<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class GenerateCourierDummyHash extends Command
{
    protected $signature = 'courier:dummy-hash';

    protected $description = 'Print a precomputed Courier dummy verifier for the current hashing settings (writes nothing)';

    public function handle(): int
    {
        // The random plaintext is discarded. Single quotes preserve dollar
        // signs when the operator installs this line in the environment file.
        $hash = Hash::make(bin2hex(random_bytes(32)));
        $this->line("COURIER_DUMMY_HASH='".$hash."'");

        return self::SUCCESS;
    }
}
