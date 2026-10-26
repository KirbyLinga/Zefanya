<?php

namespace App\Http\Controllers\Rider;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\MarkDeliveryFailedRequest;
use App\Http\Requests\Rider\CompleteDeliveryRequest;
use App\Models\SellerOrder;
use App\Services\ParcelHandoffVerification;
use App\Services\RiderHandoffVerification;
use App\Services\RiderOperationsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Delivery leg, performed by the Rider from their own panel.
 *
 * Logistics assigns the delivery rider at sorting; the Rider starts the run,
 * submits proof of delivery and collects COD. Nothing here allows a rider to
 * self-assign a parcel or skip the station handoff.
 */
class DeliveryAssignmentsController extends Controller
{
    public function index(Request $request, RiderOperationsService $operations): View
    {
        $rider = $request->user('rider');

        return view('Rider.Deliveries.index', [
            'rider' => $rider,
            'shipments' => $operations->deliveryAssignments($rider),
        ]);
    }

    public function start(
        Request $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderHandoffVerification $handoffVerify,
    ): RedirectResponse {
        $rider = $request->user('rider');

        // Assignment ownership before any idempotent success response.
        $operations->assertDeliveryAssigned($rider, $sellerOrder);

        try {
            $method = null;
            if ($sellerOrder->fresh()?->status !== OrderStatus::OutForDelivery) {
                $payload = $handoffVerify->assertValid(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_START_DELIVERY,
                    $request->input('verification_token'),
                );
                $method = $payload['method'] ?? null;
            }

            $operations->startDelivery($rider, $sellerOrder, $method);

            if ($method !== null) {
                app(ParcelHandoffVerification::class)->consumeAfterSuccess(fn () => $handoffVerify->consume(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_START_DELIVERY,
                    $request->input('verification_token'),
                ));
            }
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Delivery started. Head to the buyer.');
    }

    public function proofForm(
        Request $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderHandoffVerification $handoffVerify,
    ): View|RedirectResponse {
        $rider = $request->user('rider');
        $courier = $operations->operationalCourier($rider);

        abort_if(
            (int) $sellerOrder->delivery_courier_id !== (int) $courier->id,
            403,
            'This delivery is not assigned to you.',
        );

        if ($sellerOrder->status === OrderStatus::Delivered || $sellerOrder->status === OrderStatus::Completed) {
            return redirect()->route('rider.deliveries.index')
                ->with('success', 'This parcel has already been delivered.');
        }

        if ($sellerOrder->status !== OrderStatus::OutForDelivery) {
            return redirect()->route('rider.deliveries.index')
                ->with('error', 'This parcel is not out for delivery.');
        }

        $token = $request->query('verification_token') ?? $request->input('verification_token');

        try {
            $handoffVerify->assertValid(
                $rider,
                $sellerOrder,
                RiderHandoffVerification::ACTION_COMPLETE_DELIVERY,
                is_string($token) ? $token : null,
            );
        } catch (ValidationException $e) {
            return redirect()->route('rider.scan.index')
                ->withErrors($e->errors())
                ->with('error', 'Scan this parcel before submitting proof of delivery.');
        }

        $sellerOrder->load(['order.buyer', 'seller', 'payment', 'orderItems.product']);

        return view('Rider.Deliveries.proof', [
            'sellerOrder' => $sellerOrder,
            'verificationToken' => $token,
        ]);
    }

    public function deliver(
        CompleteDeliveryRequest $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderHandoffVerification $handoffVerify,
    ): RedirectResponse {
        $rider = $request->user('rider');

        // Assignment ownership before any idempotent success response.
        $operations->assertDeliveryAssigned($rider, $sellerOrder);

        $token = $request->input('verification_token');
        $alreadyDone = in_array($sellerOrder->fresh()?->status, [OrderStatus::Delivered, OrderStatus::Completed], true);

        try {
            $method = null;
            if (! $alreadyDone) {
                // Assert only — do not consume before POD/COD validation succeeds.
                $payload = $handoffVerify->assertValid(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_COMPLETE_DELIVERY,
                    $token,
                );
                $method = $payload['method'] ?? null;
            }

            $operations->completeDelivery($rider, $sellerOrder, [
                'photo' => $request->file('proof_photo'),
                'received_by' => $request->input('received_by'),
                'notes' => $request->input('delivery_notes'),
                'cod_amount_minor' => $request->codAmountMinor(),
                'verification_method' => $method,
            ]);

            if (! $alreadyDone) {
                app(ParcelHandoffVerification::class)->consumeAfterSuccess(fn () => $handoffVerify->consume(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_COMPLETE_DELIVERY,
                    $token,
                ));
            }
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('rider.deliveries.index')
            ->with('success', 'Delivery recorded. Proof of delivery saved.');
    }

    public function failedForm(
        Request $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderHandoffVerification $handoffVerify,
    ): View|RedirectResponse {
        $rider = $request->user('rider');
        $courier = $operations->operationalCourier($rider);

        abort_if(
            (int) $sellerOrder->delivery_courier_id !== (int) $courier->id,
            403,
            'This delivery is not assigned to you.',
        );

        if ($sellerOrder->status === OrderStatus::DeliveryFailed) {
            return redirect()->route('rider.deliveries.index')
                ->with('warning', 'This delivery attempt was already recorded as failed.');
        }

        if ($sellerOrder->status !== OrderStatus::OutForDelivery) {
            return redirect()->route('rider.deliveries.index')
                ->with('error', 'This parcel is not out for delivery.');
        }

        $token = $request->query('verification_token') ?? $request->input('verification_token');

        try {
            $handoffVerify->assertValid(
                $rider,
                $sellerOrder,
                RiderHandoffVerification::ACTION_FAIL_DELIVERY,
                is_string($token) ? $token : null,
            );
        } catch (ValidationException $e) {
            return redirect()->route('rider.scan.index')
                ->withErrors($e->errors())
                ->with('error', 'Scan this parcel before reporting a failed delivery.');
        }

        return view('Rider.Deliveries.failed', [
            'sellerOrder' => $sellerOrder,
            'reasons' => DeliveryFailureReason::cases(),
            'verificationToken' => $token,
        ]);
    }

    public function failed(
        MarkDeliveryFailedRequest $request,
        SellerOrder $sellerOrder,
        RiderOperationsService $operations,
        RiderHandoffVerification $handoffVerify,
    ): RedirectResponse {
        $rider = $request->user('rider');

        // Assignment ownership before any idempotent success response.
        $operations->assertDeliveryAssigned($rider, $sellerOrder);

        $token = $request->input('verification_token');
        $alreadyFailed = $sellerOrder->fresh()?->status === OrderStatus::DeliveryFailed;

        try {
            $method = null;
            if (! $alreadyFailed) {
                $payload = $handoffVerify->assertValid(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_FAIL_DELIVERY,
                    $token,
                );
                $method = $payload['method'] ?? null;
            }

            $operations->failDelivery(
                $rider,
                $sellerOrder,
                DeliveryFailureReason::from((string) $request->input('failure_reason')),
                $request->input('failure_notes'),
                $method,
            );

            if (! $alreadyFailed) {
                app(ParcelHandoffVerification::class)->consumeAfterSuccess(fn () => $handoffVerify->consume(
                    $rider,
                    $sellerOrder,
                    RiderHandoffVerification::ACTION_FAIL_DELIVERY,
                    $token,
                ));
            }
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('rider.deliveries.index')
            ->with('warning', 'Delivery attempt recorded as failed. Logistics will decide the next step.');
    }
}
