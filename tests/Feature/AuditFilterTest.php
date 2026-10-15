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
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditFilterTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function entry(int $tenantId, int $userId, string $action, string $date, ?string $subject = null, ?array $details = null): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'action' => $action,
            'subject_type' => $subject, 'subject_id' => $subject ? 1 : null,
            'details' => $details, 'created_at' => $date.' 10:00:00',
        ]);
    }

    /** @return array<int, string> */
    private function actionsShown($response): array
    {
        return $response->viewData('logs')->pluck('action')->all();
    }

    public function test_audit_log_can_be_filtered(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant('Andere GmbH');
        $admin = $this->makeUser($tenant, 'admin');
        $clerk = $this->makeUser($tenant, 'clerk');
        $stranger = $this->makeUser($other, 'admin');

        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01', Invoice::class, ['number' => 'R-4711']);
        $this->entry($tenant->id, $clerk->id, 'customer.updated', '2026-09-01', 'App\Models\Customer');
        $this->entry($other->id, $stranger->id, 'secret.action', '2026-10-01');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $all = $this->actionsShown($this->get('/audit')->assertOk());
        $this->assertContains('invoice.created', $all);
        $this->assertContains('customer.updated', $all);
        $this->assertNotContains('secret.action', $all); // fremder Mandant

        $this->assertSame(['customer.updated'], $this->actionsShown($this->get('/audit?user='.$clerk->id)));
        $this->assertSame(['invoice.created'], $this->actionsShown($this->get('/audit?action=invoice.created')));
        $this->assertSame(['invoice.created'], $this->actionsShown($this->get('/audit?subject='.urlencode(Invoice::class))));
        $this->assertSame(['invoice.created'], $this->actionsShown($this->get('/audit?q=R-4711')));
        $this->assertSame(['customer.updated'], $this->actionsShown($this->get('/audit?q=customer')));
        $this->assertSame(['invoice.created'], $this->actionsShown($this->get('/audit?from=2026-09-15&to=2026-10-31&q=invoice')));
        $this->assertSame(['customer.updated'], $this->actionsShown($this->get('/audit?to=2026-09-30&q=.')));
    }

    public function test_foreign_or_invalid_filter_values_are_ignored(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant('Andere GmbH');
        $admin = $this->makeUser($tenant, 'admin');
        $stranger = $this->makeUser($other, 'admin');

        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01');
        $this->entry($other->id, $stranger->id, 'secret.action', '2026-10-01');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        // Benutzer eines anderen Mandanten, unbekannte Aktion und kaputtes Datum wirken nicht als Filter.
        $shown = $this->actionsShown($this->get('/audit?user='.$stranger->id.'&action=secret.action&from=kaputt'));
        $this->assertContains('invoice.created', $shown);
        $this->assertNotContains('secret.action', $shown);
    }

    public function test_filter_form_and_reset_link(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/audit')->assertOk()->assertSee('Filtern')->assertDontSee('Zurücksetzen');
        $this->get('/audit?q=zzz')->assertOk()->assertSee('Zurücksetzen')->assertSee('Keine Einträge für diese Filter.');
    }

    public function test_audit_log_lives_in_the_settings_menu_and_logout_is_a_text_button(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])->get('/')->assertOk()
            ->assertSeeInOrder(['Einstellungen', 'Protokoll', '</details>'], false)
            ->assertSee('>Abmelden</button>', false)
            ->assertSee('>'.$admin->name.'</a>', false)
            ->assertDontSee('user-icon', false);
    }

    public function test_settings_menu_is_shown_to_admins_only(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $accounting = $this->makeUser($tenant, 'accounting');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);
        $this->get('/')->assertOk()->assertSee('Einstellungen')->assertSee('Nummernkreise')->assertSee('Mandanten')->assertSee('Protokoll');

        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id]);
        $this->get('/')->assertOk()->assertDontSee('Einstellungen')->assertDontSee('Nummernkreise');
    }

    public function test_audit_log_shows_25_rows_per_page(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        for ($i = 1; $i <= 30; $i++) {
            $this->entry($tenant->id, $admin->id, 'test.action'.$i, '2026-10-01');
        }
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $page1 = $this->get('/audit')->assertOk()->assertSee('Seite 1 von 2')->assertSee('Weiter');
        $this->assertCount(25, $page1->viewData('logs')->items());

        $page2 = $this->get('/audit?page=2')->assertOk()->assertSee('Seite 2 von 2');
        $this->assertCount(5, $page2->viewData('logs')->items());
    }
}
