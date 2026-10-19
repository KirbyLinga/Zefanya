<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreRiderRequest;
use App\Models\Rider;
use App\Notifications\Rider\RiderActivation;
use App\Support\RiderValidation;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function index(): View
    {
        $provider = Auth::guard('logistics')->user();
        $riders = Rider::query()->where('logistics_provider_id', $provider->getKey())->latest()->get();

        return view('Logistics.Riders.index', ['riders' => $riders, 'provider' => $provider]);
    }

    public function create(): View
    {
        return view('Logistics.Riders.create');
    }

    public function show(Rider $rider): View
    {
        $this->scope($rider);

        return view('Logistics.Riders.show', ['rider' => $rider]);
    }

    public function store(StoreRiderRequest $request): RedirectResponse
    {
        $provider = Auth::guard('logistics')->user();
        $data = $request->validated();

        try {
            $rider = DB::transaction(function () use ($provider, $data, $request) {
                $licensePath = $request->file('drivers_license')->store('rider-licenses', 'local');
                $orcrPath = $request->file('or_cr')->store('rider-or-crs', 'local');
                $row = new Rider([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'birthday' => $data['birthday'],
                    'email' => $data['email'],
                    'contact_no' => $data['contact_no'],
                    'license_no' => $data['license_no'],
                    'license_expiry' => $data['license_expiry'],
                    'vehicle_type' => $data['vehicle_type'],
                    'plate_number' => $data['plate_number'],
                    'drivers_license_path' => $licensePath,
                    'or_cr_path' => $orcrPath,
                ]);
                $row->logistics_provider_id = $provider->getKey();
                $row->status = 'pending_activation';
                $row->save();
                $this->issueToken($row);

                return $row;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Race (double submit slipping past validation): map the DB
            // constraint back to a friendly field message instead of a 500.
            $msg = $e->getMessage();
            $field = str_contains($msg, 'riders.contact_no') ? 'contact_no'
                : (str_contains($msg, 'riders.license_no') ? 'license_no'
                : (str_contains($msg, 'riders.plate_number') ? 'plate_number' : 'email'));

            return redirect()->back()->withErrors([$field => match ($field) {
                'contact_no' => 'This mobile number is already in use.',
                'license_no' => 'This license number is already registered.',
                'plate_number' => 'This plate number is already registered.',
                default => 'This email is already in use.',
            }])->withInput($request->except(['drivers_license', 'or_cr']));
        }

        return redirect()->route('logistics.riders.index')->with('success', 'Rider created. Activation email sent.');
    }

    public function resend(Rider $rider): RedirectResponse
    {
        $this->scope($rider);
        DB::table('rider_activation_tokens')->where('rider_id', $rider->getKey())->delete();
        $this->issueToken($rider->fresh());

        return redirect()->route('logistics.riders.index')->with('success', 'Activation email resent.');
    }

    private function scope(Rider $rider): void
    {
        $provider = Auth::guard('logistics')->user();
        if ((int) $rider->logistics_provider_id !== (int) $provider->getKey()) {
            abort(403);
        }
    }

    private function issueToken(Rider $rider): string
    {
        $raw = bin2hex(random_bytes(32));
        DB::table('rider_activation_tokens')->insert([
            'rider_id' => $rider->getKey(),
            'token_hash' => hash('sha256', $raw),
            'expires_at' => Carbon::now(RiderValidation::TIMEZONE)->addHours(24),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rider->notify(new RiderActivation(route('rider.activate.show', $raw)));

        return $raw;
    }
}
