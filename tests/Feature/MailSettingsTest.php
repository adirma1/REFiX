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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use CreatesMailSetup, CreatesTenants, RefreshDatabase;

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'mail_account_id' => '', 'mail_send_roles' => ['accounting'],
            'mail_invoice_subject' => '', 'mail_invoice_body' => '', 'mail_credit_subject' => '', 'mail_credit_body' => '',
        ], $overrides);
    }

    public function test_admin_assigns_account_roles_and_texts(): void
    {
        $tenant = $this->makeTenant();
        $account = $this->makeMailAccount();

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post('/mail', $this->settings([
                'mail_account_id' => (string) $account->id,
                'mail_send_roles' => ['accounting', 'clerk'],
                'mail_invoice_subject' => 'Ihre Rechnung {belegnummer}',
                'mail_invoice_body' => "Hallo {kunde},\r\nanbei {betrag}.",
            ]))->assertSessionHasNoErrors()->assertRedirect(route('mail.edit'));

        $tenant->refresh();
        $this->assertSame($account->id, $tenant->mail_account_id);
        $this->assertSame(['accounting', 'clerk'], $tenant->mailRoles());
        $this->assertSame('Ihre Rechnung {belegnummer}', $tenant->mail_invoice_subject);
        $this->assertSame("Hallo {kunde},\nanbei {betrag}.", $tenant->mail_invoice_body);
        $this->assertNull($tenant->mail_credit_subject);
    }

    public function test_only_admins_may_open_or_change_the_settings(): void
    {
        $tenant = $this->makeTenant();

        foreach (['accounting', 'clerk', 'viewer'] as $role) {
            $this->actingAs($this->makeUser($tenant, $role))->withSession(['tenant_id' => $tenant->id]);
            $this->get('/mail')->assertForbidden();
            $this->post('/mail', $this->settings(['mail_send_roles' => ['clerk']]))->assertForbidden();
        }

        $this->assertNull(Tenant::find($tenant->id)->mail_send_roles);
    }

    public function test_default_is_accounting_and_an_empty_selection_means_admin_only(): void
    {
        $tenant = $this->makeTenant();
        $this->assertSame(['accounting'], $tenant->mailRoles());

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post('/mail', $this->settings(['mail_send_roles' => []]))->assertSessionHasNoErrors();

        $this->assertSame([], $tenant->fresh()->mailRoles());
    }

    public function test_viewer_role_cannot_be_granted(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post('/mail', $this->settings(['mail_send_roles' => ['viewer']]))
            ->assertSessionHasErrors('mail_send_roles.0');
    }

    public function test_unknown_placeholders_and_foreign_accounts_are_rejected(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post('/mail', $this->settings([
                'mail_account_id' => '999',
                'mail_invoice_body' => 'Hallo {kundenname}, anbei {betrag}',
            ]))->assertSessionHasErrors(['mail_account_id', 'mail_invoice_body']);
    }

    public function test_texts_equal_to_the_default_are_not_stored(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        // Seite zeigt den Standardtext vorausgefüllt; unverändert speichern legt nichts fest.
        $page = $this->get('/mail')->assertOk()->assertSee('Rechnung {belegnummer} von {firma}', false);
        $this->assertStringContainsString('Gutschrift {belegnummer} von {firma}', $page->getContent());

        $this->post('/mail', $this->settings(['mail_invoice_subject' => 'Rechnung {belegnummer} von {firma}']))
            ->assertSessionHasNoErrors();
        $this->assertNull($tenant->fresh()->mail_invoice_subject);
    }
}
