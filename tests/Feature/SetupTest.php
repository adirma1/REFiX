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

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_setup_page_is_shown_while_no_user_exists(): void
    {
        $this->get('/setup')->assertOk()->assertSee('Ersteinrichtung');
    }

    public function test_login_redirects_to_setup_while_no_user_exists(): void
    {
        $this->get('/login')->assertRedirect(route('setup'));
    }

    public function test_setup_creates_tenant_and_admin_and_logs_in(): void
    {
        $this->post('/setup', [
            'company' => 'MAD Business Consulting GmbH',
            'name' => 'Martin Adirsch',
            'email' => 'martin@example.com',
            'password' => 'ein-sehr-langes-passwort',
            'password_confirmation' => 'ein-sehr-langes-passwort',
        ])->assertRedirect(route('profile.two-factor'));

        $user = User::where('email', 'martin@example.com')->firstOrFail();
        $tenant = Tenant::where('name', 'MAD Business Consulting GmbH')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin', $user->roleIn($tenant->id));
        $this->assertNotSame('ein-sehr-langes-passwort', $user->password);
    }

    public function test_setup_rejects_short_password(): void
    {
        $this->post('/setup', [
            'company' => 'Firma',
            'name' => 'Name',
            'email' => 'a@example.com',
            'password' => 'kurz',
            'password_confirmation' => 'kurz',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_is_locked_once_a_user_exists(): void
    {
        $this->makeUser($this->makeTenant());

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [])->assertNotFound();
    }
}
