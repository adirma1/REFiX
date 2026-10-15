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
use App\Services\InvoiceService;
use App\Support\CustomerRules;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PrivateCustomerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function login(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);
        Tenancy::set($tenant->id);
    }

    private function privateData(array $overrides = []): array
    {
        return $this->customerData(array_merge([
            'company' => '', 'salutation' => 'Herr', 'first_name' => 'Max', 'last_name' => 'Mustermann',
        ], $overrides));
    }

    public function test_a_private_person_needs_no_company(): void
    {
        $this->login();

        $this->post(route('customers.store'), $this->privateData())->assertSessionHasNoErrors();

        $customer = Customer::sole();
        $this->assertSame('Max Mustermann', $customer->company);
        $this->assertTrue($customer->isPrivate());
        $this->assertSame('', $customer->contactLine());
    }

    public function test_company_or_a_name_is_required(): void
    {
        $this->login();

        $this->post(route('customers.store'), $this->privateData(['first_name' => '', 'last_name' => '', 'salutation' => '']))
            ->assertSessionHasErrors('company');
        $this->assertSame(0, Customer::count());

        // Nur ein Nachname genügt.
        $this->post(route('customers.store'), $this->privateData(['first_name' => '', 'customer_number' => '20002']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Mustermann', Customer::where('customer_number', '20002')->value('company'));
    }

    public function test_a_company_with_a_contact_person_keeps_its_contact_line(): void
    {
        $this->login();

        $this->post(route('customers.store'), $this->privateData(['company' => 'Muster GmbH']))->assertSessionHasNoErrors();

        $customer = Customer::sole();
        $this->assertSame('Muster GmbH', $customer->company);
        $this->assertFalse($customer->isPrivate());
        $this->assertSame('Herr Max Mustermann', $customer->contactLine());
    }

    public function test_editing_a_private_person_keeps_the_name_in_sync(): void
    {
        $this->login();
        $this->post(route('customers.store'), $this->privateData())->assertSessionHasNoErrors();
        $customer = Customer::sole();

        // Das Formular zeigt die Firma leer, nicht den abgeleiteten Namen.
        $html = $this->get(route('customers.edit', $customer))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*id="company"[^>]*value=""/', $html);

        $this->put(route('customers.update', $customer), $this->privateData(['customer_number' => '20001', 'first_name' => 'Moritz']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Moritz Mustermann', $customer->fresh()->company);

        // Später doch eine Firma eintragen.
        $this->put(route('customers.update', $customer), $this->privateData(['customer_number' => '20001', 'company' => 'Neue GmbH']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Neue GmbH', $customer->fresh()->company);
        $this->assertFalse($customer->fresh()->isPrivate());
    }

    public function test_documents_use_the_name_as_address_without_a_duplicate_line(): void
    {
        $this->login();
        $this->post(route('customers.store'), $this->privateData())->assertSessionHasNoErrors();

        $snapshot = app(InvoiceService::class)->snapshot(Customer::sole());

        $this->assertSame('Max Mustermann', $snapshot['company']);
        $this->assertSame('', $snapshot['contact']);
    }

    public function test_the_list_and_the_invoice_form_show_the_name(): void
    {
        $this->login();
        $this->post(route('customers.store'), $this->privateData())->assertSessionHasNoErrors();

        $this->get(route('customers.index'))->assertOk()->assertSee('Max Mustermann');
        $this->get(route('invoices.create'))->assertOk()->assertSee('20001 – Max Mustermann');
    }

    public function test_import_rules_accept_a_name_instead_of_a_company(): void
    {
        $base = [
            'customer_number' => '1', 'street' => 'S 1', 'zip' => '12345', 'city' => 'O', 'country' => 'DE', 'email' => 'a@example.com', 'currency' => 'EUR',
        ];

        $ok = Validator::make($base + ['company' => '', 'first_name' => 'Eva', 'last_name' => 'Muster'], CustomerRules::rules());
        $this->assertFalse($ok->fails());

        $bad = Validator::make($base + ['company' => '', 'first_name' => '', 'last_name' => ''], CustomerRules::rules());
        $this->assertTrue($bad->fails());
        $this->assertArrayHasKey('company', $bad->errors()->messages());
    }
}
