<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\LogisticsHub;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('linehaul:provision {--provider=} {--hub=} {--email=} {--name=}')]
#[Description('Explicitly provision one local-demo Courier account; never changes an existing account')]
class ProvisionLinehaulDriver extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Courier demo provisioning is available only in local/testing environments.');

            return self::FAILURE;
        }
        $hub = LogisticsHub::query()->find($this->option('hub'));
        if ($hub === null || (int) $hub->logistics_provider_id !== (int) $this->option('provider')
            || ! $hub->isOperational() || ! $hub->provider?->isApproved()) {
            $this->error('Choose an operational hub owned by an approved provider.');

            return self::FAILURE;
        }
        $email = strtolower(trim((string) $this->option('email')));
        foreach (['linehaul_drivers', 'buyers', 'riders', 'sellers', 'logistics_providers', 'admins'] as $table) {
            if (\Illuminate\Support\Facades\DB::table($table)->where('email', $email)->exists()) {
                $this->error('That email is already registered. No account was changed.');

                return self::FAILURE;
            }
        }
        $password = $this->secret('Courier password');
        $data = Validator::make(['name' => $this->option('name'), 'email' => $email, 'password' => $password], [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
        ])->validate();
        $driver = new LinehaulDriver;
        $driver->forceFill([...$data, 'logistics_provider_id' => $hub->logistics_provider_id,
            'home_hub_id' => $hub->id, 'status' => 'active', 'email_verified_at' => now()])->save();
        $this->info('Courier demo account created. Use Courier login in the live app.');

        return self::SUCCESS;
    }
}
