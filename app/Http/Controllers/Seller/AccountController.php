<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\UpdateStoreProfileRequest;
use App\Services\SellerStoreProfileService;
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
}
