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

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RecurringService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    private User $clerk;

    private User $viewer;

    private \App\Models\Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'admin');
        $this->accounting = $this->makeUser($this->tenant, 'accounting');
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
        $this->viewer = $this->makeUser($this->tenant, 'viewer');
        $this->customer = $this->makeCustomer($this->tenant);
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Wartung Server', 'customer_id' => $this->customer->id, 'active' => 1,
            'interval' => 'monthly', 'billing' => 'advance', 'next_date' => '2026-11-01', 'end_date' => '',
            'currency' => 'EUR', 'tax_mode' => 'standard',
            'intro_text' => 'Leistungszeitraum {zeitraum}',
            'items' => [
                ['article_id' => '', 'description' => 'Wartung {monat}', 'quantity' => '1', 'unit' => 'pauschal',
                    'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19'],
            ],
        ], $overrides);
    }

    private function template(array $overrides = []): RecurringInvoice
    {
        $this->actAs($this->accounting)->post(route('recurring.store'), $this->formData($overrides))->assertSessionHasNoErrors();

        return RecurringInvoice::latest('id')->firstOrFail();
    }

    private function dates(RecurringInvoice $r, int $k): string
    {
        [$from, $to] = $r->periodFor($k);

        return $from->toDateString().' '.$to->toDateString();
    }

    public function test_periods_in_advance_and_in_arrears(): void
    {
        $advance = new RecurringInvoice(['interval' => 'monthly', 'billing' => 'advance', 'anchor_date' => '2026-11-01']);
        $this->assertSame('2026-11-01 2026-11-30', $this->dates($advance, 0));
        $this->assertSame('2026-12-01 2026-12-31', $this->dates($advance, 1));

        $arrears = new RecurringInvoice(['interval' => 'monthly', 'billing' => 'arrears', 'anchor_date' => '2026-12-01']);
        $this->assertSame('2026-11-01 2026-11-30', $this->dates($arrears, 0));
        $this->assertSame('2026-12-01 2026-12-31', $this->dates($arrears, 1));

        $quarter = new RecurringInvoice(['interval' => 'quarterly', 'billing' => 'advance', 'anchor_date' => '2026-10-01']);
        $this->assertSame('2026-10-01 2026-12-31', $this->dates($quarter, 0));
        $this->assertSame('2027-01-01 2027-03-31', $this->dates($quarter, 1));

        $year = new RecurringInvoice(['interval' => 'yearly', 'billing' => 'advance', 'anchor_date' => '2027-01-01']);
        $this->assertSame('2027-01-01 2027-12-31', $this->dates($year, 0));

        $yearArrears = new RecurringInvoice(['interval' => 'yearly', 'billing' => 'arrears', 'anchor_date' => '2027-01-01']);
        $this->assertSame('2026-01-01 2026-12-31', $this->dates($yearArrears, 0));
    }

    public function test_due_dates_do_not_drift_at_month_end(): void
    {
        $r = new RecurringInvoice(['interval' => 'monthly', 'billing' => 'advance', 'anchor_date' => '2026-01-31']);

        $this->assertSame('2026-01-31', $r->occurrenceDate(0)->toDateString());
        $this->assertSame('2026-02-28', $r->occurrenceDate(1)->toDateString());
        $this->assertSame('2026-03-31', $r->occurrenceDate(2)->toDateString());
    }

    public function test_placeholders_are_filled_in_german(): void
    {
        $from = Carbon::parse('2026-11-01');
        $to = Carbon::parse('2026-11-30');

        $this->assertSame(
            'Wartung November 2026 (01.11.2026 – 30.11.2026) Q4 2026 / 2026, von 01.11.2026 bis 30.11.2026 {unbekannt}',
            RecurringService::fill('Wartung {monat} ({zeitraum}) {quartal} / {jahr}, von {zeitraum_von} bis {zeitraum_bis} {unbekannt}', $from, $to)
        );
        $this->assertNull(RecurringService::fill(null, $from, $to));
    }

    public function test_saving_a_template_via_the_form(): void
    {
        $r = $this->template();

        $this->assertTrue($r->active);
        $this->assertSame('2026-11-01', $r->next_date->toDateString());
        $this->assertSame('2026-11-01', $r->anchor_date->toDateString());
        $this->assertSame(0, $r->occurrence);
        $this->assertCount(1, $r->items);
        $this->assertSame('100.0000', (string) $r->items[0]->unit_price);

        $this->get(route('recurring.edit', $r))->assertOk()->assertSee('Wartung Server')->assertSee('Wartung {monat}', false);
        $this->get(route('recurring.index'))->assertOk()->assertSee('Wartung Server');
    }

    public function test_a_due_template_creates_a_draft_with_the_regular_due_date_and_period(): void
    {
        $r = $this->template(['next_date' => '2026-10-01']);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $invoice = Invoice::sole();
        $this->assertTrue($invoice->isDraft());
        $this->assertSame($r->id, $invoice->recurring_invoice_id);
        $this->assertSame('2026-10-01', $invoice->issue_date->toDateString());
        $this->assertSame('2026-10-01', $invoice->service_date_from->toDateString());
        $this->assertSame('2026-10-31', $invoice->service_date_to->toDateString());
        $this->assertSame('Leistungszeitraum 01.10.2026 – 31.10.2026', $invoice->intro_text);
        $this->assertSame('Wartung Oktober 2026', $invoice->items->first()->description);
        $this->assertSame('119.00', $invoice->gross_total);
        $this->assertStringContainsString('Wartung Server', (string) $invoice->internal_notes);

        $r->refresh();
        $this->assertSame(1, $r->occurrence);
        $this->assertSame('2026-11-01', $r->next_date->toDateString());
        $this->assertNotNull($r->last_generated_at);
        $this->assertSame(1, AuditLog::where('action', 'recurring.generated')->count());

        // Zweiter Lauf am selben Tag erzeugt nichts mehr.
        $this->artisan('recurring:generate')->assertSuccessful();
        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_missed_dates_are_caught_up_with_their_regular_dates(): void
    {
        $this->template(['next_date' => '2026-07-15']);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $this->assertSame(
            ['2026-07-15', '2026-08-15', '2026-09-15'],
            Invoice::orderBy('issue_date')->get()->map(fn ($i) => $i->issue_date->toDateString())->all()
        );
        $this->assertSame(
            ['Wartung Juli 2026', 'Wartung August 2026', 'Wartung September 2026'],
            Invoice::orderBy('issue_date')->get()->map(fn ($i) => $i->items->first()->description)->all()
        );
        // Der 15.10. liegt noch in der Zukunft.
        $this->assertSame('2026-10-15', RecurringInvoice::sole()->next_date->toDateString());
    }

    public function test_arrears_billing_uses_the_period_that_just_ended(): void
    {
        $this->template(['billing' => 'arrears', 'next_date' => '2026-10-01']);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $invoice = Invoice::sole();
        $this->assertSame('2026-10-01', $invoice->issue_date->toDateString());
        $this->assertSame('2026-09-01', $invoice->service_date_from->toDateString());
        $this->assertSame('2026-09-30', $invoice->service_date_to->toDateString());
        $this->assertSame('Wartung September 2026', $invoice->items->first()->description);
    }

    public function test_paused_future_and_ended_templates_create_nothing(): void
    {
        $this->template(['name' => 'pausiert', 'active' => 0, 'next_date' => '2026-09-01']);
        $this->template(['name' => 'zukünftig', 'next_date' => '2026-12-01']);
        $this->template(['name' => 'beendet', 'next_date' => '2026-09-01', 'end_date' => '2026-08-31']);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $this->assertSame(0, Invoice::count());
        $this->assertSame('finished', RecurringInvoice::where('name', 'beendet')->first()->state());
        $this->assertSame('paused', RecurringInvoice::where('name', 'pausiert')->first()->state());
    }

    public function test_end_date_stops_after_the_last_period(): void
    {
        $this->template(['next_date' => '2026-08-01', 'end_date' => '2026-09-30']);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $this->assertSame(2, Invoice::count());
        $this->assertSame('finished', RecurringInvoice::sole()->state());

        // Enddatum später setzen: die Vorlage läuft weiter.
        $r = RecurringInvoice::sole();
        $this->put(route('recurring.update', $r), $this->formData(['next_date' => '2026-10-01', 'end_date' => '']))->assertSessionHasNoErrors();
        $this->artisan('recurring:generate')->assertSuccessful();
        Tenancy::set($this->tenant->id);
        $this->assertSame(3, Invoice::count());
    }

    public function test_catalogue_articles_use_the_price_valid_at_the_due_date_free_positions_keep_theirs(): void
    {
        Tenancy::set($this->tenant->id);
        $article = Article::create(['name' => 'Support', 'unit' => 'Stunde', 'tax_rate' => 19]);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '90', 'valid_from' => '2026-01-01']);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '99', 'valid_from' => '2026-10-01']);

        $this->template([
            'next_date' => '2026-08-01',
            'items' => [
                ['article_id' => $article->id, 'description' => 'Support {monat}', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '50,00', 'discount_percent' => '', 'tax_rate' => '19'],
                ['article_id' => '', 'description' => 'Freie Position', 'quantity' => '1', 'unit' => 'pauschal', 'unit_price' => '30,00', 'discount_percent' => '', 'tax_rate' => '19'],
            ],
        ]);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $prices = Invoice::orderBy('issue_date')->get()->map(fn ($i) => $i->items->map(fn ($it) => (string) $it->unit_price)->all())->all();

        // August, September: 90; Oktober: 99 (Preiserhöhung zum 01.10.). Freie Position immer 30.
        $this->assertSame([
            ['90.0000', '30.0000'], ['90.0000', '30.0000'], ['99.0000', '30.0000'],
        ], $prices);
    }

    public function test_article_without_a_price_falls_back_to_the_template_price(): void
    {
        Tenancy::set($this->tenant->id);
        $article = Article::create(['name' => 'Ohne Preis', 'unit' => 'Stück', 'tax_rate' => 19]);

        $this->template(['next_date' => '2026-10-01', 'items' => [
            ['article_id' => $article->id, 'description' => 'X', 'quantity' => '1', 'unit' => 'Stück', 'unit_price' => '42,00', 'discount_percent' => '', 'tax_rate' => '19'],
        ]]);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $this->assertSame('42.0000', (string) Invoice::sole()->items->first()->unit_price);
    }

    public function test_changing_the_due_date_or_interval_recalculates_from_the_new_date(): void
    {
        $r = $this->template(['next_date' => '2026-10-01']);
        $this->artisan('recurring:generate')->assertSuccessful();
        $this->actAs($this->accounting);

        $this->put(route('recurring.update', $r), $this->formData(['next_date' => '2027-01-15', 'interval' => 'quarterly']))->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertSame('2027-01-15', $r->anchor_date->toDateString());
        $this->assertSame(0, $r->occurrence);
        $this->assertSame('2027-01-15', $r->next_date->toDateString());
        $this->assertSame('2027-04-15', $r->occurrenceDate(1)->toDateString());

        // Nur Texte ändern: der Zähler bleibt.
        $this->artisan('recurring:generate');
        $this->put(route('recurring.update', $r), $this->formData(['next_date' => '2027-01-15', 'interval' => 'quarterly', 'name' => 'Neu']))->assertSessionHasNoErrors();
        $this->assertSame('Neu', $r->fresh()->name);
        $this->assertSame('2027-01-15', $r->fresh()->anchor_date->toDateString());
    }

    public function test_run_button_creates_due_drafts_now(): void
    {
        $this->template(['next_date' => '2026-10-01']);

        $this->post(route('recurring.run'))->assertRedirect(route('recurring.index'))->assertSessionHas('status');

        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_a_recurring_invoice_can_be_made_from_an_issued_invoice_and_starts_paused(): void
    {
        $this->actAs($this->accounting)->post('/invoices', [
            'type' => 'invoice', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11',
            'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [['article_id' => '', 'description' => 'Beratung Oktober 2026', 'quantity' => '2', 'unit' => 'Stunde',
                'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19']],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::latest('id')->firstOrFail();
        $this->post(route('invoices.issue', $invoice))->assertRedirect();

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(__('messages.recurring.make_recurring'));

        $response = $this->post(route('invoices.recurring', $invoice));
        $r = RecurringInvoice::sole();
        $response->assertRedirect(route('recurring.edit', $r));

        $this->assertFalse($r->active);
        $this->assertSame('2026-11-11', $r->next_date->toDateString());
        $this->assertCount(1, $r->items);
        $this->assertSame('Beratung Oktober 2026', $r->items[0]->description);
        $this->assertSame($this->customer->id, $r->customer_id);

        // Pausiert: nichts wird erzeugt, die Rechnung selbst bleibt unverändert.
        $this->artisan('recurring:generate')->assertSuccessful();
        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    public function test_credit_notes_cannot_become_recurring(): void
    {
        $this->actAs($this->accounting);
        $credit = Invoice::create([
            'type' => 'credit_note', 'status' => 'draft', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11',
            'currency' => 'EUR', 'tax_mode' => 'standard',
        ]);

        $this->post(route('invoices.recurring', $credit))->assertSessionHas('warning');
        $this->assertSame(0, RecurringInvoice::count());
    }

    public function test_generated_invoice_page_shows_the_source_and_period(): void
    {
        $r = $this->template(['next_date' => '2026-10-01']);
        $this->artisan('recurring:generate')->assertSuccessful();
        $this->actAs($this->accounting);

        $invoice = Invoice::sole();
        $this->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Wartung Server')->assertSee('01.10.2026 – 31.10.2026');

        $this->get(route('recurring.edit', $r))->assertSee('01.10.2026 – 31.10.2026', false);
        $this->get(route('recurring.index'))->assertOk();
    }

    public function test_deleting_the_template_keeps_the_invoices(): void
    {
        $r = $this->template(['next_date' => '2026-10-01']);
        $this->artisan('recurring:generate')->assertSuccessful();
        $this->actAs($this->accounting);

        $this->delete(route('recurring.destroy', $r))->assertRedirect(route('recurring.index'));

        $this->assertSame(0, RecurringInvoice::count());
        $this->assertSame(0, \App\Models\RecurringInvoiceItem::count());
        $this->assertSame(1, Invoice::count());
        $this->assertNull(Invoice::sole()->recurring_invoice_id);
    }

    public function test_a_deleted_customer_stops_only_that_template(): void
    {
        $other = $this->makeCustomer($this->tenant, '10002');
        $this->template(['name' => 'kaputt', 'next_date' => '2026-10-01']);
        $this->template(['name' => 'gut', 'customer_id' => $other->id, 'next_date' => '2026-10-01']);

        Tenancy::set($this->tenant->id);
        $this->customer->delete();

        $this->artisan('recurring:generate')->assertFailed();

        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Invoice::count());
        $this->assertSame($other->id, Invoice::sole()->customer_id);
        $this->assertSame('2026-10-01', RecurringInvoice::where('name', 'kaputt')->first()->next_date->toDateString());
    }

    public function test_roles(): void
    {
        $r = $this->template();

        foreach ([$this->viewer] as $user) {
            $this->actAs($user);
            $this->get(route('recurring.index'))->assertOk();
            $this->get(route('recurring.create'))->assertForbidden();
            $this->post(route('recurring.store'), $this->formData())->assertForbidden();
            $this->put(route('recurring.update', $r), $this->formData())->assertForbidden();
            $this->delete(route('recurring.destroy', $r))->assertForbidden();
            $this->post(route('recurring.run'))->assertForbidden();
        }

        $this->actAs($this->clerk);
        $this->get(route('recurring.create'))->assertOk();
        $this->post(route('recurring.store'), $this->formData(['name' => 'Vom Sachbearbeiter']))->assertSessionHasNoErrors();
        $this->assertSame(2, RecurringInvoice::count());
    }

    public function test_tenants_are_isolated(): void
    {
        $r = $this->template(['next_date' => '2026-10-01']);

        $other = $this->makeTenant('Andere GmbH');
        $otherAdmin = $this->makeUser($other, 'admin');
        $otherCustomer = $this->makeCustomer($other, '20001');

        $this->actingAs($otherAdmin)->withSession(['tenant_id' => $other->id]);
        Tenancy::set($other->id);
        $this->get(route('recurring.edit', $r))->assertNotFound();
        $this->put(route('recurring.update', $r), $this->formData(['customer_id' => $otherCustomer->id]))->assertNotFound();
        $this->delete(route('recurring.destroy', $r))->assertNotFound();
        $this->get(route('recurring.index'))->assertOk()->assertDontSee('Wartung Server');

        // Fremder Kunde im eigenen Formular wird abgelehnt.
        $this->actAs($this->accounting);
        $this->post(route('recurring.store'), $this->formData(['customer_id' => $otherCustomer->id]))->assertSessionHasErrors('customer_id');

        // Der Lauf legt Entwürfe nur im Mandanten der Vorlage an.
        $this->artisan('recurring:generate')->assertSuccessful();
        Tenancy::set($other->id);
        $this->assertSame(0, Invoice::count());
        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_validation(): void
    {
        $this->actAs($this->accounting);

        $this->post(route('recurring.store'), $this->formData(['name' => '', 'interval' => 'weekly', 'billing' => 'x', 'items' => []]))
            ->assertSessionHasErrors(['name', 'interval', 'billing', 'items']);
        $this->assertSame(0, RecurringInvoice::count());
    }

    public function test_test_mode_drafts_are_marked_as_test(): void
    {
        $this->tenant->update(['test_mode' => true]);
        $this->template(['next_date' => '2026-10-01']);

        $this->artisan('recurring:generate')->assertSuccessful();

        Tenancy::set($this->tenant->id);
        $this->assertTrue((bool) Invoice::sole()->is_test);
    }

    public function test_the_list_shows_the_gross_invoice_amount_of_each_template(): void
    {
        $this->template(['name' => 'Klein']);
        $this->template(['name' => 'Gross', 'discount_percent' => '10', 'items' => [
            ['article_id' => '', 'description' => 'Projekt', 'quantity' => '2', 'unit' => 'Stk',
                'unit_price' => '1.000,00', 'discount_percent' => '', 'tax_rate' => '19'],
        ]]);
        $this->template(['name' => 'Verein', 'tax_mode' => 'export']);

        $this->actAs($this->viewer)->get(route('recurring.index'))->assertOk()
            ->assertSee('sort=amount', false)
            ->assertSee(e(__('messages.recurring.amount_tip')), false)
            ->assertSee('119,00', false)      // 100 netto + 19 %
            ->assertSee('2.142,00', false)    // 2 × 1.000 − 10 % = 1.800 netto + 19 % = 2.142 brutto
            ->assertSee('100,00', false);     // Steuerfall ohne Umsatzsteuer
    }

    public function test_the_list_can_be_sorted_by_amount_in_both_directions(): void
    {
        $this->template(['name' => 'Klein']);
        $this->template(['name' => 'Gross', 'items' => [
            ['article_id' => '', 'description' => 'Projekt', 'quantity' => '5', 'unit' => 'Stk',
                'unit_price' => '1.000,00', 'discount_percent' => '', 'tax_rate' => '19'],
        ]]);

        $this->actAs($this->viewer)->get(route('recurring.index', ['sort' => 'amount', 'dir' => 'asc']))
            ->assertOk()->assertSeeInOrder(['Klein', 'Gross']);
        $this->get(route('recurring.index', ['sort' => 'amount', 'dir' => 'desc']))
            ->assertOk()->assertSeeInOrder(['Gross', 'Klein']);
    }

    public function test_menu_contains_the_recurring_entry(): void
    {
        $this->actAs($this->viewer)->get(route('dashboard'))->assertOk()->assertSee(route('recurring.index'), false);
    }
}
