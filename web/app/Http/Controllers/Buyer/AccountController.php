<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    /**
     * Order status buckets (same as OrderController, for consistent counts).
     */
    private const TO_SHIP_STATUSES = [
        OrderStatus::Placed,
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::ReadyForPickup,
    ];

    private const TO_RECEIVE_STATUSES = [
        OrderStatus::AssignedToRider,
        OrderStatus::PickedUp,
        OrderStatus::AtSortingCenter,
        OrderStatus::Sorted,
        OrderStatus::OutForDelivery,
        OrderStatus::Delivered,
    ];

    private const CANCELLED_STATUSES = [
        OrderStatus::DeliveryFailed,
        OrderStatus::Returned,
    ];

    /**
     * Calculate order counts for the sidebar (all account pages).
     */
    private function getOrderCounts(Buyer $buyer): array
    {
        $allOrdersForCounts = Order::query()
            ->where('buyer_id', $buyer->id)
            ->with('sellerOrders:id,order_id,status')
            ->get(['id']);

        $counts = [
            'all' => $allOrdersForCounts->count(),
            'to_ship' => 0,
            'to_receive' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        foreach ($allOrdersForCounts as $o) {
            $statuses = $o->sellerOrders->pluck('status');
            if ($statuses->contains(fn ($s) => $s === OrderStatus::Completed)) {
                $counts['completed']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, self::TO_RECEIVE_STATUSES, true))) {
                $counts['to_receive']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, self::TO_SHIP_STATUSES, true))) {
                $counts['to_ship']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, self::CANCELLED_STATUSES, true))) {
                $counts['cancelled']++;
            }
        }

        return $counts;
    }

    /**
     * Profile-field rules, copied from StoreBuyerRegistrationRequest for the
     * subset the profile tab owns. Address and password have their own tabs /
     * follow-up tasks and are deliberately not validated here.
     *
     * @return array<string, array<int, string>>
     */
    private function profileRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'birthday' => ['required', 'date', 'before:today'],
        ];
    }

    /**
     * Address-rules, copied from StoreBuyerRegistrationRequest for the subset
     * the address tab owns. Validated identically so manual/API modes behave
     * the same as registration.
     *
     * @return array<string, array<int, string>>
     */
    private function addressRules(Request $request): array
    {
        $isManual = $request->input('address_mode') === 'manual';

        return [
            'address_mode' => ['required', Rule::in(['api', 'manual'])],
            'province' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'province_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Profile tab — the account landing page ("My Account" in the navbar).
     * The address / password tabs are separate follow-ups.
     */
    public function index(Request $request): View
    {
        $buyer = $request->user('buyer');

        return view('Buyer.Account.profile', [
            'buyer' => $buyer,
            'counts' => $this->getOrderCounts($buyer),
        ]);
    }

    /**
     * Persist the profile tab. Email is intentionally not editable here
     * (it is the login identity and is verified at registration).
     */
    public function update(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        $data = $request->validate($this->profileRules());

        // `age` is a real column (registration derives it from birthday too).
        $buyer->update($data + [
            'age' => Carbon::parse($data['birthday'])->age,
        ]);

        return redirect()
            ->route('buyer.account.index')
            ->with('success', 'Profile updated.');
    }

    /**
     * Password tab.
     */
    public function passwordEdit(Request $request): View
    {
        $buyer = $request->user('buyer');

        return view('Buyer.Account.password', [
            'buyer' => $buyer,
            'counts' => $this->getOrderCounts($buyer),
        ]);
    }

    /**
     * Persist the password tab.
     *
     * @throws ValidationException
     */
    public function passwordUpdate(Request $request): RedirectResponse
    {
        $buyer = $request->user('buyer');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
                Password::defaults(),
            ],
        ], [
            // user-facing rules only (the field-error-block style used by the
            // form is handled by the blade view, so keep these plain strings).
            'current_password.required' => 'Enter your current password.',
            'new_password.required' => 'Enter a new password.',
            'new_password.min' => 'The new password must be at least 8 characters.',
            'new_password.confirmed' => 'The new password confirmation does not match.',
        ]);

        // Reject outright if the current password doesn't match — never
        // silently no-op.
        if (! Hash::check($data['current_password'], $buyer->password)) {
            $request->session()->flash('error', 'That doesn\'t match your current password.');

            return back()
                ->withInput()
                ->withErrors(['current_password' => 'That doesn\'t match your current password.']);
        }

        $buyer->update([
            'password' => Hash::make($data['new_password']),
        ]);

        return redirect()
            ->route('buyer.account.index')
            ->with('success', 'Password updated.');
    }

    /**
     * Address tab — the saved delivery address (PSGC codes + names, or the
     * free-text manual fallback), the same data checkout snapshots.
     */
    public function addressEdit(Request $request): View
    {
        $buyer = $request->user('buyer');

        return view('Buyer.Account.address', [
            'buyer' => $buyer,
            'counts' => $this->getOrderCounts($buyer),
        ]);
    }

    /**
     * Persist the address tab. The field mapping is identical to registration
     * (RegisterBuyerController@store): the request carries `province` /
     * `municipality` / `barangay` CODES plus their `*_name` pairs, stored in
     * the `*_code` / `*_name` columns. The account address lookup mirrors the
     * manual inputs into the same hidden *_name fields, so both modes reach
     * this method with the same contract.
     */
    public function addressUpdate(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        $data = $request->validate($this->addressRules($request));

        $buyer->update([
            'address_mode' => $data['address_mode'],
            'province_code' => $data['province'] ?? null,
            'province_name' => $data['province_name'] ?? null,
            'municipality_code' => $data['municipality'] ?? null,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_code' => $data['barangay'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
            'street' => $data['street'],
            'house_number' => $data['house_number'] ?? null,
            'address_detail' => $data['address_detail'] ?? null,
        ]);

        return redirect()
            ->route('buyer.account.address')
            ->with('success', 'Address updated.');
    }
}
