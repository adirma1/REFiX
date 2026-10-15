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

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Mail\MailTemplates;
use Tests\TestCase;

class MailTemplatesTest extends TestCase
{
    private function invoice(array $attributes = [], ?Customer $customer = null): Invoice
    {
        $invoice = new Invoice(array_merge([
            'type' => 'invoice', 'number' => 'R-2026-0007', 'currency' => 'EUR', 'gross_total' => '1234.5',
            'issue_date' => '2026-10-11', 'due_date' => '2026-10-25', 'customer_snapshot' => ['company' => 'Kunde GmbH'],
        ], $attributes));
        $invoice->setRelation('customer', $customer);

        return $invoice;
    }

    public function test_render_replaces_known_placeholders_once_and_keeps_unknown_ones(): void
    {
        $out = MailTemplates::render('{kunde} schuldet {betrag} {nichtda}', ['kunde' => 'A {betrag}', 'betrag' => '5 EUR']);

        // Ein eingesetzter Wert wird nicht noch einmal ausgewertet.
        $this->assertSame('A {betrag} schuldet 5 EUR {nichtda}', $out);
    }

    public function test_unknown_placeholders_are_found(): void
    {
        $this->assertSame(['kundenname', 'foo'], MailTemplates::unknown('{kunde} {kundenname} {betrag} {foo} {foo}'));
        $this->assertSame([], MailTemplates::unknown('Nichts zu ersetzen'));
    }

    public function test_values_are_formatted_german(): void
    {
        $values = MailTemplates::values($this->invoice(), new Tenant(['name' => 'Testfirma GmbH']));

        $this->assertSame('R-2026-0007', $values['belegnummer']);
        $this->assertSame('1.234,50 EUR', $values['betrag']);
        $this->assertSame('25.10.2026', $values['faelligkeit']);
        $this->assertSame('11.10.2026', $values['datum']);
        $this->assertSame('Kunde GmbH', $values['kunde']);
        $this->assertSame('Testfirma GmbH', $values['firma']);
    }

    public function test_due_date_falls_back_to_the_invoice_date(): void
    {
        $values = MailTemplates::values($this->invoice(['due_date' => null]), new Tenant(['name' => 'X']));

        $this->assertSame('11.10.2026', $values['faelligkeit']);
    }

    public function test_salutation_depends_on_the_contact(): void
    {
        $tenant = new Tenant(['name' => 'X']);
        $contact = fn (string $salutation) => new Customer(['salutation' => $salutation, 'last_name' => 'Meier']);

        $this->assertSame('Sehr geehrter Herr Meier,', MailTemplates::values($this->invoice(customer: $contact('Herr')), $tenant)['anrede']);
        $this->assertSame('Sehr geehrte Frau Meier,', MailTemplates::values($this->invoice(customer: $contact('Frau')), $tenant)['anrede']);
        $this->assertSame('Sehr geehrte Damen und Herren,', MailTemplates::values($this->invoice(customer: $contact('')), $tenant)['anrede']);
        $this->assertSame('Sehr geehrte Damen und Herren,', MailTemplates::values($this->invoice(), $tenant)['anrede']);
    }

    public function test_compose_uses_defaults_by_type_and_tenant_texts_when_set(): void
    {
        $tenant = new Tenant(['name' => 'Testfirma GmbH']);

        [$subject, $body] = MailTemplates::compose($this->invoice(), $tenant);
        $this->assertSame('Rechnung R-2026-0007 von Testfirma GmbH', $subject);
        $this->assertStringContainsString('über 1.234,50 EUR. Bitte überweisen Sie den Betrag bis zum 25.10.2026.', $body);
        $this->assertStringEndsWith("Mit freundlichen Grüßen\nTestfirma GmbH", $body);

        [$subject, $body] = MailTemplates::compose($this->invoice(['type' => 'credit_note', 'number' => 'G-2026-0001']), $tenant);
        $this->assertSame('Gutschrift G-2026-0001 von Testfirma GmbH', $subject);
        $this->assertStringContainsString('gutgeschrieben', $body);

        $tenant->mail_invoice_subject = "Hallo\n{belegnummer}";
        $this->assertSame('Hallo R-2026-0007', MailTemplates::compose($this->invoice(), $tenant)[0]);
    }

    public function test_texts_stay_german_in_the_english_interface(): void
    {
        app()->setLocale('en');

        $this->assertSame('Rechnung R-2026-0007 von X', MailTemplates::compose($this->invoice(), new Tenant(['name' => 'X']))[0]);
        $this->assertSame('en', app()->getLocale());
    }
}
