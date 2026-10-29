<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_administrator_with_an_eight_character_password(): void
    {
        $this->artisan('app:create-administrator')
            ->expectsQuestion('Administrator name', 'Administration informatique')
            ->expectsQuestion('Administrator email', 'administrator88@gmail.com')
            ->expectsQuestion('Password (at least 8 characters)', 'Time229!')
            ->expectsQuestion('Confirm password', 'Time229!')
            ->expectsOutputToContain('Administrator account created.')
            ->assertExitCode(0);

        $administrator = User::query()->where('email', 'administrator88@gmail.com')->firstOrFail();

        $this->assertSame('admin', $administrator->role);
        $this->assertSame('active', $administrator->profile_status);
        $this->assertTrue(Hash::check('Time229!', $administrator->password));
    }
}
