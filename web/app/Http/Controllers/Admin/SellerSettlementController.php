<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Logistics\CodRemittance;
use App\Models\Seller\SellerSettlement;
use App\Services\Seller\SellerSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SellerSettlementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(SellerSettlement::query()->orderByDesc('id')->paginate(20));
    }

    public function store(CodRemittance $remittance, SellerSettlementService $service): JsonResponse
    {
        $settlement = $service->prepare($remittance);

        return response()->json($settlement, $settlement->wasRecentlyCreated ? 201 : 200);
    }

    public function confirm(
        Request $request,
        SellerSettlement $settlement,
        SellerSettlementService $service,
    ): JsonResponse {
        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'reference' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            'reason.required' => 'Say why this Seller is paid directly instead of through a payout request.',
            'reason.min' => 'Give a reason of at least 10 characters.',
        ]);

        $paid = $service->confirmPayout(
            $settlement,
            $request->user('admin'),
            (int) $data['amount_minor'],
            $data['reference'],
        );

        OperationsAuditLog::record(
            $request->user('admin'), 'seller_direct_payout', $paid, 'Settlement #'.$paid->getKey(),
            ['status' => SellerSettlement::PENDING_PAYOUT],
            ['status' => 'Paid', 'amount' => '₱'.number_format($data['amount_minor'] / 100, 2), 'reference' => $data['reference']],
            $data['reason'],
        );

        return response()->json($paid);
    }
}
