<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:create-administrator')]
#[Description('Create the first active administrator account')]
class CreateAdministrator extends Command
{
    public function handle(): int
    {
        $name = $this->ask('Administrator name');
        $email = $this->ask('Administrator email');
        $password = $this->secret('Password (at least 8 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($name) || trim($name) === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A name and a valid email address are required.');

            return self::FAILURE;
        }

        if (! is_string($password) || strlen($password) < 8 || $password !== $confirmation) {
            $this->error('The password must contain at least 8 characters and both entries must match.');

            return self::FAILURE;
        }

        $email = strtolower(trim($email));

        if (User::where('email', $email)->exists()) {
            $this->error('An account already uses this email address.');

            return self::FAILURE;
        }

        User::create([
            'name' => trim($name),
            'email' => $email,
            'password' => $password,
            'role' => 'admin',
            'profile_status' => 'active',
        ]);

        $this->info('Administrator account created.');

        return self::SUCCESS;
    }
}
