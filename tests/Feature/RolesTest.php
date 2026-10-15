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

use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_empty_optional_fields_are_stored_as_null(): void
    {
        $tenant = $this->makeTenant();
        $clerk = $this->makeUser($tenant, 'clerk');

        // Genau so schickt der Browser ein Formular ab, in dem optionale Felder leer bleiben.
        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id])
            ->post('/customers', $this->customerData([
                'discount_percent' => '', 'discount_days' => '', 'payment_term_days' => '',
                'vat_id' => '', 'phone' => '', 'buyer_reference' => '', 'notes' => '',
            ]))->assertSessionHasNoErrors()->assertRedirect(route('customers.index'));

        Tenancy::set($tenant->id);
        $customer = Customer::firstOrFail();
        $this->assertNull($customer->discount_percent);
        $this->assertNull($customer->discount_days);
        $this->assertNull($customer->payment_term_days);
        $this->assertNull($customer->vat_id);
        $this->assertNull($customer->getRawOriginal('phone'));
    }

    public function test_admin_sees_the_buttons_to_create_and_manage(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/customers')->assertOk()->assertSee('Neuer Kunde')->assertSee('Benutzer');
        $this->get('/articles')->assertOk()->assertSee('Neuer Artikel');
    }

    public function test_viewer_does_not_see_the_create_buttons(): void
    {
        $tenant = $this->makeTenant();
        $viewer = $this->makeUser($tenant, 'viewer');

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id])
            ->get('/customers')->assertOk()->assertDontSee('Neuer Kunde');
    }

    public function test_viewer_can_read_but_not_write(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant, '10001');
        $viewer = $this->makeUser($tenant, 'viewer');

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/customers')->assertOk();
        $this->get(route('customers.edit', $customer))->assertOk();
        $this->get('/customers/create')->assertForbidden();
        $this->post('/customers', $this->customerData())->assertForbidden();
        $this->put(route('customers.update', $customer), $this->customerData())->assertForbidden();
        $this->delete(route('customers.destroy', $customer))->assertForbidden();
    }

    public function test_clerk_can_create_and_edit_but_not_delete(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant, '10001');
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id]);

        $this->post('/customers', $this->customerData())->assertRedirect(route('customers.index'));
        $this->put(route('customers.update', $customer), $this->customerData(['customer_number' => '10001', 'company' => 'Geändert GmbH']))
            ->assertRedirect(route('customers.index'));
        $this->delete(route('customers.destroy', $customer))->assertForbidden();

        Tenancy::set($tenant->id);
        $this->assertSame('Geändert GmbH', $customer->fresh()->company);
    }

    public function test_accounting_can_delete(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant, '10001');
        $accounting = $this->makeUser($tenant, 'accounting');

        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id])
            ->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'));

        Tenancy::set($tenant->id);
        $this->assertNull(Customer::find($customer->id));
        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    public function test_only_admin_can_manage_users_tenants_and_audit_log(): void
    {
        $tenant = $this->makeTenant();
        $clerk = $this->makeUser($tenant, 'clerk');
        $accounting = $this->makeUser($tenant, 'accounting');

        foreach ([$clerk, $accounting] as $user) {
            $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

            $this->get('/users')->assertForbidden();
            $this->get('/audit')->assertForbidden();
            $this->get(route('tenants.edit', $tenant))->assertForbidden();
        }

        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/users')->assertOk();
        $this->get('/audit')->assertOk();
        $this->get(route('tenants.edit', $tenant))->assertOk();
    }

    public function test_role_in_one_tenant_does_not_apply_in_another(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $user = $this->makeUser($a, 'admin');
        $b->users()->attach($user->id, ['role' => 'viewer']);

        $this->actingAs($user)->withSession(['tenant_id' => $b->id])
            ->get('/users')
            ->assertForbidden();
    }
}
