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
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NumberGenerator;
use App\Services\ZugferdXml;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ZugferdTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');
    }

    private function draft(Tenant $tenant, User $user, Customer $customer, array $overrides = []): Invoice
    {
        $data = array_merge([
            'type' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-10-11',
            'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [
                ['article_id' => '', 'description' => "Beratung\nVor Ort", 'quantity' => '2,5', 'unit' => 'Stunde', 'unit_price' => '100,00', 'discount_percent' => '10', 'tax_rate' => '19'],
                ['article_id' => '', 'description' => 'Fachbuch', 'quantity' => '1', 'unit' => 'Stk', 'unit_price' => '29,90', 'discount_percent' => '', 'tax_rate' => '7'],
            ],
        ], $overrides);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->post('/invoices', $data)->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);

        return Invoice::latest('id')->firstOrFail();
    }

    /** Liest die eingebettete XML aus dem PDF (Dateianhänge sind Flate-komprimiert). */
    private function embeddedXml(string $pdf): ?string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);

        foreach ($matches[1] as $stream) {
            $plain = @gzuncompress($stream);

            if ($plain !== false && str_contains($plain, 'CrossIndustryInvoice')) {
                return $plain;
            }
        }

        return null;
    }

    private function issue(Tenant $tenant, Invoice $invoice): Invoice
    {
        $accounting = $this->makeUser($tenant, 'accounting');
        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id])->post(route('invoices.issue', $invoice));

        Tenancy::set($tenant->id);

        return $invoice->fresh();
    }

    public function test_issued_invoice_is_a_pdf_a3_with_valid_embedded_xml(): void
    {
        $tenant = $this->makeTenant('Testfirma GmbH', ['email' => 'Email: rechnung@testfirma.example', 'iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->issue($tenant, $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer, [
            'discount_percent' => '5', 'skonto1_percent' => '2', 'skonto1_days' => '8', 'customer_order_ref' => 'B-77',
        ]));

        $this->assertSame('issued', $invoice->status);

        $pdf = Storage::disk('local')->get($invoice->pdf_path);
        $this->assertStringContainsString('factur-x.xml', $pdf);
        $this->assertStringContainsString('<pdfaid:part>3</pdfaid:part>', $this->xmpOf($pdf));

        $xml = $this->embeddedXml($pdf);
        $this->assertNotNull($xml, 'Die XML ist nicht im PDF eingebettet.');
        $this->assertSame([], ZugferdXml::validate($xml));

        $this->assertStringContainsString('<ram:ID>urn:cen.eu:en16931:2017</ram:ID>', $xml);
        $this->assertStringContainsString('<ram:ID>R-2026-0001</ram:ID>', $xml);
        $this->assertStringContainsString('<ram:TypeCode>380</ram:TypeCode>', $xml);
        $this->assertStringContainsString('schemeID="EM">rechnung@testfirma.example<', $xml); // ohne "Email:"
        $this->assertStringContainsString('<ram:IBANID>DE02120300000000202051</ram:IBANID>', $xml);
        $this->assertStringContainsString('#SKONTO#TAGE=8#PROZENT=2.00#', $xml);
        $this->assertStringContainsString('<ram:IssuerAssignedID>B-77</ram:IssuerAssignedID>', $xml);
        $this->assertStringContainsString('unitCode="HUR"', $xml);
        $this->assertStringContainsString('unitCode="C62"', $xml);
        $this->assertStringContainsString('<ram:GrandTotalAmount>'.number_format((float) $invoice->gross_total, 2, '.', '').'</ram:GrandTotalAmount>', $xml);
    }

    private function xmpOf(string $pdf): string
    {
        // Der XMP-Datenstrom ist unkomprimiert.
        return preg_match('/<x:xmpmeta.*?<\/x:xmpmeta>/s', $pdf, $m) ? $m[0] : '';
    }

    public function test_credit_note_has_type_381_and_references_the_invoice(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->issue($tenant, $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer));

        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])->post(route('invoices.credit-note', $invoice));
        Tenancy::set($tenant->id);
        $credit = $this->issue($tenant, Invoice::where('type', 'credit_note')->firstOrFail());

        $xml = $this->embeddedXml(Storage::disk('local')->get($credit->pdf_path));
        $this->assertSame([], ZugferdXml::validate($xml));
        $this->assertStringContainsString('<ram:TypeCode>381</ram:TypeCode>', $xml);
        $this->assertStringContainsString('<ram:IssuerAssignedID>R-2026-0001</ram:IssuerAssignedID>', $xml);
        $this->assertStringContainsString('<ram:ID>G-2026-0001</ram:ID>', $xml);
    }

    public function test_missing_seller_data_blocks_issue_without_consuming_a_number(): void
    {
        $tenant = $this->makeTenant('Ohne Steuernummer GmbH', ['vat_id' => null, 'tax_number' => null]);
        $customer = $this->makeCustomer($tenant);
        $draft = $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer);

        $invoice = $this->issue($tenant, $draft);

        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame(0, NumberGenerator::lastFor($tenant, 'invoice', false, Carbon::parse('2026-10-11')));

        $this->get(route('invoices.show', $draft))->assertSee('USt-IdNr. oder Steuernummer fehlt');
    }

    public function test_test_documents_get_no_xml(): void
    {
        $tenant = $this->makeTenant('Testfirma GmbH', ['test_mode' => true]);
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->issue($tenant, $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer));

        $this->assertStringStartsWith('TEST-', $invoice->number);
        $this->assertNull($this->embeddedXml(Storage::disk('local')->get($invoice->pdf_path)));
    }

    public function test_reverse_charge_needs_the_buyer_vat_id(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant, '10002', ['vat_id' => null]);
        $draft = $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer, ['tax_mode' => 'reverse_charge']);

        $this->assertSame('draft', $this->issue($tenant, $draft)->status);

        $customer = $this->makeCustomer($tenant, '10003', ['vat_id' => 'DE987654321']);
        $good = $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer, ['tax_mode' => 'reverse_charge']);
        $issued = $this->issue($tenant, $good);

        $xml = $this->embeddedXml(Storage::disk('local')->get($issued->pdf_path));
        $this->assertSame([], ZugferdXml::validate($xml));
        $this->assertStringContainsString('<ram:CategoryCode>AE</ram:CategoryCode>', $xml);
        $this->assertStringContainsString('VATEX-EU-AE', $xml);
    }

    public function test_calculation_check_detects_a_wrong_total(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->issue($tenant, $this->draft($tenant, $this->makeUser($tenant, 'clerk'), $customer));
        $xml = $this->embeddedXml(Storage::disk('local')->get($invoice->pdf_path));

        $broken = preg_replace('#<ram:GrandTotalAmount>[0-9.]+#', '<ram:GrandTotalAmount>1.00', $xml);
        $this->assertNotSame([], ZugferdXml::validate($broken));
    }

    public function test_email_is_extracted_from_free_text(): void
    {
        $this->assertSame('info@firma.de', ZugferdXml::extractEmail('Email: info@firma.de'));
        $this->assertSame('a.b+c@sub.firma.de', ZugferdXml::extractEmail('E-Mail a.b+c@sub.firma.de (Büro)'));
        $this->assertNull(ZugferdXml::extractEmail('keine Adresse'));
        $this->assertNull(ZugferdXml::extractEmail(null));
    }
}
