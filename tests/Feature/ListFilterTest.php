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
use App\Models\Customer;
use App\Models\RecurringInvoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RecurringService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Filter in den Listen Kunden, Artikel und Wiederkehrend. */
class ListFilterTest extends TestCase
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
        $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);
    }

    private function customer(string $number, array $attributes = []): Customer
    {
        return Customer::create(array_merge([
            'customer_number' => $number, 'company' => 'Firma '.$number.' GmbH', 'street' => 'Weg 1',
            'zip' => '33378', 'city' => 'Rheda', 'country' => 'DE', 'currency' => 'EUR',
            'email' => 'kunde'.$number.'@example.com',
        ], $attributes));
    }

    // ---- Kunden ----

    public function test_customers_filter_by_kind(): void
    {
        $this->customer('1');
        $this->customer('2', ['company' => '', 'first_name' => 'Max', 'last_name' => 'Muster']);
        // Firma mit Ansprechpartner ist keine Privatperson.
        $this->customer('3', ['first_name' => 'Erika', 'last_name' => 'Muster']);

        $this->get(route('customers.index', ['kind' => 'private']))
            ->assertSee('Max Muster')->assertDontSee('Firma 1 GmbH')->assertDontSee('Firma 3 GmbH');
        $this->get(route('customers.index', ['kind' => 'company']))
            ->assertSee('Firma 1 GmbH')->assertSee('Firma 3 GmbH')->assertDontSee('Max Muster');
    }

    public function test_customers_filter_without_tag(): void
    {
        $this->customer('1', ['tags' => 'vip']);
        $this->customer('2', ['tags' => null]);
        $this->customer('3', ['tags' => '  ']);

        $this->get(route('customers.index', ['tag' => '__none']))
            ->assertSee('Firma 2 GmbH')->assertSee('Firma 3 GmbH')->assertDontSee('Firma 1 GmbH');
        $this->get(route('customers.index', ['tag' => 'vip']))
            ->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
    }

    public function test_dashboard_untagged_row_links_to_the_no_tag_filter(): void
    {
        $this->customer('1', ['tags' => 'vip']);
        $this->customer('2', ['tags' => null]);

        $this->get(route('dashboard'))->assertSee('tag=__none', false);
    }

    public function test_customers_filter_by_customer_until_range(): void
    {
        $this->customer('1', ['customer_until' => '2026-03-31']);
        $this->customer('2', ['customer_until' => '2026-12-31']);
        $this->customer('3', ['customer_until' => '2027-01-01']);
        $this->customer('4', ['customer_until' => null]);

        // Ganzes Jahr, Grenzen inklusive; Kunden ohne Enddatum erscheinen dann nicht.
        $this->get(route('customers.index', ['until_from' => '2026-01-01', 'until_to' => '2026-12-31']))
            ->assertSee('Firma 1 GmbH')->assertSee('Firma 2 GmbH')->assertDontSee('Firma 3 GmbH')->assertDontSee('Firma 4 GmbH');
        // Nur eine Grenze.
        $this->get(route('customers.index', ['until_from' => '2026-12-31']))
            ->assertSee('Firma 2 GmbH')->assertSee('Firma 3 GmbH')->assertDontSee('Firma 1 GmbH');
        $this->get(route('customers.index', ['until_to' => '2026-03-31']))
            ->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
        // Ungültiges Datum wird ignoriert.
        $this->get(route('customers.index', ['until_from' => 'morgen']))->assertSee('Firma 4 GmbH');
        // Schnellwahl „Dieses Jahr“ im Menü: Januar bis Dezember des laufenden Jahres.
        $this->get(route('customers.index'))->assertSee('until_from=2026-01-01', false)->assertSee('until_to=2026-12-31', false);
    }

    public function test_customers_until_quick_filters_and_button_label(): void
    {
        // heute: 11.10.2026
        $this->customer('1', ['customer_until' => '2026-10-10']);
        $this->customer('2', ['customer_until' => '2026-10-11']);
        $this->customer('3', ['customer_until' => '2026-12-31']);
        $this->customer('4', ['customer_until' => null]);

        $this->get(route('customers.index', ['until' => 'expired']))
            ->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH')->assertDontSee('Firma 3 GmbH')->assertDontSee('Firma 4 GmbH');
        $this->get(route('customers.index', ['until' => 'none']))
            ->assertSee('Firma 4 GmbH')->assertDontSee('Firma 1 GmbH')->assertDontSee('Firma 3 GmbH');
        $this->get(route('customers.index', ['until' => 'bogus']))->assertSee('Firma 4 GmbH')->assertSee('Firma 1 GmbH');

        // Der Knopf zeigt die Auswahl; ohne Filter nur die Bezeichnung und kein „zurücksetzen“-Kreuz.
        $this->get(route('customers.index'))->assertDontSee('dd-x', false);
        $this->get(route('customers.index', ['until_from' => '2026-01-01', 'until_to' => '2026-12-31']))
            ->assertSee('Kunde bis: 2026', false)->assertSee('dd-x', false);
        $this->get(route('customers.index', ['until_from' => '2026-03-01', 'until_to' => '2026-05-31']))
            ->assertSee('Kunde bis: 01.03.2026–31.05.2026', false);
        $this->get(route('customers.index', ['until' => 'none']))->assertSee('Kunde bis: Ohne Enddatum', false);
    }

    public function test_customers_search_covers_name_street_and_zip(): void
    {
        $this->customer('1', ['first_name' => 'Hubertus', 'last_name' => 'Zobel', 'street' => 'Lindenallee 9', 'zip' => '48143']);
        $this->customer('2');

        foreach (['Hubertus', 'Zobel', 'Lindenallee', '48143'] as $term) {
            $this->get(route('customers.index', ['q' => $term]))->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
        }
    }

    public function test_customers_filter_by_country_currency_and_reference(): void
    {
        $this->customer('1', ['country' => 'AT', 'currency' => 'CHF', 'buyer_reference' => '04011000-12345-34']);
        $this->customer('2');

        $this->get(route('customers.index', ['country' => 'AT']))->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
        $this->get(route('customers.index', ['currency' => 'CHF']))->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
        $this->get(route('customers.index', ['ref' => '1']))->assertSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
        $this->get(route('customers.index', ['country' => 'DE', 'currency' => 'CHF']))
            ->assertDontSee('Firma 1 GmbH')->assertDontSee('Firma 2 GmbH');
    }

    public function test_unknown_filter_values_are_ignored_and_reset_link_shows_only_when_filtered(): void
    {
        $this->customer('1');

        $this->get(route('customers.index', ['country' => 'ZZ', 'kind' => 'x']))->assertSee('Firma 1 GmbH')
            ->assertDontSee(__('messages.filter.reset'));
        $this->get(route('customers.index', ['kind' => 'company']))->assertSee(__('messages.filter.reset'));
    }

    public function test_filters_survive_pagination(): void
    {
        $this->admin->update(['per_page' => 10]);
        for ($i = 1; $i <= 30; $i++) {
            $this->customer((string) (100 + $i), ['country' => 'AT']);
        }
        $this->customer('999');

        $html = $this->get(route('customers.index', ['country' => 'AT']))->getContent();
        $this->assertStringContainsString('country=AT', $html);
        $this->assertStringContainsString('page=2', $html);
        $this->assertStringNotContainsString('Firma 999 GmbH', $html);
    }

    public function test_customer_filter_does_not_leak_other_tenants(): void
    {
        $other = $this->makeTenant('Andere GmbH');
        $this->makeCustomer($other, '777', ['company' => 'Fremdkunde AG', 'country' => 'CH']);
        Tenancy::set($this->tenant->id);

        $this->get(route('customers.index'))->assertDontSee('Fremdkunde AG');
        // Auch das Länder-Auswahlfeld verrät nichts aus anderen Mandanten.
        $this->get(route('customers.index'))->assertDontSee('value="CH"', false);
    }

    // ---- Artikel ----

    public function test_articles_filter_by_status_tax_unit_and_missing_price(): void
    {
        $a = Article::create(['article_number' => 'A1', 'name' => 'Alpha', 'unit' => 'Stunde', 'tax_rate' => 19, 'active' => true]);
        $a->prices()->create(['currency' => 'EUR', 'amount' => '90', 'valid_from' => '2026-01-01']);
        Article::create(['article_number' => 'B1', 'name' => 'Beta', 'unit' => 'Stück', 'tax_rate' => 7, 'active' => false]);
        // Preis erst in der Zukunft: gilt heute noch nicht.
        $c = Article::create(['article_number' => 'C1', 'name' => 'Gamma', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);
        $c->prices()->create(['currency' => 'EUR', 'amount' => '5', 'valid_from' => '2027-01-01']);

        $this->get(route('articles.index', ['status' => 'inactive']))->assertSee('Beta')->assertDontSee('Alpha')->assertDontSee('Gamma');
        $this->get(route('articles.index', ['status' => 'active']))->assertSee('Alpha')->assertSee('Gamma')->assertDontSee('Beta');
        $this->get(route('articles.index', ['tax' => '7']))->assertSee('Beta')->assertDontSee('Alpha');
        $this->get(route('articles.index', ['unit' => 'Stunde']))->assertSee('Alpha')->assertDontSee('Beta')->assertDontSee('Gamma');
        $this->get(route('articles.index', ['price' => 'none']))->assertSee('Beta')->assertSee('Gamma')->assertDontSee('Alpha');
    }

    public function test_article_search_includes_description(): void
    {
        Article::create(['name' => 'Alpha', 'description' => 'Wartung im Rechenzentrum', 'unit' => 'Stück', 'tax_rate' => 19]);
        Article::create(['name' => 'Beta', 'unit' => 'Stück', 'tax_rate' => 19]);

        $this->get(route('articles.index', ['q' => 'Rechenzentrum']))->assertSee('Alpha')->assertDontSee('Beta');
    }

    // ---- Wiederkehrend ----

    private function recurring(string $name, array $attributes = []): RecurringInvoice
    {
        $customer = $attributes['customer'] ?? ($this->tenantCustomer ??= $this->customer('500', ['company' => 'Dauerkunde GmbH']));
        unset($attributes['customer']);

        return RecurringInvoice::create(array_merge([
            'name' => $name, 'customer_id' => $customer->id, 'active' => true, 'interval' => 'monthly',
            'billing' => 'advance', 'anchor_date' => '2026-11-01', 'next_date' => '2026-11-01',
            'currency' => 'EUR', 'tax_mode' => 'standard',
        ], $attributes));
    }

    private ?Customer $tenantCustomer = null;

    public function test_recurring_filter_by_state(): void
    {
        $this->recurring('Aktive Vorlage');
        $this->recurring('Pausierte Vorlage', ['active' => false]);
        $this->recurring('Beendete Vorlage', ['end_date' => '2026-12-31', 'next_date' => '2027-01-01']);

        $this->get(route('recurring.index', ['state' => 'active']))
            ->assertSee('Aktive Vorlage')->assertDontSee('Pausierte Vorlage')->assertDontSee('Beendete Vorlage');
        $this->get(route('recurring.index', ['state' => 'paused']))
            ->assertSee('Pausierte Vorlage')->assertDontSee('Aktive Vorlage')->assertDontSee('Beendete Vorlage');
        $this->get(route('recurring.index', ['state' => 'finished']))
            ->assertSee('Beendete Vorlage')->assertDontSee('Aktive Vorlage')->assertDontSee('Pausierte Vorlage');
    }

    public function test_recurring_filter_by_interval_billing_and_search(): void
    {
        $other = $this->customer('501', ['company' => 'Quartalskunde AG']);
        $this->recurring('Monatlich');
        $this->recurring('Quartalsweise', ['interval' => 'quarterly', 'billing' => 'arrears', 'customer' => $other]);

        $this->get(route('recurring.index', ['interval' => 'quarterly']))->assertSee('Quartalsweise')->assertDontSee('Monatlich');
        $this->get(route('recurring.index', ['billing' => 'arrears']))->assertSee('Quartalsweise')->assertDontSee('Monatlich');
        $this->get(route('recurring.index', ['q' => 'Quartalskunde']))->assertSee('Quartalsweise')->assertDontSee('Monatlich');
        $this->get(route('recurring.index', ['q' => 'Monat']))->assertSee('Monatlich')->assertDontSee('Quartalsweise');
    }

    public function test_recurring_filter_by_open_drafts(): void
    {
        $with = $this->recurring('Mit Entwurf', ['next_date' => '2026-10-01', 'anchor_date' => '2026-10-01']);
        $with->items()->create([
            'position' => 1, 'description' => 'Wartung', 'quantity' => 1, 'unit' => 'pauschal',
            'unit_price' => 100, 'tax_rate' => 19,
        ]);
        $this->recurring('Ohne Entwurf');
        app(RecurringService::class)->generate($with, $this->admin, now());
        $this->assertSame(1, $with->invoices()->count());

        $this->get(route('recurring.index', ['drafts' => '1']))->assertSee('Mit Entwurf')->assertDontSee('Ohne Entwurf');
        $this->get(route('recurring.index'))->assertSee('Mit Entwurf')->assertSee('Ohne Entwurf');
    }
}
