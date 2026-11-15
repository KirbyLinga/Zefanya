<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ManifestStatus;
use App\Enums\ParcelHandoffStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Services\Logistics\StuckTransferService;
use Illuminate\View\View;

class TransportOverviewController extends Controller
{
    public function index(StuckTransferService $transfers): View
    {
        $handoffs = ParcelHandoff::query()
            ->whereIn('status', [ParcelHandoffStatus::InTransit, ParcelHandoffStatus::Cancelled])
            ->whereNotNull('dispatched_at')->with(['sendingHub', 'receivingHub'])
            ->latest('dispatched_at')->orderByDesc('id')->paginate(20, pageName: 'handoffs_page')->withQueryString();
        $manifests = Manifest::query()
            ->whereIn('status', [ManifestStatus::Dispatched, ManifestStatus::Cancelled])
            ->whereNotNull('dispatched_at')->with(['originHub', 'destinationHub'])
            ->latest('dispatched_at')->orderByDesc('id')->paginate(20, pageName: 'batches_page')->withQueryString();

        return view('Admin.transport-overview', compact('transfers', 'handoffs', 'manifests'));
    }
}
