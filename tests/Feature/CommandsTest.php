<?php

/*
 * RE-FiX – Rechnungsprogramm
 * Copyright (C) 2026 Martin Adirsch
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * version 2 of the GNU General Public License as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program
 * (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.
 *
 * SPDX-License-Identifier: GPL-2.0-only
 */

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_reset_password_sets_new_password_forces_change_and_ends_sessions(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);

        DB::table('sessions')->insert([
            'id' => 'sitzung1', 'user_id' => $user->id, 'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->artisan('user:reset-password', ['email' => 'a@example.com'])
            ->expectsQuestion('Neues Passwort (mindestens 12 Zeichen)', 'brandneues-passwort-1')
            ->expectsQuestion('Passwort wiederholen', 'brandneues-passwort-1')
            ->assertExitCode(0);

        $user->refresh();
        $this->assertTrue(Hash::check('brandneues-passwort-1', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertTrue(AuditLog::where('action', 'user.password_reset_cli')->exists());
    }

    public function test_reset_password_rejects_short_password(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);
        $oldHash = $user->password;

        $this->artisan('user:reset-password', ['email' => 'a@example.com'])
            ->expectsQuestion('Neues Passwort (mindestens 12 Zeichen)', 'kurz')
            ->expectsQuestion('Passwort wiederholen', 'kurz')
            ->assertExitCode(1);

        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_reset_password_with_random_option(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);
        $oldHash = $user->password;

        $this->artisan('user:reset-password', ['email' => 'a@example.com', '--random' => true])
            ->assertExitCode(0);

        $this->assertNotSame($oldHash, $user->fresh()->password);
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_reset_password_for_unknown_user_fails(): void
    {
        $this->artisan('user:reset-password', ['email' => 'gibts@nicht.example', '--random' => true])
            ->assertExitCode(1);
    }

    public function test_reset_two_factor_clears_all_two_factor_data(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);
        $user->forceFill([
            'two_factor_secret' => 'GEHEIMNIS',
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->artisan('user:reset-2fa', ['email' => 'a@example.com'])->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertFalse($user->hasTwoFactor());
    }

    public function test_create_admin_adds_admin_to_all_existing_tenants(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');

        $this->artisan('user:create-admin', [
            '--email' => 'notfall@example.com', '--name' => 'Notfall', '--password' => 'ein-langes-passwort-1',
        ])->assertExitCode(0);

        $user = User::where('email', 'notfall@example.com')->firstOrFail();
        $this->assertSame('admin', $user->roleIn($a->id));
        $this->assertSame('admin', $user->roleIn($b->id));
        $this->assertTrue($user->must_change_password);
    }

    public function test_create_admin_creates_a_tenant_if_none_exists(): void
    {
        $this->artisan('user:create-admin', [
            '--email' => 'erster@example.com', '--name' => 'Erster', '--password' => 'ein-langes-passwort-1',
            '--company' => 'Meine GmbH',
        ])->assertExitCode(0);

        $tenant = Tenant::where('name', 'Meine GmbH')->firstOrFail();
        $this->assertSame('admin', User::where('email', 'erster@example.com')->firstOrFail()->roleIn($tenant->id));
    }

    public function test_create_admin_with_if_none_does_nothing_when_users_exist(): void
    {
        $this->makeUser($this->makeTenant());

        $this->artisan('user:create-admin', [
            '--email' => 'zweiter@example.com', '--name' => 'Zweiter', '--password' => 'ein-langes-passwort-1',
            '--if-none' => true,
        ])->assertExitCode(0);

        $this->assertNull(User::where('email', 'zweiter@example.com')->first());
    }

    public function test_create_admin_rejects_duplicate_email_and_short_password(): void
    {
        $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);

        $this->artisan('user:create-admin', [
            '--email' => 'a@example.com', '--name' => 'Doppelt', '--password' => 'ein-langes-passwort-1',
        ])->assertExitCode(1);

        $this->artisan('user:create-admin', [
            '--email' => 'b@example.com', '--name' => 'Kurz', '--password' => 'kurz',
        ])->assertExitCode(1);
    }
}
