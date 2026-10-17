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

use App\Models\Invoice;
use App\Services\Layout\DocumentModel;
use App\Services\Layout\LayoutService;
use App\Services\Layout\TemplateEngine;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Satz „Zahlbar bis … ohne Abzug.“ je Mandant ausblendbar; Übersetzung „Angebot“ in den Nummernkreisen. */
class DueTextTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');
    }

    private function invoice($tenant): Invoice
    {
        $customer = $this->makeCustomer($tenant);
        $this->actingAs($this->makeUser($tenant, 'accounting'))->withSession(['tenant_id' => $tenant->id])
            ->post('/invoices', [
                'type' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-10-11', 'currency' => 'EUR', 'tax_mode' => 'standard',
                'items' => [['article_id' => '', 'description' => 'Beratung', 'quantity' => '1', 'unit' => 'Stk', 'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19']],
            ])->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_the_sentence_is_shown_by_default_and_can_be_hidden_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $invoice = $this->invoice($tenant);

        $this->assertTrue($tenant->fresh()->show_due_text);
        $model = DocumentModel::fromInvoice($invoice, $tenant->fresh(), null, null, [], null);
        $this->assertStringContainsString('ohne Abzug', $model['payment']['due_text']);

        $tenant->update(['show_due_text' => false]);
        $model = DocumentModel::fromInvoice($invoice, $tenant->fresh(), null, null, [], null);
        $this->assertSame('', $model['payment']['due_text']);
        $this->assertTrue($model['payment']['show']);
        $this->assertSame('25.10.2026', $model['doc']['due_date']);
    }

    public function test_tenant_form_saves_the_switch_and_keeps_it_when_the_field_is_missing(): void
    {
        $tenant = $this->makeTenant();
        $base = ['name' => 'Verein e.V.', 'country' => 'DE', 'default_payment_days' => 14, 'invoice_number_format' => 'R-{YYYY}-{NNNN}'];
        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id]);

        $this->put(route('tenants.update', $tenant), $base + ['show_due_text' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($tenant->fresh()->show_due_text);

        // Ohne das Feld (z. B. ältere Formulare) bleibt der Wert unverändert.
        $this->put(route('tenants.update', $tenant), $base)->assertSessionHasNoErrors();
        $this->assertFalse($tenant->fresh()->show_due_text);

        $this->put(route('tenants.update', $tenant), $base + ['show_due_text' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($tenant->fresh()->show_due_text);

        $this->get(route('tenants.edit', $tenant))->assertOk()->assertSee('name="show_due_text"', false);
    }

    public function test_numbering_page_translates_quote_in_english(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->makeUser($tenant, 'admin', ['locale' => 'en']))->withSession(['tenant_id' => $tenant->id]);

        $this->get(route('numbering.index'))->assertOk()->assertSee('Quote')->assertDontSee('Angebot');
    }

    public function test_customer_number_and_service_date_can_be_hidden_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $invoice = $this->invoice($tenant);
        [$body] = LayoutService::standardTemplate();

        $this->assertTrue($tenant->fresh()->show_customer_number);
        $this->assertTrue($tenant->fresh()->show_service_date);
        $model = DocumentModel::fromInvoice($invoice, $tenant->fresh(), null, null, [], null);
        $html = TemplateEngine::render(TemplateEngine::parse($body), $model);
        $this->assertSame('10001', $model['customer']['number']);
        $this->assertStringContainsString('Kundennummer', $html);
        $this->assertStringContainsString('Leistungsdatum', $html);

        $tenant->update(['show_customer_number' => false, 'show_service_date' => false]);
        $model = DocumentModel::fromInvoice($invoice, $tenant->fresh(), null, null, [], null);
        $html = TemplateEngine::render(TemplateEngine::parse($body), $model);
        $this->assertSame('', $model['customer']['number']);
        $this->assertSame('', $model['doc']['service_period']);
        $this->assertSame('', $model['labels']['service_date']);
        $this->assertStringNotContainsString('Kundennummer', $html);
        $this->assertStringNotContainsString('Leistungsdatum', $html);
        $this->assertStringContainsString($invoice->displayNumber(), $html);
    }

    public function test_tenant_form_saves_the_header_switches(): void
    {
        $tenant = $this->makeTenant();
        $base = ['name' => 'Verein e.V.', 'country' => 'DE', 'default_payment_days' => 14, 'invoice_number_format' => 'R-{YYYY}-{NNNN}'];
        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id]);

        $this->put(route('tenants.update', $tenant), $base + ['show_customer_number' => '0', 'show_service_date' => '0'])->assertSessionHasNoErrors();
        $tenant->refresh();
        $this->assertFalse($tenant->show_customer_number);
        $this->assertFalse($tenant->show_service_date);

        // Ohne die Felder bleibt alles, wie es ist.
        $this->put(route('tenants.update', $tenant), $base)->assertSessionHasNoErrors();
        $this->assertFalse($tenant->fresh()->show_customer_number);

        $this->put(route('tenants.update', $tenant), $base + ['show_customer_number' => '1', 'show_service_date' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($tenant->fresh()->show_service_date);

        $this->get(route('tenants.edit', $tenant))->assertOk()
            ->assertSee('name="show_customer_number"', false)->assertSee('name="show_service_date"', false);
    }
}
