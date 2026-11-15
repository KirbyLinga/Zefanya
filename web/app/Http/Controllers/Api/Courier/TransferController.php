<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Services\Logistics\CourierTransferQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only Courier views. No endpoint here changes a transfer. */
class TransferController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly CourierTransferQuery $transfers) {}

    public function profile(Request $request): JsonResponse
    {
        return $this->success('Courier profile.', $this->transfers->profile($request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->success('Assigned transfers.', $this->transfers->active($request->user()));
    }

    public function history(Request $request): JsonResponse
    {
        $page = $this->transfers->history($request->user(), max(1, min(100000, $request->integer('page', 1))));

        return $this->success('Transfer history.', [
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
