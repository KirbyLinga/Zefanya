<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\ReturnChargeService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReturnController extends Controller
{
    /** Refunds, voids, fault and charge settlement decide who pays: Admin/Super Admin only. */
    private function authorizeFinancialDecision(Request $request): void
    {
        abort_unless(in_array($request->user('admin')?->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
    }

    public function index()
    {
        $returns = ParcelReturn::query()->with(['sellerOrder.originStation', 'sellerOrder.custodyHub', 'sellerOrder.seller', 'sellerOrder.manifestItems.manifest', 'sellerOrder.parcelHandoffs', 'sellerOrder.parcelHandoffExceptions', 'sellerOrder.payment', 'sellerOrder.sellerDebt', 'returnCharges.provider', 'originHub', 'originProvider', 'events'])->latest('id')->paginate(20)->withQueryString();
        $hubs = LogisticsHub::query()->operational()->with('provider')->orderBy('name')->get();

        return view('Admin.Returns.index', compact('returns', 'hubs'));
    }

    public function verifyOrigin(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['origin_hub_id' => ['required', 'integer'], 'barangay_code' => ['required', 'string', 'max:20'], 'address' => ['required', 'string', 'max:1000'], 'evidence' => ['required', 'string', 'max:1000']]);
        $service->verifyOrigin($request->user('admin'), $return, (int) $data['origin_hub_id'], $data['barangay_code'], $data['address'], $data['evidence']);

        return back()->with('success', 'Original pickup location verified. Reverse transport still requires normal authorization and QR receiving.');
    }

    public function resolve(Request $request, ParcelReturn $return, ParcelHandoffService $handoffs, ParcelReturnService $service)
    {
        $service->admin($request->user('admin'));
        $data = $request->validate(['resolution_note' => ['required', 'string', 'max:1000']]);
        foreach ($return->sellerOrder->parcelHandoffExceptions()->where('status', 'OPEN')->get() as $exception) {
            $handoffs->resolveException($exception, $request->user('admin'), $data['resolution_note']);
        }
        $return->forceFill(['exception_reason' => null])->save();

        return back()->with('success', 'Exception resolution recorded. Custody, Seller receipt and payment states were not changed.');
    }

    public function authorizeReverseRoute(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:10', 'max:1000']]);
        $service->authorizeReverseRoute($request->user('admin'), $return, $data['note']);

        return back()->with('success', 'Reverse route authorized for this parcel. The provider holding it can now request the return handoff.');
    }

    public function resolveDispute(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate([
            'outcome' => ['required', 'in:upheld,overturned'],
            'note' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'outcome.required' => 'Choose whether the rejection stands or the return goes ahead.',
            'note.required' => 'The resolution note must be 10 to 1000 characters.',
            'note.min' => 'The resolution note must be 10 to 1000 characters.',
            'note.max' => 'The resolution note must be 10 to 1000 characters.',
        ]);
        $service->resolveDispute($return, $request->user('admin'), $data['outcome'], $data['note']);

        return back()->with('success', $data['outcome'] === 'upheld'
            ? 'The seller’s rejection stands. The dispute is closed.'
            : 'The return goes ahead. The seller’s rejection was overturned.');
    }

    public function setFault(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeFinancialDecision($request);
        $data = $request->validate([
            'fault_party' => ['required', 'in:'.ParcelReturn::FAULT_SELLER.','.ParcelReturn::FAULT_LOGISTICS],
            'fault_note' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'fault_party.required' => 'Choose who pays: the Seller or Logistics.',
            'fault_party.in' => 'Choose who pays: the Seller or Logistics.',
            'fault_note.required' => 'Explain the decision in 10 to 1000 characters.',
            'fault_note.min' => 'Explain the decision in 10 to 1000 characters.',
            'fault_note.max' => 'Explain the decision in 10 to 1000 characters.',
        ]);
        $service->setFault($return, $request->user('admin'), $data['fault_party'], $data['fault_note']);

        return back()->with('success', $data['fault_party'] === ParcelReturn::FAULT_LOGISTICS
            ? 'Saved: Logistics pays for this return. The Seller is not charged.'
            : 'Saved: the Seller pays for this return.');
    }

    public function recordRefund(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeFinancialDecision($request);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', 'regex:/\A\d{1,9}(?:\.\d{1,2})?\z/'],
            'recipient' => ['required', 'string', 'max:255'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'refund_reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'reference.required' => 'A refund reference of at most 255 characters is required.',
            'amount.required' => 'Enter the amount that was refunded.',
            'amount.regex' => 'Enter a peso amount with at most two decimal places.',
            'recipient.required' => 'Say who received the refund.',
            'paid_on.before_or_equal' => 'The refund date cannot be in the future.',
        ]);

        // Pesos to centavos using integers, as on the other money forms.
        [$pesos, $centavos] = array_pad(explode('.', $data['amount'], 2), 2, '0');
        $proofPath = $request->file('proof')?->store('refund-proofs/'.$return->id, 'local');

        try {
            $service->recordRefund($return, $request->user('admin'), $data['reference'], ((int) $pesos) * 100 + (int) str_pad($centavos, 2, '0'), $data['recipient'], $data['paid_on'], $proofPath, $data['refund_reason'] ?? null);
        } catch (\Throwable $exception) {
            // Files are not transactional: remove the upload if the record was not saved.
            if ($proofPath !== null) {
                Storage::disk('local')->delete($proofPath);
            }

            throw $exception;
        }

        return back()->with('success', 'Refund recorded. The charges for this return have been updated.');
    }

    public function voidRefund(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $this->authorizeFinancialDecision($request);
        $data = $request->validate(['void_reason' => ['required', 'string', 'min:10', 'max:1000']], [
            'void_reason.required' => 'Explain why the refund failed in 10 to 1000 characters.',
            'void_reason.min' => 'Explain why the refund failed in 10 to 1000 characters.',
            'void_reason.max' => 'Explain why the refund failed in 10 to 1000 characters.',
        ]);
        $service->voidRefund($return, $request->user('admin'), $data['void_reason']);

        return back()->with('success', 'Refund voided. It stays in the history; record the new refund once it is paid.');
    }

    public function settleCharge(Request $request, LogisticsReturnCharge $charge, ReturnChargeService $service)
    {
        // Money moves, so moderators can see charges but not settle them.
        $this->authorizeFinancialDecision($request);
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']], [
            'reference.required' => 'Enter the payment reference, at most 100 characters.',
            'reference.max' => 'Enter the payment reference, at most 100 characters.',
        ]);
        $service->settle($charge, $request->user('admin'), $data['reference']);

        return back()->with('success', $charge->owedToZefanya()
            ? 'Recorded: the Logistics company repaid this charge.'
            : 'Recorded: Zefanya paid the Logistics company for this charge.');
    }

    public function refundProof(ParcelReturn $return): StreamedResponse
    {
        abort_unless($return->refund_proof_path !== null && Storage::disk('local')->exists($return->refund_proof_path), 404);

        return Storage::disk('local')->response($return->refund_proof_path);
    }

    public function close(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['close_reason' => ['required', 'string', 'min:10', 'max:1000']], [
            'close_reason.required' => 'Explain why this return is closed, in 10 to 1000 characters.',
            'close_reason.min' => 'Explain why this return is closed, in 10 to 1000 characters.',
            'close_reason.max' => 'Explain why this return is closed, in 10 to 1000 characters.',
        ]);
        $service->closeByAdmin($return, $request->user('admin'), $data['close_reason']);

        return back()->with('success', $return->fresh()->isCancelled()
            ? 'Return closed before collection. The buyer keeps the item and the Seller\'s held earnings are restored.'
            : 'Return closed. Custody, refund and who pays are unchanged; record them separately if needed.');
    }

    public function authorizeCollectionRetry(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['retry_note' => ['required', 'string', 'min:10', 'max:1000']], [
            'retry_note.required' => 'Explain why another collection is authorized, in 10 to 1000 characters.',
            'retry_note.min' => 'Explain why another collection is authorized, in 10 to 1000 characters.',
            'retry_note.max' => 'Explain why another collection is authorized, in 10 to 1000 characters.',
        ]);
        $service->authorizeCollectionRetry($return, $request->user('admin'), $data['retry_note']);

        return back()->with('success', 'One extra buyer collection authorized. No custody, refund or fault decision changed.');
    }

    public function recordDisposition(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate([
            'disposition' => ['required', 'in:'.implode(',', array_keys(ParcelReturn::DISPOSITIONS))],
            'disposition_note' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'disposition.required' => 'Choose what physically happened to this parcel.',
            'disposition.in' => 'Choose what physically happened to this parcel.',
            'disposition_note.required' => 'Describe the physical disposition in 10 to 1000 characters.',
            'disposition_note.min' => 'Describe the physical disposition in 10 to 1000 characters.',
            'disposition_note.max' => 'Describe the physical disposition in 10 to 1000 characters.',
        ]);
        $service->recordDisposition($return, $request->user('admin'), $data['disposition'], $data['disposition_note']);

        return back()->with('success', 'Physical disposition recorded. Custody, refund and stock were not changed.');
    }

    public function resumeHandover(Request $request, ParcelReturn $return, ParcelReturnService $service)
    {
        $data = $request->validate(['resume_note' => ['required', 'string', 'min:10', 'max:1000']], [
            'resume_note.required' => 'Explain the decision in 10 to 1000 characters.',
            'resume_note.min' => 'Explain the decision in 10 to 1000 characters.',
            'resume_note.max' => 'Explain the decision in 10 to 1000 characters.',
        ]);
        $service->resumeHandoverAfterDispute($return, $request->user('admin'), $data['resume_note']);

        return back()->with('success', 'Sent back to handover. Logistics must hand the parcel to the Seller with a new verified code.');
    }

    public function receiptDisputePhoto(ParcelReturn $return, int $index): StreamedResponse
    {
        $path = $return->receipt_dispute_photos[$index] ?? null;
        abort_unless($path !== null && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    public function stock(Request $request, ParcelReturn $return, SellerOrderStatusService $status)
    {
        try {
            $status->releaseVerifiedReturnStock($return->sellerOrder, $request->user('admin'));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['stock' => $exception->getMessage()]);
        }

        return back()->with('success', 'Verified resellable goods restored once with stock-ledger entries.');
    }
}
