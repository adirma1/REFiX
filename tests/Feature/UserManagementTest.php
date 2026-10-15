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
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_admin_creates_new_user_who_must_change_password(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->post('/users', [
                'email' => 'neu@example.com', 'name' => 'Neuer Nutzer',
                'password' => 'startpasswort-123', 'role' => 'clerk',
            ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'neu@example.com')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertSame('clerk', $user->roleIn($tenant->id));
    }

    public function test_existing_user_is_added_to_the_tenant_without_changing_the_password(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $admin = $this->makeUser($a, 'admin');
        $existing = $this->makeUser($b, 'admin', ['email' => 'vorhanden@example.com']);
        $oldHash = $existing->password;

        $this->actingAs($admin)->withSession(['tenant_id' => $a->id])
            ->post('/users', ['email' => 'vorhanden@example.com', 'role' => 'viewer'])
            ->assertRedirect(route('users.index'));

        $this->assertSame('viewer', $existing->fresh()->roleIn($a->id));
        $this->assertSame($oldHash, $existing->fresh()->password);
    }

    public function test_new_user_needs_a_name_and_a_strong_password(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->post('/users', ['email' => 'neu@example.com', 'password' => 'kurz', 'role' => 'clerk'])
            ->assertSessionHasErrors(['name', 'password']);

        $this->assertNull(User::where('email', 'neu@example.com')->first());
    }

    public function test_last_admin_cannot_be_demoted_or_removed(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->patch(route('users.update', $admin), ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->delete(route('users.destroy', $admin))->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->fresh()->roleIn($tenant->id));
    }

    public function test_admin_can_demote_when_another_admin_exists(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $second = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->patch(route('users.update', $second), ['role' => 'accounting'])
            ->assertRedirect(route('users.index'));

        $this->assertSame('accounting', $second->fresh()->roleIn($tenant->id));
    }

    public function test_user_of_another_tenant_cannot_be_modified(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $admin = $this->makeUser($a, 'admin');
        $foreign = $this->makeUser($b, 'clerk');

        $this->actingAs($admin)->withSession(['tenant_id' => $a->id]);

        $this->patch(route('users.update', $foreign), ['role' => 'admin'])->assertNotFound();
        $this->delete(route('users.destroy', $foreign))->assertNotFound();
        $this->assertSame('clerk', $foreign->fresh()->roleIn($b->id));
    }

    public function test_tenant_can_be_created_and_test_mode_toggled(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->post('/tenants', ['name' => 'Zweite GmbH', 'country' => 'DE', 'default_payment_days' => 14, 'test_mode' => '1'])
            ->assertRedirect(route('tenants.index'));

        $new = \App\Models\Tenant::where('name', 'Zweite GmbH')->firstOrFail();
        $this->assertTrue($new->test_mode);
        $this->assertSame('admin', $admin->fresh()->roleIn($new->id));

        $this->put(route('tenants.update', $new), [
            'name' => 'Zweite GmbH', 'country' => 'DE', 'default_payment_days' => 30, 'test_mode' => '0',
            'iban' => 'DE26 4786 0125 1319 8036 00', 'bic' => 'genodem1gtl',
        ])->assertRedirect(route('tenants.index'));

        $new->refresh();
        $this->assertFalse($new->test_mode);
        $this->assertSame('DE26478601251319803600', $new->iban);
        $this->assertSame('GENODEM1GTL', $new->bic);
    }

    public function test_test_mode_banner_is_visible(): void
    {
        $tenant = $this->makeTenant();
        $tenant->update(['test_mode' => true]);
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->get('/')
            ->assertOk()
            ->assertSee('TESTMODUS');
    }

    public function test_english_interface_can_be_selected(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin', ['locale' => 'en']);

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->get('/customers')
            ->assertOk()
            ->assertSee('New customer');
    }
}
