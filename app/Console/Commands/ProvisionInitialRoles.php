<?php

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:provision-initial-roles')]
#[Description('Create the single admin IT account for the platform')]
class ProvisionInitialRoles extends Command
{
    public function handle(): int
    {
        $accounts = config('initial_role_accounts');
        $existingEmails = User::query()
            ->whereIn('email', array_column($accounts, 'email'))
            ->pluck('email')
            ->all();

        if ($existingEmails !== []) {
            $this->error('No account was created. The reserved administrator email is already in use: '.implode(', ', $existingEmails));

            return self::FAILURE;
        }

        $password = $this->secret('Password for the admin IT account');
        $confirmation = $this->secret('Confirm the password');

        if (! is_string($password) || strlen($password) < 8 || $password !== $confirmation) {
            $this->error('The password must contain at least 8 characters and both entries must match.');

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            User::create([
                ...$account,
                'password' => $password,
                'profile_status' => 'active',
            ]);
        }

        $this->info('Created the active administrator IT account for the platform.');
        $this->table(
            ['Role', 'Email', 'Scope'],
            array_map(
                fn (array $account): array => [
                    $account['name'],
                    $account['email'],
                    'Platform administration',
                ],
                $accounts,
            ),
        );

        return self::SUCCESS;
    }
}
