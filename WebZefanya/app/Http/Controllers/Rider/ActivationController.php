<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\Rider\Rider;
use App\Services\Rider\RiderRegistrationService;
use App\Support\PasswordRule;
use App\Support\RiderValidation;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ActivationController extends Controller
{
    public function show(string $token): View
    {
        $this->findToken($token);

        return view('Rider.activate', ['token' => $token]);
    }

    public function store(Request $request, string $token, RiderRegistrationService $service): RedirectResponse
    {
        $row = $this->findToken($token);
        $data = $request->validate(['password' => PasswordRule::rules()], PasswordRule::messages());

        DB::transaction(function () use ($row, $data, $service): void {
            $rider = Rider::whereKey($row->rider_id)->lockForUpdate()->firstOrFail();
            // Refuses (403) when the rider's station was removed or deactivated.
            $service->activateLegacy($rider, $data['password']);
            DB::table('rider_activation_tokens')->where('id', $row->id)->update(['used_at' => now()]);
        });

        return redirect()->route('home', ['login' => 1])->with('auth.status_message', 'Rider account activated. Please log in.');
    }

    private function findToken(string $token): object
    {
        $row = DB::table('rider_activation_tokens')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->used_at !== null) {
            abort(403, 'This activation link is invalid or already used.');
        }
        abort_unless(Rider::whereKey($row->rider_id)->where('status', 'pending_activation')->exists(), 403);
        if (Carbon::parse($row->expires_at, RiderValidation::TIMEZONE)->lessThan(Carbon::now(RiderValidation::TIMEZONE))) {
            abort(403, 'This activation link has expired.');
        }

        return $row;
    }
}
