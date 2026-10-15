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

class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_queries_return_nothing_without_an_active_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->makeCustomer($tenant, '10001');

        Tenancy::clear();

        $this->assertSame(0, Customer::count());
    }

    public function test_queries_only_return_data_of_the_active_tenant(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $this->makeCustomer($a, '10001');
        $this->makeCustomer($b, '10002');

        Tenancy::set($a->id);
        $this->assertSame(['10001'], Customer::pluck('customer_number')->all());

        Tenancy::set($b->id);
        $this->assertSame(['10002'], Customer::pluck('customer_number')->all());
    }

    public function test_creating_without_tenant_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        Tenancy::clear();
        Customer::create($this->customerData());
    }

    public function test_user_sees_only_own_customers(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $this->makeCustomer($a, '10001');
        $this->makeCustomer($b, '10002');
        $user = $this->makeUser($a);

        $this->actingAs($user)->withSession(['tenant_id' => $a->id])
            ->get('/customers')
            ->assertOk()
            ->assertSee('Kunde 10001 GmbH')
            ->assertDontSee('Kunde 10002 GmbH');
    }

    public function test_foreign_customer_cannot_be_opened_changed_or_deleted(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $foreign = $this->makeCustomer($b, '10002');
        $user = $this->makeUser($a);

        $this->actingAs($user)->withSession(['tenant_id' => $a->id]);

        $this->get(route('customers.edit', $foreign->id))->assertNotFound();
        $this->put(route('customers.update', $foreign->id), $this->customerData())->assertNotFound();
        $this->delete(route('customers.destroy', $foreign->id))->assertNotFound();

        Tenancy::set($b->id);
        $this->assertSame('Kunde 10002 GmbH', Customer::find($foreign->id)->company);
    }

    public function test_session_tenant_of_a_foreign_company_is_ignored(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $this->makeCustomer($b, '10002');
        $user = $this->makeUser($a);

        // Der Benutzer gehört nur zu A, auch wenn die Sitzung B behauptet.
        $this->actingAs($user)->withSession(['tenant_id' => $b->id])
            ->get('/customers')
            ->assertOk()
            ->assertDontSee('Kunde 10002 GmbH');
    }

    public function test_user_cannot_switch_to_a_foreign_tenant(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $user = $this->makeUser($a);

        $this->actingAs($user)->withSession(['tenant_id' => $a->id])
            ->post(route('tenants.switch', $b))
            ->assertForbidden();
    }

    public function test_user_can_switch_between_own_tenants(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $user = $this->makeUser($a);
        $b->users()->attach($user->id, ['role' => 'viewer']);

        $this->actingAs($user)->withSession(['tenant_id' => $a->id])
            ->post(route('tenants.switch', $b))
            ->assertRedirect(route('dashboard'));

        $this->assertSame($b->id, session('tenant_id'));
    }

    public function test_same_customer_number_is_allowed_in_different_tenants_and_gets_reassigned_in_one(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $this->makeCustomer($a, '10001');
        $userB = $this->makeUser($b);
        $userA = $this->makeUser($a);

        $this->actingAs($userB)->withSession(['tenant_id' => $b->id])
            ->post('/customers', $this->customerData(['customer_number' => '10001']))
            ->assertSessionHasNoErrors();

        $this->actingAs($userA)->withSession(['tenant_id' => $a->id])
            ->post('/customers', $this->customerData(['customer_number' => '10001', 'email' => 'x@example.com']))
            ->assertSessionHasNoErrors();

        // Schon vergeben: der neue Kunde bekommt automatisch die nächste freie Nummer.
        \App\Support\Tenancy::set($a->id);
        $this->assertSame(['10001', '10002'], \App\Models\Customer::orderBy('customer_number')->pluck('customer_number')->all());
    }
}
