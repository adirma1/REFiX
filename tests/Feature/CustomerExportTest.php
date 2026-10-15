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
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerExporter;
use App\Services\Import\CustomerImporter;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CustomerExportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'admin');
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function export(array $data = [], array $query = [])
    {
        return $this->actAs($this->admin)->post(route('customers.export.download', $query), array_merge([
            'scope' => 'all', 'fields' => ['customer_number', 'company', 'email'], 'delimiter' => 'semicolon',
            'encoding' => 'utf8', 'date_format' => 'de',
        ], $data));
    }

    private function csv($response): string
    {
        return $response->streamedContent();
    }

    public function test_export_page_and_button_are_available_to_editors_but_not_to_viewers(): void
    {
        $this->actAs($this->admin)->get(route('customers.index'))->assertSee(route('customers.export'), false);
        $this->get(route('customers.export'))->assertOk()->assertSee('Kunden exportieren')->assertSee('Stammdaten')->assertSee('Homepage');

        $viewer = $this->makeUser($this->tenant, 'viewer');
        $this->actAs($viewer)->get(route('customers.export'))->assertForbidden();
        $this->post(route('customers.export.download'), ['scope' => 'all', 'fields' => ['company'], 'delimiter' => 'semicolon', 'encoding' => 'utf8', 'date_format' => 'de'])
            ->assertForbidden();
    }

    public function test_exports_the_chosen_fields_in_template_order_with_bom_and_headers(): void
    {
        $this->makeCustomer($this->tenant, '10001', ['company' => 'Alpha GmbH']);

        // Auswahl in „falscher“ Reihenfolge: die Spalten folgen trotzdem der Importvorlage.
        $response = $this->export(['fields' => ['email', 'customer_number', 'company']]);
        $response->assertOk()->assertDownload('kunden-2026-10-11.csv');
        $csv = $this->csv($response);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lines = explode("\r\n", trim(substr($csv, 3)));
        $this->assertSame('Kundennummer;Firma;"E-Mail (Rechnungsempfänger)"', $lines[0]);
        $this->assertSame('10001;"Alpha GmbH";kunde10001@example.com', $lines[1]);
    }

    public function test_formats_for_dates_flags_decimals_delimiter_and_encoding(): void
    {
        $this->makeCustomer($this->tenant, '10001', [
            'company' => 'Müller AG', 'customer_since' => '2024-03-15', 'customer_until' => '2030-12-31', 'birthday' => '1980-05-06',
            'discount_percent' => '2.5', 'tags' => '#m #w', 'active' => true,
        ]);
        $fields = ['company', 'customer_since', 'customer_until', 'birthday', 'discount_percent', 'active', 'tags'];

        $de = $this->csv($this->export(['fields' => $fields]));
        // Spalten folgen der Reihenfolge der Importvorlage, nicht der Reihenfolge der Auswahl.
        $this->assertStringContainsString('"Müller AG";2,50;15.03.2024;31.12.2030;ja;"#m #w";06.05.1980', $de);

        $iso = $this->csv($this->export(['fields' => $fields, 'date_format' => 'iso', 'delimiter' => 'comma']));
        $this->assertStringContainsString('2.50,2024-03-15,2030-12-31,ja,"#m #w",1980-05-06', $iso);

        $tab = $this->csv($this->export(['fields' => ['customer_number', 'company'], 'delimiter' => 'tab']));
        $this->assertStringContainsString("Kundennummer\tFirma", $tab);

        $win = $this->csv($this->export(['fields' => ['company'], 'encoding' => 'cp1252']));
        $this->assertStringStartsNotWith("\xEF\xBB\xBF", $win);
        $this->assertStringContainsString("M\xFCller AG", $win);
    }

    public function test_scope_selection_uses_the_list_filters_and_all_ignores_them(): void
    {
        $this->makeCustomer($this->tenant, '10001', ['company' => 'Alpha AG', 'tags' => '#m']);
        $this->makeCustomer($this->tenant, '10002', ['company' => 'Beta AG', 'tags' => '#w']);
        $this->makeCustomer($this->tenant, '10003', ['company' => 'Gamma AG', 'tags' => '#m', 'active' => false]);

        $selection = $this->csv($this->export(['scope' => 'selection'], ['status' => 'active', 'tag' => '#m']));
        $this->assertStringContainsString('Alpha AG', $selection);
        $this->assertStringNotContainsString('Beta AG', $selection);
        $this->assertStringNotContainsString('Gamma AG', $selection);

        $all = $this->csv($this->export(['scope' => 'all'], ['status' => 'active', 'tag' => '#m']));
        $this->assertStringContainsString('Alpha AG', $all);
        $this->assertStringContainsString('Beta AG', $all);
        $this->assertStringContainsString('Gamma AG', $all);
    }

    public function test_deleted_customers_only_when_requested_and_never_from_other_tenants(): void
    {
        $this->makeCustomer($this->tenant, '10001', ['company' => 'Alpha AG']);
        $this->makeCustomer($this->tenant, '10002', ['company' => 'Gelöscht AG'])->delete();
        $other = $this->makeTenant('Fremd GmbH');
        $this->makeCustomer($other, '10001', ['company' => 'Fremd AG']);

        $without = $this->csv($this->export());
        $this->assertStringContainsString('Alpha AG', $without);
        $this->assertStringNotContainsString('Gelöscht AG', $without);
        $this->assertStringNotContainsString('Fremd AG', $without);

        $with = $this->csv($this->export(['with_deleted' => 1]));
        $this->assertStringContainsString('Gelöscht AG', $with);
        $this->assertStringNotContainsString('Fremd AG', $with);
    }

    public function test_validation_requires_fields_and_known_options(): void
    {
        $this->actAs($this->admin);

        $this->post(route('customers.export.download'), ['scope' => 'all', 'fields' => [], 'delimiter' => 'semicolon', 'encoding' => 'utf8', 'date_format' => 'de'])
            ->assertSessionHasErrors('fields');
        $this->post(route('customers.export.download'), ['scope' => 'all', 'fields' => ['password'], 'delimiter' => 'semicolon', 'encoding' => 'utf8', 'date_format' => 'de'])
            ->assertSessionHasErrors('fields.0');
        $this->post(route('customers.export.download'), ['scope' => 'all', 'fields' => ['company'], 'delimiter' => 'pipe', 'encoding' => 'utf8', 'date_format' => 'de'])
            ->assertSessionHasErrors('delimiter');
    }

    public function test_formula_injection_is_defused_but_phone_numbers_stay_intact(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://x\")", CustomerExporter::safe('=HYPERLINK("http://x")'));
        $this->assertSame("'@SUM(A1)", CustomerExporter::safe('@SUM(A1)'));
        $this->assertSame("'-2+3*cmd", CustomerExporter::safe('-2+3*cmd'));
        $this->assertSame('+49 5242 12345', CustomerExporter::safe('+49 5242 12345'));
        $this->assertSame('-5', CustomerExporter::safe('-5'));
        $this->assertSame('Normal', CustomerExporter::safe('Normal'));

        $this->makeCustomer($this->tenant, '10001', ['company' => '=cmd|calc', 'phone' => '+49 5242 12345']);
        $csv = $this->csv($this->export(['fields' => ['company', 'phone']]));
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString('+49 5242 12345', $csv);
    }

    public function test_settings_are_remembered_per_user_and_the_export_is_logged(): void
    {
        $this->makeCustomer($this->tenant, '10001');
        $this->export(['fields' => ['company', 'tags'], 'delimiter' => 'comma', 'date_format' => 'iso'])->assertOk();

        // Der angemeldete Benutzer wird neu geladen, wie es bei der nächsten echten Anfrage geschieht.
        $this->admin = $this->admin->fresh();
        $this->actAs($this->admin)->get(route('customers.export'))->assertOk()
            ->assertViewHas('prefs', fn (array $p) => $p['fields'] === ['company', 'tags'] && $p['delimiter'] === 'comma' && $p['date_format'] === 'iso');

        $other = $this->makeUser($this->tenant, 'accounting');
        $this->actAs($other)->get(route('customers.export'))
            ->assertViewHas('prefs', fn (array $p) => count($p['fields']) === count(CustomerExporter::fields()) && $p['delimiter'] === 'semicolon');

        Tenancy::set($this->tenant->id);
        $log = AuditLog::where('action', 'customers.exported')->firstOrFail();
        $this->assertSame(1, $log->details['count']);
        $this->assertSame(['company', 'tags'], $log->details['fields']);
    }

    public function test_export_can_be_imported_again(): void
    {
        $this->makeCustomer($this->tenant, '10001', [
            'company' => 'Roundtrip KG', 'customer_since' => '2024-03-15', 'tags' => '#m #xyz', 'website' => 'https://rt.example', 'birthday' => '1975-04-03',
        ]);
        $csv = $this->csv($this->export(['fields' => CustomerExporter::fields()]));

        // In einem anderen Mandanten wieder einlesen.
        $other = $this->makeTenant('Ziel GmbH');
        Tenancy::set($other->id);
        $parsed = CustomerImporter::parse($csv);
        $mapping = CustomerImporter::guess($parsed['headers']);
        $this->assertSame(1, app(CustomerImporter::class)->run($parsed['rows'], $mapping)['created']);

        $copy = Customer::where('customer_number', '10001')->firstOrFail();
        $this->assertSame('Roundtrip KG', $copy->company);
        $this->assertSame('2024-03-15', $copy->customer_since->toDateString());
        $this->assertSame('#m #xyz', $copy->tags);
        $this->assertSame('https://rt.example', $copy->website);
        $this->assertSame('1975-04-03', $copy->birthday->toDateString());
        $this->assertTrue($copy->active);
    }
    public function test_every_exported_column_heading_is_recognised_by_the_import(): void
    {
        $labels = array_map(fn ($f) => CustomerExporter::label($f), CustomerExporter::fields());
        $mapping = CustomerImporter::guess($labels);

        foreach (CustomerExporter::fields() as $i => $field) {
            $this->assertSame($i, $mapping[$field], 'Spalte nicht erkannt: '.$labels[$i]);
        }
    }
}
