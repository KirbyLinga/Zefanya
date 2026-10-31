<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\StoreRiderRequest;
use App\Models\Logistics\LogisticsHub;
use App\Models\Rider\Rider;
use App\Notifications\Rider\RiderActivation;
use App\Services\Rider\RiderRegistrationService;
use App\Support\RiderValidation;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RiderController extends Controller
{
    public function index(): View
    {
        $provider = Auth::guard('logistics')->user();
        $riders = Rider::query()->where('logistics_provider_id', $provider->getKey())
            ->with('station')->latest()->get();
        $applications = $riders->where('status', Rider::STATUS_PENDING)->values();

        return view('Logistics.Riders.index', [
            'riders' => $riders->where('status', '!=', Rider::STATUS_PENDING)->values(),
            'applications' => $applications,
            'stations' => $this->stationOptions($provider->getKey()),
            'provider' => $provider,
        ]);
    }

    public function create(): View
    {
        $provider = Auth::guard('logistics')->user();

        return view('Logistics.Riders.create', [
            'stations' => $this->stationOptions($provider->getKey()),
        ]);
    }

    public function show(Rider $rider): View
    {
        $this->scope($rider);

        return view('Logistics.Riders.show', [
            'rider' => $rider->load('station', 'courier'),
            'stations' => $this->stationOptions((int) $rider->logistics_provider_id),
        ]);
    }

    public function store(StoreRiderRequest $request): RedirectResponse
    {
        $provider = Auth::guard('logistics')->user();
        $data = $request->validated();

        try {
            DB::transaction(function () use ($provider, $data, $request) {
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
                    'address_mode' => $data['address_mode'] ?? null,
                    'province_code' => $data['province'] ?? null,
                    'province_name' => $data['province_name'] ?? null,
                    'municipality_code' => $data['municipality'] ?? null,
                    'municipality_name' => $data['municipality_name'] ?? null,
                    'barangay_code' => $data['barangay'] ?? null,
                    'barangay_name' => $data['barangay_name'] ?? null,
                    'street' => $data['street'] ?? null,
                    'house_number' => $data['house_number'] ?? null,
                    'address_detail' => $data['address_detail'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                ]);
                $row->forceFill([
                    'logistics_provider_id' => $provider->getKey(),
                    'station_hub_id' => $data['station_hub_id'],
                    'status' => Rider::STATUS_PENDING_ACTIVATION,
                    'status_changed_at' => now(),
                ])->save();
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

    public function approve(Request $request, Rider $rider, RiderRegistrationService $service): RedirectResponse
    {
        $this->scope($rider);
        $data = $request->validate([
            'station_hub_id' => ['required', 'integer'],
        ]);

        $service->decide($rider, Auth::guard('logistics')->user(), Rider::STATUS_APPROVED, null, (int) $data['station_hub_id']);

        return redirect()->route('logistics.riders.show', $rider)->with('success', 'Rider application approved and assigned to the Station Hub.');
    }

    public function reject(Request $request, Rider $rider, RiderRegistrationService $service): RedirectResponse
    {
        $this->scope($rider);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ]);

        $service->decide($rider, Auth::guard('logistics')->user(), Rider::STATUS_REJECTED, $data['reason']);

        return redirect()->route('logistics.riders.index')->with('success', 'Rider application rejected.');
    }

    public function suspend(Request $request, Rider $rider, RiderRegistrationService $service): RedirectResponse
    {
        $this->scope($rider);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:300']]);
        $service->suspend($rider, Auth::guard('logistics')->user(), $data['reason']);

        return redirect()->route('logistics.riders.show', $rider)->with('success', 'Rider suspended. Affected parcels are flagged for reassignment.');
    }

    public function deactivate(Request $request, Rider $rider, RiderRegistrationService $service): RedirectResponse
    {
        $this->scope($rider);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $service->deactivate($rider, Auth::guard('logistics')->user(), $data['reason'] ?? null);

        return redirect()->route('logistics.riders.show', $rider)->with('success', 'Rider deactivated. Affected parcels are flagged for reassignment.');
    }

    public function reactivate(Rider $rider, RiderRegistrationService $service): RedirectResponse
    {
        $this->scope($rider);
        $service->reactivate($rider, Auth::guard('logistics')->user());

        return redirect()->route('logistics.riders.show', $rider)->with('success', 'Rider reactivated.');
    }

    public function changeStation(Request $request, Rider $rider, RiderRegistrationService $service): RedirectResponse
    {
        $this->scope($rider);
        $data = $request->validate([
            'station_hub_id' => ['required', 'integer'],
        ]);
        $service->changeStation($rider, Auth::guard('logistics')->user(), (int) $data['station_hub_id']);

        return redirect()->route('logistics.riders.show', $rider)->with('success', 'Rider station updated.');
    }

    public function resend(Rider $rider): RedirectResponse
    {
        $this->scope($rider);
        abort_unless($rider->status === Rider::STATUS_PENDING_ACTIVATION, 403, 'This registration cannot use activation links.');
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

    private function stationOptions(int $providerId)
    {
        return LogisticsHub::query()
            ->where('logistics_provider_id', $providerId)
            ->where('hub_type', LogisticsHub::STATION)
            ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
            ->where('is_active', true)
            ->orderBy('province_name')
            ->orderBy('municipality')
            ->get();
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
