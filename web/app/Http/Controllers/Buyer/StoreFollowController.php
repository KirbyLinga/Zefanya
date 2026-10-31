<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use App\Models\Buyer\StoreFollow;
use App\Models\Seller\Seller;
use App\Services\Buyer\StoreFollowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreFollowController extends Controller
{
    public function index(Request $request): View
    {
        $follows = StoreFollow::query()->where('buyer_id', $request->user('buyer')->id)
            ->whereHas('seller', fn ($query) => $query->where('status', 'approved')->whereNull('archived_at'))
            ->with('seller')->latest('id')->paginate(12);

        return view('Buyer.Following.index', compact('follows'));
    }

    public function toggle(Request $request, Seller $seller, StoreFollowService $service): JsonResponse|RedirectResponse
    {
        $following = $service->toggle($request->user('buyer'), $seller);
        if ($request->expectsJson()) {
            return response()->json([
                'following' => $following,
                'followers_count' => StoreFollow::query()->where('seller_id', $seller->id)->count(),
            ]);
        }

        return back()->with('success', $following ? 'Store followed.' : 'Store unfollowed.');
    }
}
