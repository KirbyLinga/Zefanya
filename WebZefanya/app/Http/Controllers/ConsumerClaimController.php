<?php

namespace App\Http\Controllers;

use App\Models\Buyer\ConsumerClaim;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\ConsumerClaimService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ConsumerClaimController extends Controller
{
    private function context(Request $request): array
    {
        $role = explode('.', $request->route()->getName())[0];
        abort_unless(in_array($role, ['buyer', 'seller', 'admin'], true), 403);
        $actor = $request->user($role);
        abort_if($actor === null, 403);

        return ['role' => $role, 'actor' => $actor,
            'layout' => match ($role) {
                'buyer' => 'Buyer.Layouts.app', 'seller' => 'Layouts.seller', default => 'Admin.Layouts.app'
            }];
    }

    public function index(Request $request)
    {
        $context = $this->context($request);
        $query = ConsumerClaim::query();
        if ($context['role'] !== 'admin') {
            $query->where($context['role'].'_id', $context['actor']->id);
        }

        return response()->view('ConsumerClaims.index', $context + ['claims' => $query->orderByDesc('updated_at')->paginate(20)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, SellerOrder $shipment, ConsumerClaimService $service)
    {
        abort_unless((int) $shipment->order->buyer_id === (int) $request->user('buyer')->id, 403);
        abort_unless($service->eligible($shipment), 422, 'A recorded delivery is needed for this pathway.');

        return response()->view('ConsumerClaims.create', $this->context($request) + ['shipment' => $shipment->load('orderItems')])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, SellerOrder $shipment, ConsumerClaimService $service)
    {
        $claim = $service->submit($request->user('buyer'), $shipment, $request->all());

        return to_route('buyer.consumer-claims.show', $claim->id)->with('success', $claim->wasRecentlyCreated
            ? 'Your claim was submitted for manual review.' : 'Your existing case is shown. Add information there when the case is open.');
    }

    public function show(Request $request, int $claimId, ConsumerClaimService $service)
    {
        $context = $this->context($request);
        $claim = ConsumerClaim::query()->findOrFail($claimId);
        $service->authorize($claim, $context['actor'], $context['role']);
        $claim->load(['updates', 'shipment.statusHistories']);

        return response()->view('ConsumerClaims.show', $context + ['claim' => $claim])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $claimId, ConsumerClaimService $service)
    {
        $context = $this->context($request);
        $claim = ConsumerClaim::query()->findOrFail($claimId);
        $service->update($claim, $context['actor'], $context['role'], $request->all());

        return to_route($context['role'].'.consumer-claims.show', $claimId)->with('success', 'Case update recorded.');
    }

    public function evidence(Request $request, int $claimId, int $updateId, int $attachment, ConsumerClaimService $service)
    {
        $context = $this->context($request);
        $claim = ConsumerClaim::query()->findOrFail($claimId);
        $service->authorize($claim, $context['actor'], $context['role']);
        $update = $claim->updates()->findOrFail($updateId);
        $file = $update->evidence[$attachment] ?? null;
        abort_unless(is_array($file) && str_starts_with($file['path'] ?? '', 'consumer-claims/'.$claimId.'/')
            && ! str_contains($file['path'], '..') && Storage::disk('local')->exists($file['path']), 404);

        return Storage::disk('local')->download($file['path'], $file['name'], ['X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }
}
