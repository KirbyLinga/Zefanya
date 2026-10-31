<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Shared\Order;
use App\Services\Buyer\BuyerAddressBook;
use App\Support\BirthDate;
use App\Support\PersonName;
use App\Support\PhilippineMobile;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
            if ($o->computeRollupStatus() === OrderStatus::Completed) {
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
     * @return array<string, array<int, mixed>>
     */
    private function profileRules(Buyer $buyer): array
    {
        return [
            'first_name' => PersonName::rules(),
            'last_name' => PersonName::rules(),
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'contact_no' => [
                ...PhilippineMobile::rules(),
                // Saving your own unchanged number must not count as a duplicate.
                function (string $attribute, mixed $value, Closure $fail) use ($buyer): void {
                    if ($value !== $buyer->contact_no && PhilippineMobile::isTaken($value, 'buyer')) {
                        $fail(PhilippineMobile::TAKEN_MESSAGE);
                    }
                },
            ],
            'birthday' => BirthDate::rules(),
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
            // Required in BOTH modes — see StoreBuyerRegistrationRequest: a
            // missing municipality breaks service-area matching at fulfillment.
            'municipality_name' => ['required', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
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
     * Persist the profile tab. Email is not editable here: it is the login
     * identity, so changing it needs a code (see AccountEmailController).
     */
    public function update(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');

        if (is_string($request->input('contact_no'))) {
            $request->merge(['contact_no' => PhilippineMobile::normalize($request->input('contact_no'))]);
        }

        $data = $request->validate($this->profileRules($buyer), [
            ...PersonName::messages('first_name', 'First name'),
            ...PersonName::messages('last_name', 'Last name'),
            ...BirthDate::messages(),
        ]);

        try {
            // `age` is a real column (registration derives it from birthday too).
            $buyer->update($data + [
                'age' => Carbon::parse($data['birthday'])->age,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another buyer took the number between validation and saving.
            return back()->withInput()->withErrors(['contact_no' => PhilippineMobile::TAKEN_MESSAGE]);
        }

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
     * Address tab — the saved delivery address (PSGC codes + names, or the
     * free-text manual fallback), the same data checkout snapshots.
     */
    public function addressEdit(Request $request): View
    {
        $buyer = $request->user('buyer');
        $addresses = $buyer->addresses()->orderByDesc('is_default')->orderBy('id')->get();
        $editing = $request->filled('edit')
            ? $addresses->firstWhere('id', $request->integer('edit')) ?? abort(404)
            : null;

        return view('Buyer.Account.address', [
            'buyer' => $buyer,
            'addresses' => $addresses,
            'editing' => $editing,
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

        app(BuyerAddressBook::class)->saveDefault($buyer, $data);

        return redirect()
            ->route('buyer.account.address')
            ->with('success', 'Address updated.');
    }

    public function addressStore(Request $request): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        $data = $request->validate($this->addressRules($request));

        app(BuyerAddressBook::class)->save($buyer, $data, null, $request->boolean('is_default'));

        return redirect()
            ->route('buyer.account.address')
            ->with('success', 'Address saved.');
    }

    public function addressRecordUpdate(Request $request, BuyerAddress $address): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        abort_unless((int) $address->buyer_id === (int) $buyer->id, 404);
        $data = $request->validate($this->addressRules($request));

        app(BuyerAddressBook::class)->save(
            $buyer,
            $data,
            $address,
            $request->boolean('is_default') || $address->is_default,
        );

        return redirect()
            ->route('buyer.account.address')
            ->with('success', 'Address updated.');
    }

    public function addressMakeDefault(Request $request, BuyerAddress $address): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        abort_unless((int) $address->buyer_id === (int) $buyer->id, 404);

        app(BuyerAddressBook::class)->makeDefault($buyer, $address);

        return redirect()
            ->route('buyer.account.address')
            ->with('success', 'Default address updated.');
    }

    public function addressDestroy(Request $request, BuyerAddress $address): RedirectResponse
    {
        /** @var Buyer $buyer */
        $buyer = $request->user('buyer');
        abort_unless((int) $address->buyer_id === (int) $buyer->id, 404);

        app(BuyerAddressBook::class)->delete($buyer, $address);

        return redirect()
            ->route('buyer.account.address')
            ->with('success', 'Address deleted.');
    }
}
