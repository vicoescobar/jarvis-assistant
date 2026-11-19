<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SeedOwnerCommand extends Command
{
    protected $signature = 'jarvis:seed-owner';

    protected $description = 'Idempotently create or update the Jarvis owner user from environment variables';

    public function handle(): int
    {
        $email = (string) config('jarvis.owner_email', '');
        $password = (string) config('jarvis.owner_password', '');

        if ($email === '' || $password === '') {
            $this->warn('JARVIS_OWNER_EMAIL or JARVIS_OWNER_PASSWORD is empty. Skipping owner seed.');

            return self::SUCCESS;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Jarvis Owner',
                'password' => $password,
            ]
        );

        $this->info("Owner account [{$email}] synchronized.");

        return self::SUCCESS;
    }
}
