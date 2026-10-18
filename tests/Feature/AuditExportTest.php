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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Protokoll exportieren (CSV, Text) und vollständig leeren. */
class AuditExportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function entry(int $tenantId, int $userId, string $action, string $date, ?array $details = null): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'action' => $action,
            'details' => $details, 'created_at' => $date.' 10:00:00', 'ip_address' => '192.0.2.7',
        ]);
    }

    public function test_csv_export_has_bom_header_and_respects_filters(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant('Andere GmbH');
        $admin = $this->makeUser($tenant, 'admin', ['name' => 'Anna Admin']);
        $stranger = $this->makeUser($other, 'admin');

        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01', ['number' => 'R-4711']);
        $this->entry($tenant->id, $admin->id, 'customer.updated', '2026-09-01');
        $evil = $this->makeUser($tenant, 'clerk', ['name' => '=1+1']);
        $this->entry($tenant->id, $evil->id, 'evil.entry', '2026-09-02');
        $this->entry($other->id, $stranger->id, 'secret.action', '2026-10-01');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $response = $this->get(route('audit.export', ['format' => 'csv']))->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("Zeit;Benutzer;Aktion;Objekt;Details;IP", $csv);
        $this->assertStringContainsString('invoice.created', $csv);
        $this->assertStringContainsString('Anna Admin', $csv);
        $this->assertStringContainsString('192.0.2.7', $csv);
        $this->assertStringNotContainsString('secret.action', $csv); // fremder Mandant
        // Formeln werden entschärft: die Zelle beginnt mit einem Apostroph
        $this->assertStringContainsString("'=1+1", $csv);
        // in der Reihenfolge des Eintragens (älteste zuerst)
        $this->assertLessThan(strpos($csv, 'customer.updated'), strpos($csv, 'invoice.created'));

        $filtered = $this->get(route('audit.export', ['format' => 'csv', 'action' => 'invoice.created']))->streamedContent();
        $this->assertStringContainsString('invoice.created', $filtered);
        $this->assertStringNotContainsString('customer.updated', $filtered);
    }

    public function test_text_export_has_one_line_per_entry(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin', ['name' => 'Anna Admin']);
        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01', ['number' => 'R-4711']);
        $this->entry($tenant->id, $admin->id, 'customer.updated', '2026-10-02');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $response = $this->get(route('audit.export', ['format' => 'txt']))->assertOk();
        $this->assertStringContainsString('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.txt', $response->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('2026-10-01 10:00:00 | Anna Admin | invoice.created', $lines[0]);
        $this->assertStringContainsString('R-4711', $lines[0]);
    }

    public function test_clear_deletes_the_whole_log_of_the_tenant_but_not_of_others(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant('Andere GmbH');
        $admin = $this->makeUser($tenant, 'admin');
        $stranger = $this->makeUser($other, 'admin');

        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01');
        $this->entry($tenant->id, $admin->id, 'customer.updated', '2026-09-01');
        $this->entry($other->id, $stranger->id, 'secret.action', '2026-10-01');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        // Auch mit gesetztem Filter wird alles gelöscht, nicht nur die Auswahl.
        $this->delete(route('audit.clear', ['action' => 'invoice.created']))
            ->assertRedirect(route('audit.index'))->assertSessionHas('status');

        $this->assertSame(0, AuditLog::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, AuditLog::where('tenant_id', $other->id)->count());

        $this->get(route('audit.index'))->assertOk()->assertSee(__('messages.audit.empty'));
    }

    public function test_only_admins_may_export_or_clear(): void
    {
        $tenant = $this->makeTenant();
        $clerk = $this->makeUser($tenant, 'clerk');
        $admin = $this->makeUser($tenant, 'admin');
        $this->entry($tenant->id, $admin->id, 'invoice.created', '2026-10-01');

        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id]);

        $this->get(route('audit.export'))->assertForbidden();
        $this->delete(route('audit.clear'))->assertForbidden();
        $this->assertSame(1, AuditLog::where('tenant_id', $tenant->id)->count());
    }
}
