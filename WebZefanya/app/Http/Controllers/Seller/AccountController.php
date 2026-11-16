<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UpdateStoreProfileRequest;
use App\Services\Seller\SellerStoreProfileService;
use App\Services\Seller\SellerVacationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('Seller.Account.index', ['seller' => $request->user('seller')]);
    }

    public function update(UpdateStoreProfileRequest $request, SellerStoreProfileService $profiles): RedirectResponse
    {
        $profiles->update($request->user('seller'), $request->validated());

        return to_route('seller.account.index')->with('success', 'Store profile updated.');
    }

    public function vacation(Request $request, SellerVacationService $vacation): RedirectResponse
    {
        $seller = $request->user('seller');
        foreach (['seller_id', 'store_id', 'id'] as $key) {
            abort_if($request->has($key) && (! is_scalar($request->input($key)) || (string) $request->input($key) !== (string) $seller->id), 403);
        }
        $data = $request->validate(['is_on_vacation' => ['required', 'boolean']]);
        $vacation->set($seller, (bool) $data['is_on_vacation']);

        return to_route('seller.account.index')->with('success', $data['is_on_vacation'] ? 'Vacation mode enabled.' : 'Vacation mode disabled.');
    }
}
