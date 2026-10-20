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
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CustomerPickerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
        $this->makeCustomer($this->tenant, '10001', ['company' => 'Müller & Söhne "Bau" GmbH']);
        $this->makeCustomer($this->tenant, '10002', ['company' => 'Schmidt AG']);
    }

    private function open(string $route): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($this->clerk)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this->get(route($route));
    }

    public function test_all_three_forms_load_the_customer_search_script(): void
    {
        foreach (['invoices.create', 'quotes.create', 'recurring.create'] as $route) {
            $this->open($route)->assertOk()
                ->assertSee('js/customer-combo.js', false)
                ->assertSee('data-pick-msg="'.e(__('messages.invoices.customer_pick')).'"', false)
                ->assertSee('data-placeholder="'.e(__('messages.invoices.customer_search')).'"', false);
        }
    }

    public function test_every_customer_option_carries_its_name_and_stays_escaped(): void
    {
        foreach (['invoices.create', 'quotes.create', 'recurring.create'] as $route) {
            $this->open($route)->assertOk()
                ->assertSee('data-name="'.e('Müller & Söhne "Bau" GmbH').'"', false)
                ->assertSee('data-name="Schmidt AG"', false)
                ->assertSee('10002 – Schmidt AG', false);
        }
    }

    public function test_only_the_recurring_form_fills_the_description_from_the_customer(): void
    {
        $this->open('recurring.create')->assertOk()->assertSee('data-fill-name="name"', false);
        $this->open('invoices.create')->assertOk()->assertDontSee('data-fill-name', false);
        $this->open('quotes.create')->assertOk()->assertDontSee('data-fill-name', false);
    }

    public function test_the_script_keeps_a_filled_description_and_the_server_still_requires_a_customer(): void
    {
        $js = file_get_contents(public_path('js/customer-combo.js'));
        $this->assertStringContainsString("cur === '' || cur === lastAuto", $js);

        $this->actingAs($this->clerk)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);
        $this->post(route('recurring.store'), ['name' => 'X', 'customer_id' => ''])->assertSessionHasErrors('customer_id');
    }
}
