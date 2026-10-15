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

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PerPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_default_is_25_and_can_be_changed_per_user(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $clerk = $this->makeUser($tenant, 'clerk');
        for ($i = 1; $i <= 30; $i++) {
            $this->makeCustomer($tenant, (string) (10000 + $i));
        }

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);
        $this->assertCount(25, $this->get('/customers')->assertOk()->viewData('customers')->items());

        $this->from('/customers?q=Kunde&page=2')->post('/profile/per-page', ['per_page' => 10])
            ->assertRedirect('/customers?q=Kunde'); // Filter bleiben, Seite wird zurückgesetzt
        $this->assertSame(10, $admin->fresh()->per_page);

        $response = $this->get('/customers')->assertOk()->assertSee('Seite 1 von 3');
        $this->assertCount(10, $response->viewData('customers')->items());

        // andere Benutzer sind nicht betroffen
        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id]);
        $this->assertCount(25, $this->get('/customers')->viewData('customers')->items());
    }

    public function test_only_allowed_values_are_accepted(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        foreach ([0, 7, 100000, 'abc', ''] as $bad) {
            $this->post('/profile/per-page', ['per_page' => $bad])->assertSessionHasErrors('per_page');
        }
        $this->assertSame(25, $admin->fresh()->per_page);

        $this->post('/profile/per-page', ['per_page' => 100])->assertSessionHasNoErrors();
        $this->assertSame(100, $admin->fresh()->per_page);
    }

    public function test_selector_is_shown_on_all_lists(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        foreach (['/customers', '/articles', '/invoices', '/audit'] as $url) {
            $this->get($url)->assertOk()->assertSee('Zeilen pro Seite');
        }
    }
}
