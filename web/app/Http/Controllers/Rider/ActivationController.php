<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Support\RiderValidation;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ActivationController extends Controller
{
    public function show(string $token): View
    {
        $this->findToken($token);

        return view('Rider.activate', ['token' => $token]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $row = $this->findToken($token);
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#]).+$/'],
        ], [
            'password.regex' => 'Password must include uppercase, lowercase, number, and special character.',
            'password.confirmed' => 'Passwords do not match.',
        ]);

        DB::transaction(function () use ($row, $data): void {
            DB::table('riders')->where('id', $row->rider_id)->update([
                'password' => Hash::make($data['password']),
                'status' => 'active',
                'updated_at' => now(),
            ]);
            DB::table('rider_activation_tokens')->where('id', $row->id)->update(['used_at' => now()]);
        });

        return redirect()->route('login')->with('auth.status_message', 'Rider account activated. Please log in.');
    }

    private function findToken(string $token): object
    {
        $row = DB::table('rider_activation_tokens')->where('token_hash', hash('sha256', $token))->first();
        if (! $row || $row->used_at !== null) {
            abort(403, 'This activation link is invalid or already used.');
        }
        if (Carbon::parse($row->expires_at, RiderValidation::TIMEZONE)->lessThan(Carbon::now(RiderValidation::TIMEZONE))) {
            abort(403, 'This activation link has expired.');
        }

        return $row;
    }
}
