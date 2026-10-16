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
use App\Models\Quote;
use App\Models\RecurringInvoice;
use App\Models\Tenant;
use App\Services\ZugferdXml;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Mandanten ohne Umsatzsteuer (z. B. Verein): 0 %, Hinweistext, ZUGFeRD-Kategorie E. */
class VatFreeTenantTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const NOTE = 'Kein Ausweis der Umsatzsteuer, da Kleinunternehmer gemäß § 19 UStG.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');
    }

    private function vatFreeTenant(array $attributes = []): Tenant
    {
        // Ein Verein hat oft nur eine Steuernummer, keine USt-IdNr.
        return $this->makeTenant('Musterverein e.V.', array_merge([
            'vat_id' => null, 'tax_number' => '337/5700/1234', 'vat_free' => true, 'vat_free_note' => self::NOTE,
        ], $attributes));
    }

    private function act(Tenant $tenant, string $role = 'accounting'): static
    {
        $this->actingAs($this->makeUser($tenant, $role))->withSession(['tenant_id' => $tenant->id]);
        Tenancy::set($tenant->id);

        return $this;
    }

    private function items(): array
    {
        return [
            ['article_id' => '', 'description' => 'Kursgebühr', 'quantity' => '2', 'unit' => 'Stk', 'unit_price' => '50,00', 'discount_percent' => '', 'tax_rate' => '19'],
        ];
    }

    private function invoiceForm(int $customerId, array $overrides = []): array
    {
        return array_merge([
            'type' => 'invoice', 'customer_id' => $customerId, 'issue_date' => '2026-10-11',
            'currency' => 'EUR', 'tax_mode' => 'standard', 'items' => $this->items(),
        ], $overrides);
    }

    private function createInvoice(Tenant $tenant, array $overrides = []): Invoice
    {
        $customer = $this->makeCustomer($tenant, (string) random_int(30000, 99999));
        $this->act($tenant)->post('/invoices', $this->invoiceForm($customer->id, $overrides))->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_tenant_form_saves_the_setting_and_requires_a_note(): void
    {
        $tenant = $this->makeTenant();
        $base = ['name' => 'Musterverein e.V.', 'country' => 'DE', 'default_payment_days' => 14, 'invoice_number_format' => 'R-{YYYY}-{NNNN}'];
        $this->act($tenant, 'admin');

        $this->put(route('tenants.update', $tenant), $base + ['vat_free' => '1', 'vat_free_note' => ''])
            ->assertSessionHasErrors('vat_free_note');

        $this->put(route('tenants.update', $tenant), $base + ['vat_free' => '1', 'vat_free_note' => self::NOTE])
            ->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertTrue($tenant->vat_free);
        $this->assertSame(self::NOTE, $tenant->vat_free_note);

        // Ohne Häkchen ist der Hinweistext nicht nötig.
        $this->put(route('tenants.update', $tenant), $base + ['vat_free' => '0', 'vat_free_note' => ''])->assertSessionHasNoErrors();
        $this->assertFalse($tenant->fresh()->vat_free);

        $this->get(route('tenants.edit', $tenant))->assertOk()
            ->assertSee(__('messages.tenants.vat_title'))
            ->assertSee(__('messages.tenants.vat_presets.kleinunternehmer.label'));
    }

    public function test_invoices_of_a_vat_free_tenant_have_no_vat_and_carry_the_note(): void
    {
        $invoice = $this->createInvoice($this->vatFreeTenant());

        $this->assertSame('exempt', $invoice->tax_mode);
        $this->assertSame(self::NOTE, $invoice->tax_note);
        $this->assertSame('100.00', number_format((float) $invoice->net_total, 2, '.', ''));
        $this->assertSame(0.0, (float) $invoice->tax_total);
        $this->assertSame(100.0, (float) $invoice->gross_total);
        $this->assertSame(self::NOTE, $invoice->taxNoteText());
        $this->assertSame(0.0, (float) $invoice->tax_breakdown[0]['rate']);
    }

    public function test_forms_lock_the_tax_mode_for_vat_free_tenants_only(): void
    {
        $vatFree = $this->vatFreeTenant();
        $this->act($vatFree)->get(route('invoices.create'))->assertOk()
            ->assertSee('name="tax_mode" value="exempt"', false)
            ->assertSee(__('messages.invoices.tax_mode_locked'));
        $this->get(route('quotes.create'))->assertOk()->assertSee('name="tax_mode" value="exempt"', false);
        $this->get(route('recurring.create'))->assertOk()->assertSee('name="tax_mode" value="exempt"', false);

        // Normaler Mandant: Auswahl wie bisher, ohne die Option „Ohne Umsatzsteuer“.
        $normal = $this->makeTenant('Normal GmbH');
        $this->act($normal)->get(route('invoices.create'))->assertOk()
            ->assertSee(__('messages.invoices.tax_modes.reverse_charge'))
            ->assertDontSee(__('messages.invoices.tax_modes.exempt'))
            ->assertDontSee('name="tax_mode" value="exempt"', false);
    }

    public function test_a_normal_tenant_is_not_affected(): void
    {
        $invoice = $this->createInvoice($this->makeTenant('Normal GmbH'));

        $this->assertSame('standard', $invoice->tax_mode);
        $this->assertNull($invoice->tax_note);
        $this->assertSame('', $invoice->taxNoteText());
        $this->assertSame(19.0, (float) $invoice->tax_breakdown[0]['rate']);
    }

    public function test_quotes_and_recurring_templates_are_forced_to_no_vat(): void
    {
        $tenant = $this->vatFreeTenant();
        $customer = $this->makeCustomer($tenant);
        $this->act($tenant);

        $this->post(route('quotes.store'), [
            'customer_id' => $customer->id, 'issue_date' => '2026-10-11', 'valid_until' => '2026-11-11',
            'currency' => 'EUR', 'tax_mode' => 'standard', 'items' => $this->items(),
        ])->assertSessionHasNoErrors();
        $quote = Quote::latest('id')->firstOrFail();
        $this->assertSame('exempt', $quote->tax_mode);
        $this->assertSame(self::NOTE, $quote->taxNoteText());
        $this->assertSame(0.0, (float) $quote->tax_total);

        $this->post(route('recurring.store'), [
            'name' => 'Beitrag', 'customer_id' => $customer->id, 'interval' => 'monthly', 'billing' => 'advance',
            'next_date' => '2026-11-01', 'end_date' => '', 'currency' => 'EUR', 'tax_mode' => 'standard', 'items' => $this->items(),
        ])->assertSessionHasNoErrors();
        $this->assertSame('exempt', RecurringInvoice::latest('id')->firstOrFail()->tax_mode);
    }

    public function test_issued_invoice_has_category_e_with_the_note_and_needs_no_vat_id(): void
    {
        $tenant = $this->vatFreeTenant(['iban' => 'DE02120300000000202051']);
        $invoice = $this->createInvoice($tenant);

        $this->act($tenant)->post(route('invoices.issue', $invoice))->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);
        $invoice = $invoice->fresh();
        $this->assertSame('issued', $invoice->status);

        $pdf = Storage::disk('local')->get($invoice->pdf_path);
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        $xml = null;
        foreach ($matches[1] as $stream) {
            $plain = @gzuncompress($stream);
            if ($plain !== false && str_contains($plain, 'CrossIndustryInvoice')) {
                $xml = $plain;
            }
        }

        $this->assertNotNull($xml);
        $this->assertSame([], ZugferdXml::validate($xml));
        $this->assertStringContainsString('<ram:CategoryCode>E</ram:CategoryCode>', $xml);
        $this->assertStringNotContainsString('<ram:CategoryCode>S</ram:CategoryCode>', $xml);
        $this->assertStringContainsString('<ram:ExemptionReason>'.htmlspecialchars(self::NOTE, ENT_XML1).'</ram:ExemptionReason>', $xml);
        $this->assertStringNotContainsString('ExemptionReasonCode', $xml);
        $this->assertStringContainsString('<ram:TaxTotalAmount currencyID="EUR">0.00</ram:TaxTotalAmount>', $xml);
        $this->assertStringContainsString('<ram:GrandTotalAmount>100.00</ram:GrandTotalAmount>', $xml);
    }

    public function test_changing_the_tenant_note_does_not_touch_issued_documents(): void
    {
        $tenant = $this->vatFreeTenant();
        $invoice = $this->createInvoice($tenant);
        $this->act($tenant)->post(route('invoices.issue', $invoice))->assertSessionHasNoErrors();

        $tenant->update(['vat_free_note' => 'Umsatzsteuerfreie Leistung gemäß § 4 Nr. 22 UStG.']);
        Tenancy::set($tenant->id);

        $this->assertSame(self::NOTE, $invoice->fresh()->taxNoteText());
    }

    public function test_credit_note_follows_the_original_invoice(): void
    {
        // Rechnung noch mit Umsatzsteuer, danach stellt der Mandant um: Die Gutschrift bleibt beim Steuerfall der Rechnung.
        $tenant = $this->makeTenant('Wandel e.V.');
        $standard = $this->createInvoice($tenant);
        $this->post(route('invoices.issue', $standard))->assertSessionHasNoErrors();
        $tenant->update(['vat_free' => true, 'vat_free_note' => self::NOTE]);

        $this->post(route('invoices.credit-note', $standard))->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);
        $credit = Invoice::where('type', 'credit_note')->latest('id')->firstOrFail();
        $this->assertSame('standard', $credit->tax_mode);
        $this->assertSame(19.0, (float) $credit->tax_breakdown[0]['rate']);

        // Rechnung ohne Umsatzsteuer: Gutschrift übernimmt den Hinweistext der Rechnung, nicht den aktuellen.
        $exempt = $this->createInvoice($tenant);
        $this->post(route('invoices.issue', $exempt))->assertSessionHasNoErrors();
        $tenant->update(['vat_free_note' => 'Anderer Text']);

        $this->post(route('invoices.credit-note', $exempt))->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);
        $creditExempt = Invoice::where('type', 'credit_note')->latest('id')->firstOrFail();
        $this->assertSame('exempt', $creditExempt->tax_mode);
        $this->assertSame(self::NOTE, $creditExempt->taxNoteText());
    }

    public function test_pdf_and_screen_show_the_note(): void
    {
        $tenant = $this->vatFreeTenant();
        $invoice = $this->createInvoice($tenant);

        $this->act($tenant)->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee(self::NOTE)
            ->assertSee(__('messages.invoices.tax_modes.exempt'));
    }
}
