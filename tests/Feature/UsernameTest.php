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

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UsernameTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PASSWORD = 'ein-langes-passwort-123';

    public function test_setup_gives_the_first_admin_the_username_admin(): void
    {
        $this->post('/setup', [
            'company' => 'Firma', 'name' => 'Martin', 'email' => 'martin@example.com',
            'password' => 'ein-sehr-langes-passwort', 'password_confirmation' => 'ein-sehr-langes-passwort',
        ])->assertRedirect();

        $this->assertSame('Admin', User::where('email', 'martin@example.com')->firstOrFail()->username);
    }

    public function test_login_works_with_username_or_email_regardless_of_case(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com', 'username' => 'Admin']);

        $this->post('/login', ['login' => 'admin', 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->flushSession();

        $this->post('/login', ['login' => 'A@Example.com', 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_with_wrong_password_or_unknown_username_fails_with_the_same_message(): void
    {
        $this->makeUser($this->makeTenant(), 'admin', ['username' => 'Admin']);

        $this->post('/login', ['login' => 'Admin', 'password' => 'falsch'])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'Niemand', 'password' => self::PASSWORD])->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_a_username_never_matches_as_an_email_and_vice_versa(): void
    {
        $this->makeUser($this->makeTenant(), 'admin', ['email' => 'anna@example.com', 'username' => 'anna']);

        $this->assertSame('anna@example.com', User::findByLogin('anna')->email);
        $this->assertSame('anna', User::findByLogin('ANNA@example.com')->username);
        $this->assertNull(User::findByLogin('anna@example'));
        $this->assertNull(User::findByLogin(''));
    }

    public function test_two_factor_still_applies_when_signing_in_with_the_username(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['username' => 'Admin']);
        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['login' => 'Admin', 'password' => self::PASSWORD])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_admin_assigns_changes_and_clears_a_username(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->patch("/users/{$clerk->id}/username", ['username' => 'anna.m'])
            ->assertRedirect(route('users.index'));
        $this->assertSame('anna.m', $clerk->fresh()->username);

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->patch("/users/{$clerk->id}/username", ['username' => ''])
            ->assertRedirect(route('users.index'));
        $this->assertNull($clerk->fresh()->username);
    }

    public function test_username_must_be_unique_ignoring_case_and_well_formed(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin', ['username' => 'Admin']);
        $clerk = $this->makeUser($tenant, 'clerk');

        foreach (['admin', 'ab', 'mit leerzeichen', 'a@b.de', str_repeat('x', 51)] as $bad) {
            $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
                ->patch("/users/{$clerk->id}/username", ['username' => $bad])
                ->assertSessionHasErrors('role');
            $this->assertNull($clerk->fresh()->username, $bad);
        }

        // Den eigenen Namen erneut zu speichern ist kein Konflikt.
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->patch("/users/{$admin->id}/username", ['username' => 'Admin'])
            ->assertSessionHasNoErrors();
    }

    public function test_non_admins_cannot_set_usernames(): void
    {
        $tenant = $this->makeTenant();
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id])
            ->patch("/users/{$clerk->id}/username", ['username' => 'selbst'])
            ->assertForbidden();
        $this->assertNull($clerk->fresh()->username);
    }

    public function test_admin_cannot_rename_users_of_other_tenants_without_being_admin_there(): void
    {
        $a = $this->makeTenant('A');
        $b = $this->makeTenant('B');
        $adminA = $this->makeUser($a, 'admin');
        $shared = $this->makeUser($a, 'clerk');
        $b->users()->attach($shared->id, ['role' => 'clerk']);

        $this->actingAs($adminA)->withSession(['tenant_id' => $a->id])
            ->patch("/users/{$shared->id}/username", ['username' => 'geteilt'])
            ->assertSessionHasErrors('role');
        $this->assertNull($shared->fresh()->username);

        // Ist er auch dort Admin, geht es.
        $b->users()->attach($adminA->id, ['role' => 'admin']);
        $adminA->forgetRoleCache();
        $this->actingAs($adminA->fresh())->withSession(['tenant_id' => $a->id])
            ->patch("/users/{$shared->id}/username", ['username' => 'geteilt'])
            ->assertSessionHasNoErrors();
        $this->assertSame('geteilt', $shared->fresh()->username);
    }

    public function test_admin_can_set_a_username_when_creating_a_user(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->post('/users', [
                'email' => 'neu@example.com', 'name' => 'Neu', 'username' => 'neu',
                'password' => 'startpasswort-123', 'role' => 'clerk',
            ])->assertRedirect(route('users.index'));

        $this->assertSame('neu', User::where('email', 'neu@example.com')->firstOrFail()->username);
    }

    public function test_user_changes_own_email_with_current_password(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'admin', ['email' => 'alt@example.com', 'username' => 'Admin']);
        DB::table('password_reset_tokens')->insert(['email' => 'alt@example.com', 'token' => 'x', 'created_at' => now()]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/email', ['email' => 'neu@example.com', 'email_password' => self::PASSWORD])
            ->assertRedirect(route('profile.show'))->assertSessionHasNoErrors();

        $this->assertSame('neu@example.com', $user->fresh()->email);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'alt@example.com']);

        // Anmeldung mit neuer E-Mail und weiterhin mit dem Benutzernamen; die alte Adresse gilt nicht mehr.
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['login' => 'alt@example.com', 'password' => self::PASSWORD])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'neu@example.com', 'password' => self::PASSWORD])->assertRedirect(route('dashboard'));
    }

    public function test_email_change_needs_correct_password_and_a_free_address(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'admin', ['email' => 'alt@example.com']);
        $this->makeUser($tenant, 'clerk', ['email' => 'belegt@example.com']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/email', ['email' => 'neu@example.com', 'email_password' => 'falsch'])
            ->assertSessionHasErrors('email_password');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/email', ['email' => 'belegt@example.com', 'email_password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/email', ['email' => 'kein-mail', 'email_password' => self::PASSWORD])
            ->assertSessionHasErrors('email');

        $this->assertSame('alt@example.com', $user->fresh()->email);
    }

    public function test_user_cannot_change_own_username_via_profile(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'admin', ['username' => 'Admin']);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/email', ['email' => $user->email, 'email_password' => self::PASSWORD, 'username' => 'Hacker']);

        $this->assertSame('Admin', $user->fresh()->username);
    }

    public function test_cli_commands_accept_a_username(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['username' => 'Admin']);

        $this->artisan('user:reset-password', ['email' => 'admin', '--random' => true])->assertSuccessful();
        $this->assertTrue($user->fresh()->must_change_password);

        $this->artisan('user:reset-2fa', ['email' => 'Admin'])->assertSuccessful();
    }

    public function test_create_admin_command_defaults_to_the_username_admin_if_free(): void
    {
        $this->artisan('user:create-admin', [
            '--email' => 'a@example.com', '--name' => 'A', '--password' => 'ein-langes-passwort-123', '--company' => 'Firma',
        ])->assertSuccessful();
        $this->assertSame('Admin', User::where('email', 'a@example.com')->firstOrFail()->username);

        $this->artisan('user:create-admin', [
            '--email' => 'b@example.com', '--name' => 'B', '--password' => 'ein-langes-passwort-123',
        ])->assertSuccessful();
        $this->assertNull(User::where('email', 'b@example.com')->firstOrFail()->username);
    }

    public function test_one_row_save_updates_role_and_username_together(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->patch("/users/{$clerk->id}", ['role' => 'accounting', 'username' => 'anna'])
            ->assertRedirect(route('users.index'))->assertSessionHasNoErrors();

        $this->assertSame('anna', $clerk->fresh()->username);
        $this->assertSame('accounting', $clerk->fresh()->roleIn($tenant->id));
    }

    public function test_invalid_username_in_row_save_changes_nothing(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->patch("/users/{$clerk->id}", ['role' => 'accounting', 'username' => 'a b'])
            ->assertSessionHasErrors('role');

        $this->assertNull($clerk->fresh()->username);
        $this->assertSame('clerk', $clerk->fresh()->roleIn($tenant->id));
    }

    public function test_role_of_a_multi_tenant_user_can_change_when_the_username_stays_the_same(): void
    {
        $a = $this->makeTenant('A');
        $b = $this->makeTenant('B');
        $adminA = $this->makeUser($a, 'admin');
        $shared = $this->makeUser($a, 'clerk', ['username' => 'geteilt']);
        $b->users()->attach($shared->id, ['role' => 'clerk']);

        $this->actingAs($adminA)->withSession(['tenant_id' => $a->id])
            ->patch("/users/{$shared->id}", ['role' => 'accounting', 'username' => 'geteilt'])
            ->assertSessionHasNoErrors();

        $this->assertSame('accounting', $shared->fresh()->roleIn($a->id));
        $this->assertSame('geteilt', $shared->fresh()->username);
    }
}
