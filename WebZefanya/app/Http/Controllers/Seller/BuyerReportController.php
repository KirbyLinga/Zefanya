<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Shared\BuyerConductReport;
use App\Models\Shared\SellerOrder;
use App\Services\Admin\BuyerConductReportService;
use App\Support\ImageUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BuyerReportController extends Controller
{
    public function store(Request $request, SellerOrder $sellerOrder, BuyerConductReportService $service): RedirectResponse
    {
        $seller = $request->user('seller');
        $this->authorizeForUser($seller, 'view', $sellerOrder);
        $data = $request->validate([
            'reason' => ['required', Rule::in(array_keys(BuyerConductReport::REASONS))],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'evidence' => ['nullable', 'array', 'max:3'],
            'evidence.*' => ['file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', ...ImageUpload::dimensionRules()],
        ]);
        $service->submit($sellerOrder, $seller, $data['reason'], $data['description'], $request->file('evidence', []));

        return back()->with('success', 'Your report is with Admin for review. An existing unresolved report is kept without creating a duplicate. No penalty has been applied.');
    }
}
