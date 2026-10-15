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
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CustomerImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->tenant = $this->makeTenant();
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
    }

    private function actAs(User $user, ?Tenant $tenant = null): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);

        return $this;
    }

    private const HEAD = "Kundennummer;Firma;Straße;PLZ;Ort;Land;E-Mail;Zahlungsziel\n";

    /** Lädt die CSV hoch und gibt das Token zurück. */
    private function upload(string $csv, ?User $user = null): string
    {
        $response = $this->actAs($user ?? $this->clerk)->post('/customers/import', [
            'file' => UploadedFile::fake()->createWithContent('kunden.csv', $csv),
        ]);

        $response->assertSessionHasNoErrors();
        $location = $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#/customers/import/[a-f0-9]{32}$#', $location);

        return basename($location);
    }

    private function mapping(): array
    {
        return ['map' => ['customer_number' => '0', 'company' => '1', 'street' => '2', 'zip' => '3', 'city' => '4', 'country' => '5', 'email' => '6', 'payment_term_days' => '7']];
    }

    private function tenantCustomers()
    {
        Tenancy::set($this->tenant->id);

        return Customer::withTrashed()->orderBy('customer_number')->get();
    }

    public function test_full_flow_upload_map_preview_import(): void
    {
        $token = $this->upload(self::HEAD
            ."1001;Alpha GmbH;Hauptstr. 1;33378;Rheda;DE;alpha@example.com;30\n"
            ."1002;Beta AG;Nebenweg 2;33602;Bielefeld;;beta@example.com;\n");

        $this->get(route('customers.import.map', $token))->assertOk()->assertSee('Kundennummer')->assertSee('Zahlungsziel');

        $this->post(route('customers.import.preview', $token), $this->mapping())
            ->assertOk()->assertSee('werden importiert')->assertSee('Alpha GmbH')->assertSee('2 Kunden jetzt importieren');
        $this->assertCount(0, $this->tenantCustomers());

        $this->post(route('customers.import.run', $token), $this->mapping())
            ->assertRedirect(route('customers.index'))->assertSessionHas('status');

        $customers = $this->tenantCustomers();
        $this->assertCount(2, $customers);
        $this->assertSame('Alpha GmbH', $customers[0]->company);
        $this->assertSame(30, $customers[0]->payment_term_days);
        $this->assertSame('DE', $customers[1]->country); // leeres Land → DE
        $this->assertSame('EUR', $customers[1]->currency);
        $this->assertNull($customers[1]->payment_term_days);
        $this->assertSame(1, AuditLog::where('action', 'customer.imported')->count());
        $this->assertSame([], Storage::disk('local')->files('imports/'.$this->tenant->id)); // Datei aufgeräumt

        // Mit dem alten Token geht nichts mehr.
        $this->get(route('customers.import.map', $token))->assertRedirect(route('customers.import'));
    }

    public function test_duplicates_and_invalid_rows_are_skipped_and_reported(): void
    {
        $this->makeCustomer($this->tenant, '1001');
        $deleted = $this->makeCustomer($this->tenant, '1003');
        Tenancy::set($this->tenant->id);
        $deleted->delete(); // gelöschte Nummern bleiben belegt (eindeutiger Index)

        $token = $this->upload(self::HEAD
            ."1001;Schon da;Str. 1;11111;Ort;DE;a@example.com;\n"
            ."1002;Neu;Str. 2;22222;Ort;DE;b@example.com;\n"
            ."1002;Doppelt in Datei;Str. 3;33333;Ort;DE;c@example.com;\n"
            ."1003;War gelöscht;Str. 4;44444;Ort;DE;d@example.com;\n"
            ."1004;Ohne Mail;Str. 5;55555;Ort;DE;;\n"
            ."1005;Falsches Land;Str. 6;66666;Ort;Deutschland;e@example.com;\n"
            ."1006;Kaputte Mail;Str. 7;77777;Ort;DE;keine-mail;\n"
            ."1007;Zahlungsziel kaputt;Str. 8;88888;Ort;DE;f@example.com;abc\n");

        $this->post(route('customers.import.preview', $token), $this->mapping())
            ->assertOk()
            ->assertSee('Kundennummer 1001 gibt es bereits.')
            ->assertSee('Kundennummer 1002 kommt in der Datei mehrfach vor.')
            ->assertSee('Kundennummer 1003 gibt es bereits.')
            ->assertSee('Ohne Mail')->assertSee('Falsches Land')->assertSee('Kaputte Mail')->assertSee('Zahlungsziel kaputt');

        $this->post(route('customers.import.run', $token), $this->mapping())->assertSessionHas('status', fn ($m) => str_contains($m, '1 Kunden wurden importiert'));

        $numbers = $this->tenantCustomers()->pluck('customer_number')->all();
        $this->assertSame(['1001', '1002', '1003'], $numbers); // nur 1002 ist neu
        $this->assertSame('Neu', Customer::where('customer_number', '1002')->value('company'));
        $this->assertSame('Kunde 1001 GmbH', Customer::where('customer_number', '1001')->value('company')); // unverändert
    }

    public function test_missing_numbers_are_assigned_after_the_highest_one(): void
    {
        $this->makeCustomer($this->tenant, '2500');

        $token = $this->upload("Firma;Straße;PLZ;Ort;E-Mail\nEins;S 1;1;O;a@example.com\nZwei;S 2;2;O;b@example.com\n");
        $map = ['map' => ['company' => '0', 'street' => '1', 'zip' => '2', 'city' => '3', 'email' => '4']];

        $this->post(route('customers.import.preview', $token), $map)->assertSee('automatisch vergeben');
        $this->post(route('customers.import.run', $token), $map);

        $this->assertSame(['2500', '2501', '2502'], $this->tenantCustomers()->pluck('customer_number')->all());
    }

    public function test_windows_encoding_and_decimal_comma_work(): void
    {
        $csv = mb_convert_encoding("Kundennummer;Firma;Straße;PLZ;Ort;E-Mail;Skonto\n7;Müller & Söhne;Königstraße 1;33330;Gütersloh;m@example.com;2,5\n", 'Windows-1252', 'UTF-8');
        $token = $this->upload($csv);

        $this->post(route('customers.import.run', $token), ['map' => ['customer_number' => '0', 'company' => '1', 'street' => '2', 'zip' => '3', 'city' => '4', 'email' => '5', 'discount_percent' => '6']]);

        $customer = $this->tenantCustomers()->sole();
        $this->assertSame('Müller & Söhne', $customer->company);
        $this->assertSame('Königstraße 1', $customer->street);
        $this->assertSame('2.50', (string) $customer->discount_percent);
    }

    public function test_unmapped_required_fields_make_every_row_invalid(): void
    {
        $token = $this->upload(self::HEAD."1;Firma;Str;1;Ort;DE;a@example.com;\n");

        $this->post(route('customers.import.preview', $token), ['map' => ['customer_number' => '0', 'company' => '1']])
            ->assertOk()->assertSee('fehlerhaft');
        $this->post(route('customers.import.run', $token), ['map' => ['customer_number' => '0', 'company' => '1']]);

        $this->assertCount(0, $this->tenantCustomers());
    }

    public function test_invalid_column_indexes_are_ignored(): void
    {
        $token = $this->upload(self::HEAD."1;Firma;Str;1;Ort;DE;a@example.com;\n");
        $map = $this->mapping();
        $map['map']['phone'] = '99';
        $map['map']['notes'] = 'abc';

        $this->post(route('customers.import.run', $token), $map)->assertSessionHas('status');

        $this->assertNull($this->tenantCustomers()->sole()->phone);
    }

    public function test_bad_uploads_are_rejected(): void
    {
        $this->actAs($this->clerk);

        $this->post('/customers/import', [])->assertSessionHasErrors('file');
        $this->post('/customers/import', ['file' => UploadedFile::fake()->createWithContent('kunden.pdf', "a;b\n1;2\n")])->assertSessionHasErrors('file');

        $this->post('/customers/import', ['file' => UploadedFile::fake()->createWithContent('kunden.csv', "nur ein Wort\n")])
            ->assertRedirect(route('customers.import'))->assertSessionHas('warning');
        $this->post('/customers/import', ['file' => UploadedFile::fake()->createWithContent('kunden.csv', "Firma;Ort\n")])
            ->assertRedirect(route('customers.import'))->assertSessionHas('warning');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_tokens_of_other_tenants_and_made_up_tokens_do_not_work(): void
    {
        $token = $this->upload(self::HEAD."1;Firma;Str;1;Ort;DE;a@example.com;\n");

        $other = $this->makeTenant('Fremde GmbH');
        $otherClerk = $this->makeUser($other, 'clerk');
        $this->actAs($otherClerk, $other);

        $this->get(route('customers.import.map', $token))->assertRedirect(route('customers.import'));
        $this->post(route('customers.import.run', $token), $this->mapping())->assertRedirect(route('customers.import'));
        $this->get('/customers/import/'.str_repeat('a', 32))->assertRedirect(route('customers.import'));

        Tenancy::set($other->id);
        $this->assertSame(0, Customer::count());
    }

    public function test_import_creates_customers_only_in_the_active_tenant(): void
    {
        $other = $this->makeTenant('Fremde GmbH');
        $this->makeCustomer($other, '1');
        $token = $this->upload(self::HEAD."1;Gleiche Nummer;Str;1;Ort;DE;a@example.com;\n");

        $this->post(route('customers.import.run', $token), $this->mapping())->assertSessionHas('status');

        // Dieselbe Nummer in einem anderen Mandanten ist kein Duplikat.
        $this->assertSame(['Gleiche Nummer'], $this->tenantCustomers()->pluck('company')->all());
    }

    public function test_only_users_who_edit_master_data_may_import(): void
    {
        $viewer = $this->makeUser($this->tenant, 'viewer');
        $this->actAs($viewer);

        $this->get('/customers/import')->assertForbidden();
        $this->get('/customers/import/template')->assertForbidden();
        $this->post('/customers/import', ['file' => UploadedFile::fake()->createWithContent('k.csv', self::HEAD."1;F;S;1;O;DE;a@example.com;\n")])->assertForbidden();
        $this->get('/customers')->assertOk()->assertDontSee('Importieren');

        $this->actAs($this->clerk)->get('/customers')->assertSee('Importieren');
        $this->get('/customers/import')->assertOk();
    }

    public function test_template_is_a_utf8_csv_with_the_expected_columns(): void
    {
        $content = $this->actAs($this->clerk)->get('/customers/import/template')->assertOk()->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Kundennummer;Firma;Anrede;Vorname;Nachname;Straße und Hausnummer', $content);

        // Die Vorlage ist selbst importierbar.
        $token = $this->upload($content);
        $this->post(route('customers.import.preview', $token), ['map' => ['customer_number' => '0', 'company' => '1', 'street' => '5', 'zip' => '6', 'city' => '7', 'email' => '9']])
            ->assertSee('1 Kunden jetzt importieren');
    }
}
