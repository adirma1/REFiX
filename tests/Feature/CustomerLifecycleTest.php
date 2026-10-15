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
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Kunde seit / bis, Aktiv-Häkchen und Tags – und die Sperre für neue Rechnungen. */
class CustomerLifecycleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'admin');
        $this->accounting = $this->makeUser($this->tenant, 'accounting');
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function invoiceData(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'type' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-10-11',
            'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [['article_id' => '', 'description' => 'Beratung', 'quantity' => '1', 'unit' => 'Stunde',
                'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19']],
        ], $overrides);
    }

    /** Nach Requests und Artisan-Aufrufen ist der Mandant wieder gelöscht; für Abfragen neu setzen. */
    private function scoped(): static
    {
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function invoiceCount(): int
    {
        Tenancy::set($this->tenant->id);

        return Invoice::count();
    }

    private function customerCount(): int
    {
        Tenancy::set($this->tenant->id);

        return Customer::count();
    }

    private function byNumber(string $number): Customer
    {
        return Customer::where('customer_number', $number)->firstOrFail();
    }

    private function firstInvoice(): Invoice
    {
        return Invoice::firstOrFail();
    }

    private function customer(array $attributes = []): Customer
    {
        return $this->makeCustomer($this->tenant, (string) random_int(30000, 39999), $attributes);
    }

    private function items(): array
    {
        return [['description' => 'Beratung', 'quantity' => 1, 'unit' => 'Stunde', 'unit_price' => 100, 'discount_percent' => 0, 'tax_rate' => 19]];
    }

    public function test_new_customer_gets_today_as_since_date_active_and_normalized_tags(): void
    {
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData(['tags' => "  #m   #w \t#xyz "]))
            ->assertSessionHasNoErrors();

        $customer = $this->scoped()->byNumber('20001');
        $this->assertSame('2026-10-11', $customer->customer_since->toDateString());
        $this->assertNull($customer->customer_until);
        $this->assertTrue($customer->active);
        $this->assertSame('#m #w #xyz', $customer->tags);
        $this->assertSame(['#m', '#w', '#xyz'], $customer->tagList());
    }

    public function test_since_date_is_editable_and_german_dates_are_accepted(): void
    {
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData(['customer_since' => '15.03.2024', 'customer_until' => '31.12.2027']))
            ->assertSessionHasNoErrors();

        $customer = $this->scoped()->byNumber('20001');
        $this->assertSame('2024-03-15', $customer->customer_since->toDateString());
        $this->assertSame('2027-12-31', $customer->customer_until->toDateString());
    }

    public function test_form_shows_the_new_fields_with_defaults(): void
    {
        $this->actAs($this->admin)->get(route('customers.create'))->assertOk()
            ->assertSee('Kunde seit')->assertSee('Kunde bis')->assertSee('Tags')
            ->assertSee('value="2026-10-11"', false);
    }

    public function test_tags_are_limited_to_30_characters_and_until_may_not_precede_since(): void
    {
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData(['tags' => str_repeat('#', 31)]))
            ->assertSessionHasErrors('tags');
        $this->post(route('customers.store'), $this->customerData(['customer_since' => '2026-05-01', 'customer_until' => '2026-04-30']))
            ->assertSessionHasErrors('customer_until');
        $this->assertSame(0, $this->customerCount());
    }

    public function test_active_cannot_be_switched_on_while_the_until_date_is_in_the_past(): void
    {
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData(['customer_until' => '2026-09-30', 'active' => '1']))
            ->assertSessionHasErrors('active');

        // Ohne Häkchen ist das Datum in der Vergangenheit in Ordnung (Altkunde).
        $this->post(route('customers.store'), $this->customerData(['customer_until' => '2026-09-30', 'active' => '0']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->scoped()->byNumber('20001')->active);
    }

    public function test_saving_with_a_past_until_date_switches_active_off(): void
    {
        $customer = $this->customer();
        Tenancy::set($this->tenant->id);
        $customer->update(['customer_until' => '2026-10-10']);

        $this->assertFalse($customer->fresh()->active);
    }

    public function test_block_rule_for_dates(): void
    {
        $customer = new Customer(['active' => true, 'customer_until' => '2026-10-31']);

        $this->assertFalse($customer->isBlockedFor('2026-10-31')); // der „bis“-Tag selbst ist noch erlaubt
        $this->assertTrue($customer->isBlockedFor('2026-11-01'));
        $this->assertFalse($customer->isBlockedFor('2026-01-01'));
        $this->assertTrue((new Customer(['active' => false]))->isBlockedFor('2020-01-01'));
        $this->assertFalse((new Customer(['active' => true]))->isBlockedFor('2099-01-01'));
    }

    public function test_inactive_customer_gets_no_new_invoice(): void
    {
        $customer = $this->customer(['active' => false]);

        $this->actAs($this->accounting)->post('/invoices', $this->invoiceData($customer));

        $this->assertSame(0, $this->invoiceCount());

        $this->expectException(RuntimeException::class);
        app(InvoiceService::class)->saveDraft(null, $this->invoiceData($customer), $this->items(), $this->accounting);
    }

    public function test_invoice_dated_after_the_until_date_is_refused_but_on_the_day_it_is_allowed(): void
    {
        $customer = $this->customer(['customer_until' => '2026-10-31']);
        $this->actAs($this->accounting);

        $this->post('/invoices', $this->invoiceData($customer, ['issue_date' => '2026-10-31']))->assertSessionHasNoErrors();
        $this->assertSame(1, $this->invoiceCount());

        $this->post('/invoices', $this->invoiceData($customer, ['issue_date' => '2026-11-01']));
        $this->assertSame(1, $this->invoiceCount());
    }

    public function test_a_draft_cannot_be_issued_once_the_customer_is_blocked(): void
    {
        $customer = $this->customer();
        $this->actAs($this->accounting)->post('/invoices', $this->invoiceData($customer))->assertSessionHasNoErrors();
        $invoice = $this->scoped()->firstInvoice();

        $customer->update(['active' => false]);
        $this->post(route('invoices.issue', $invoice));

        $this->scoped();
        $this->assertTrue($invoice->fresh()->isDraft());

        $customer->update(['active' => true]);
        $this->post(route('invoices.issue', $invoice));
        $this->scoped();
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_credit_notes_remain_possible_for_blocked_customers(): void
    {
        $customer = $this->customer();
        $this->actAs($this->accounting)->post('/invoices', $this->invoiceData($customer))->assertSessionHasNoErrors();
        $invoice = $this->scoped()->firstInvoice();
        $this->post(route('invoices.issue', $invoice));

        $customer->update(['active' => false]);
        $credit = app(InvoiceService::class)->createCreditNote($invoice->fresh(), $this->accounting);

        $this->assertSame(Invoice::TYPE_CREDIT_NOTE, $credit->type);
    }

    public function test_recurring_template_is_skipped_cleanly_for_a_blocked_customer(): void
    {
        $customer = $this->customer();
        Tenancy::set($this->tenant->id);
        $recurring = RecurringInvoice::create([
            'name' => 'Wartung', 'customer_id' => $customer->id, 'active' => true, 'interval' => 'monthly', 'billing' => 'advance',
            'anchor_date' => '2026-10-01', 'occurrence' => 0, 'next_date' => '2026-10-01', 'currency' => 'EUR', 'tax_mode' => 'standard',
            'created_by' => $this->admin->id,
        ]);
        $recurring->items()->create([
            'position' => 1, 'description' => 'Wartung', 'quantity' => 1, 'unit' => 'pauschal', 'unit_price' => 100, 'discount_percent' => 0, 'tax_rate' => 19,
        ]);

        $customer->update(['active' => false]);
        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $this->assertSame(0, $this->invoiceCount());
        $this->assertSame(0, (int) $recurring->fresh()->occurrence);

        $customer->update(['active' => true]);
        $this->artisan('recurring:generate')->assertSuccessful();
        Tenancy::set($this->tenant->id);
        $this->assertGreaterThanOrEqual(1, $this->invoiceCount());
    }

    public function test_daily_command_switches_active_off_the_day_after_the_until_date(): void
    {
        $ended = $this->customer(['customer_until' => '2026-10-31']);
        $stillOpen = $this->customer(['customer_until' => '2026-10-31']);

        $this->travelTo(Carbon::parse('2026-10-31 12:00:00'));
        $this->artisan('customers:deactivate-expired')->assertSuccessful();
        $this->assertTrue(DB::table('customers')->where('id', $ended->id)->value('active') == 1);

        $this->travelTo(Carbon::parse('2026-11-01 05:00:00'));
        $this->artisan('customers:deactivate-expired')->assertSuccessful();

        $this->assertSame(0, (int) DB::table('customers')->where('id', $ended->id)->value('active'));
        $this->assertSame(0, (int) DB::table('customers')->where('id', $stillOpen->id)->value('active'));
        $this->scoped();
        $this->assertSame(2, AuditLog::where('action', 'customer.deactivated_expired')->count());
    }

    public function test_blocked_customers_are_not_offered_in_the_invoice_form(): void
    {
        $blocked = $this->customer(['company' => 'Gesperrt AG', 'active' => false]);
        $this->customer(['company' => 'Offen AG']);

        $this->actAs($this->accounting)->get(route('invoices.create'))->assertOk()
            ->assertSee('Offen AG')->assertDontSee('Gesperrt AG');
        $this->assertNotNull($blocked->id);
    }

    public function test_list_shows_status_and_filters_by_status_and_tag_and_searches_tags(): void
    {
        $this->customer(['company' => 'Alpha AG', 'tags' => '#m #xyz']);
        $this->customer(['company' => 'Beta AG', 'tags' => '#mm']);
        $this->customer(['company' => 'Gamma AG', 'active' => false]);

        $this->actAs($this->admin)->get(route('customers.index'))->assertOk()->assertSee('Inaktiv');

        $this->get(route('customers.index', ['status' => 'inactive']))->assertSee('Gamma AG')->assertDontSee('Alpha AG');
        $this->get(route('customers.index', ['status' => 'active']))->assertSee('Alpha AG')->assertDontSee('Gamma AG');
        $this->get(route('customers.index', ['tag' => '#m']))->assertSee('Alpha AG')->assertDontSee('Beta AG');
        $this->get(route('customers.index', ['q' => '#xyz']))->assertSee('Alpha AG')->assertDontSee('Beta AG');
    }

    public function test_import_accepts_the_new_columns(): void
    {
        $csv = "Kundennummer;Firma;Straße;PLZ;Ort;E-Mail;Kunde seit;Kunde bis;Aktiv;Tags\r\n"
            ."7001;Import AG;Weg 1;33378;Rheda;i@example.com;01.02.2025;31.12.2030;nein;#imp #x\r\n"
            ."7002;Import Zwei;Weg 2;33378;Rheda;j@example.com;;;;\r\n";
        $this->actAs($this->admin);

        $importer = app(\App\Services\Import\CustomerImporter::class);
        $parsed = \App\Services\Import\CustomerImporter::parse($csv);
        $mapping = \App\Services\Import\CustomerImporter::guess($parsed['headers']);
        $this->assertNotNull($mapping['customer_since']);
        $this->assertNotNull($mapping['tags']);

        $result = $importer->run($parsed['rows'], $mapping);
        $this->assertSame(2, $result['created']);

        $one = $this->scoped()->byNumber('7001');
        $this->assertSame('2025-02-01', $one->customer_since->toDateString());
        $this->assertSame('2030-12-31', $one->customer_until->toDateString());
        $this->assertFalse($one->active);
        $this->assertSame('#imp #x', $one->tags);

        $two = $this->scoped()->byNumber('7002');
        $this->assertSame('2026-10-11', $two->customer_since->toDateString());
        $this->assertTrue($two->active);
    }
    public function test_new_customers_get_automatic_numbers_from_the_counter(): void
    {
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData(['customer_number' => '']))->assertSessionHasNoErrors();
        $this->post(route('customers.store'), $this->customerData(['customer_number' => '', 'email' => 'b@example.com']))->assertSessionHasNoErrors();

        $this->scoped();
        $this->assertSame(['1', '2'], Customer::orderBy('customer_number')->pluck('customer_number')->all());
    }

    public function test_existing_number_is_replaced_by_the_next_free_one(): void
    {
        $this->makeCustomer($this->tenant, '10005');
        $this->makeCustomer($this->tenant, '10006');
        $this->actAs($this->admin);

        // Zähler startet hinter der höchsten vorhandenen Zahl.
        $this->post(route('customers.store'), $this->customerData(['customer_number' => '10005']))
            ->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->scoped();
        $this->assertTrue(Customer::where('customer_number', '10007')->exists());

        // Eigene, freie Nummer bleibt erhalten und verschiebt den Zähler nicht.
        $this->post(route('customers.store'), $this->customerData(['customer_number' => 'K-77', 'email' => 'c@example.com']));
        $this->post(route('customers.store'), $this->customerData(['customer_number' => '', 'email' => 'd@example.com']));
        $this->scoped();
        $this->assertTrue(Customer::where('customer_number', 'K-77')->exists());
        $this->assertTrue(Customer::where('customer_number', '10008')->exists());
    }

    public function test_numbers_of_deleted_customers_are_not_reused(): void
    {
        $this->makeCustomer($this->tenant, '5')->delete();
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData(['customer_number' => '']));

        $this->scoped();
        $this->assertTrue(Customer::where('customer_number', '6')->exists());
    }

    public function test_counter_can_be_set_on_the_numbering_page(): void
    {
        $this->actAs($this->admin)->get('/numbering')->assertOk()->assertSee('Kundennummern');

        $this->post('/numbering', ['type' => 'customer', 'is_test' => 0, 'last' => 4999])->assertRedirect('/numbering');
        $this->post(route('customers.store'), $this->customerData(['customer_number' => '']));

        $this->scoped();
        $this->assertTrue(Customer::where('customer_number', '5000')->exists());
        $this->get('/numbering')->assertSee('5001');
    }

    public function test_editing_still_rejects_a_duplicate_number(): void
    {
        $one = $this->makeCustomer($this->tenant, '1');
        $this->makeCustomer($this->tenant, '2');

        $this->actAs($this->admin)->put(route('customers.update', $one), $this->customerData(['customer_number' => '2']))
            ->assertSessionHasErrors('customer_number');
    }
    public function test_birthday_and_website_are_optional_and_validated(): void
    {
        $this->actAs($this->admin)->post(route('customers.store'), $this->customerData())->assertSessionHasNoErrors();
        $plain = $this->scoped()->byNumber('20001');
        $this->assertNull($plain->birthday);
        $this->assertNull($plain->website);

        $this->post(route('customers.store'), $this->customerData(['customer_number' => '', 'email' => 'b@example.com',
            'birthday' => '15.03.1980', 'website' => 'www.beispiel.de/start']))->assertSessionHasNoErrors();
        $this->scoped();
        $customer = Customer::where('email', 'b@example.com')->firstOrFail();
        $this->assertSame('1980-03-15', $customer->birthday->toDateString());
        $this->assertSame('https://www.beispiel.de/start', $customer->website);

        $this->post(route('customers.store'), $this->customerData(['customer_number' => '', 'email' => 'c@example.com', 'birthday' => '2030-01-01']))
            ->assertSessionHasErrors('birthday');
        $this->post(route('customers.store'), $this->customerData(['customer_number' => '', 'email' => 'd@example.com', 'website' => 'ftp://files.example']))
            ->assertSessionHasErrors('website');
    }

    public function test_import_takes_birthday_and_website(): void
    {
        $csv = "Kundennummer;Firma;Straße;PLZ;Ort;E-Mail;Geburtstag;Homepage\r\n9001;Web AG;Weg 1;33378;Rheda;w@example.com;03.04.1975;web.example\r\n";
        $this->actAs($this->admin);
        $parsed = \App\Services\Import\CustomerImporter::parse($csv);
        $mapping = \App\Services\Import\CustomerImporter::guess($parsed['headers']);
        app(\App\Services\Import\CustomerImporter::class)->run($parsed['rows'], $mapping);

        $customer = $this->scoped()->byNumber('9001');
        $this->assertSame('1975-04-03', $customer->birthday->toDateString());
        $this->assertSame('https://web.example', $customer->website);
    }
    public function test_dashboard_shows_customer_statistics_by_tag(): void
    {
        $this->customer(['tags' => '#m #xyz']);
        $this->customer(['tags' => '#m']);
        $this->customer(['tags' => '#w']);
        $this->customer([]);
        $this->customer(['tags' => '#m', 'active' => false]);

        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('Kunden gesamt')->assertSee('Aktive Kunden nach Tags')
            ->assertSee(e(route('customers.index', ['status' => 'active', 'tag' => '#m'])), false)
            ->assertSee(route('customers.index', ['status' => 'inactive']), false)
            ->assertSee('ohne Tag')
            ->assertViewHas('customerStats', fn (array $c) => $c['total'] === 5 && $c['active'] === 4 && $c['inactive'] === 1
                && $c['active_pct'] === 80 && $c['untagged'] === 1
                && $c['tags'] === ['#m' => 2, '#w' => 1, '#xyz' => 1]);
    }

    public function test_dashboard_customer_card_limits_the_tag_list(): void
    {
        foreach (range(1, 10) as $i) {
            $this->customer(['tags' => '#t'.$i]);
        }

        $this->actAs($this->admin)->get(route('dashboard'))
            ->assertViewHas('customerStats', fn (array $c) => count($c['tags']) === 8 && $c['more'] === 2);
    }
}
