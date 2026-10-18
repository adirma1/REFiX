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
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Sortierung per Klick auf den Spaltenkopf: serverseitig, mit gemerkter Wahl je Benutzer und Liste. */
class ListSortTest extends TestCase
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

    private function customer(string $number, string $company): void
    {
        $this->makeCustomer($this->tenant, $number, ['company' => $company]);
        Tenancy::set($this->tenant->id);
    }

    /** Die Kundennummern in der Reihenfolge, wie sie in der Antwort erscheinen. */
    private function order($response, array $names): array
    {
        $html = $response->getContent();
        $positions = [];
        foreach ($names as $name) {
            $pos = strpos($html, $name);
            $this->assertNotFalse($pos, $name.' fehlt in der Antwort');
            $positions[$name] = $pos;
        }
        asort($positions);

        return array_keys($positions);
    }

    public function test_click_toggles_between_ascending_and_descending(): void
    {
        $this->customer('1', 'Alpha GmbH');
        $this->customer('2', 'Zeta GmbH');
        $this->customer('3', 'Mitte GmbH');
        $names = ['Alpha GmbH', 'Mitte GmbH', 'Zeta GmbH'];

        $asc = $this->get(route('customers.index', ['sort' => 'company', 'dir' => 'asc']));
        $this->assertSame($names, $this->order($asc, $names));
        // Der Kopf der aktiven Spalte verweist jetzt auf die Gegenrichtung.
        $asc->assertSee('sort=company&amp;dir=desc', false)->assertSee('aria-sort="ascending"', false);

        $desc = $this->get(route('customers.index', ['sort' => 'company', 'dir' => 'desc']));
        $this->assertSame(array_reverse($names), $this->order($desc, $names));
        $desc->assertSee('sort=company&amp;dir=asc', false)->assertSee('aria-sort="descending"', false);
    }

    public function test_sorting_spans_all_pages(): void
    {
        $this->admin->forceFill(['per_page' => 10])->save();

        // 12 Kunden: Nummer aufsteigend, Name absteigend – der letzte Name steht auf Seite 1 der absteigenden Sortierung.
        foreach (range(1, 12) as $i) {
            $this->customer((string) $i, 'Firma '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $this->get(route('customers.index', ['sort' => 'company', 'dir' => 'desc']))
            ->assertSee('Firma 12')->assertSee('Firma 03')->assertDontSee('Firma 02')->assertDontSee('Firma 01');
    }

    public function test_choice_is_remembered_per_user_and_list(): void
    {
        $this->customer('1', 'Alpha GmbH');
        $this->customer('2', 'Zeta GmbH');
        $names = ['Alpha GmbH', 'Zeta GmbH'];

        $this->get(route('customers.index', ['sort' => 'company', 'dir' => 'desc']))->assertOk();

        $stored = json_decode((string) $this->admin->fresh()->list_sorts, true);
        $this->assertSame(['sort' => 'company', 'dir' => 'desc'], $stored['customers']);

        // Ohne Angabe in der URL gilt die gemerkte Sortierung.
        $this->assertSame(array_reverse($names), $this->order($this->get(route('customers.index')), $names));

        // Andere Liste bleibt unberührt, anderer Benutzer ebenfalls.
        $this->assertArrayNotHasKey('invoices', $stored);
        $other = $this->makeUser($this->tenant, 'admin');
        $this->actingAs($other);
        $this->assertSame($names, $this->order($this->get(route('customers.index')), $names));
    }

    public function test_unknown_columns_and_directions_are_ignored(): void
    {
        $this->customer('1', 'Alpha GmbH');

        $this->get(route('customers.index', ['sort' => 'password; drop table users', 'dir' => 'sideways']))
            ->assertOk()->assertSee('Alpha GmbH');
        $this->assertNull($this->admin->fresh()->list_sorts);

        // Unbekannte Richtung bei gültiger Spalte → aufsteigend.
        $this->get(route('customers.index', ['sort' => 'company', 'dir' => 'sideways']))->assertOk();
        $stored = json_decode((string) $this->admin->fresh()->list_sorts, true);
        $this->assertSame('asc', $stored['customers']['dir']);
    }

    public function test_sort_link_keeps_filters_and_resets_page(): void
    {
        $this->customer('1', 'Alpha GmbH');

        $this->get(route('customers.index', ['q' => 'Alpha', 'page' => 3]))
            ->assertSee('q=Alpha&amp;sort=company&amp;dir=asc', false)
            ->assertDontSee('page=3&amp;sort', false);
    }

    public function test_invoices_sort_by_customer_name_via_subquery(): void
    {
        $zeta = $this->makeCustomer($this->tenant, '1', ['company' => 'Zeta AG']);
        $alpha = $this->makeCustomer($this->tenant, '2', ['company' => 'Alpha AG']);
        Tenancy::set($this->tenant->id);

        foreach ([$zeta, $alpha] as $customer) {
            Invoice::create([
                'type' => Invoice::TYPE_INVOICE, 'status' => Invoice::STATUS_DRAFT, 'customer_id' => $customer->id,
                'issue_date' => '2026-10-01', 'currency' => 'EUR', 'tax_mode' => 'standard',
            ]);
        }

        $names = ['Alpha AG', 'Zeta AG'];
        $this->assertSame($names, $this->order($this->get(route('invoices.index', ['sort' => 'customer', 'dir' => 'asc'])), $names));
        $this->assertSame(array_reverse($names), $this->order($this->get(route('invoices.index', ['sort' => 'customer', 'dir' => 'desc'])), $names));
    }

    public function test_every_list_page_renders_with_sorting(): void
    {
        foreach ([
            'invoices.index', 'quotes.index', 'recurring.index', 'customers.index', 'articles.index', 'payments.index',
            'dunning.index', 'bank.index', 'audit.index', 'users.index', 'tenants.index', 'mail-accounts.index',
            'numbering.index', 'backups.index', 'layouts.index',
        ] as $route) {
            $this->get(route($route, ['sort' => 'nope']))->assertOk();
            $this->get(route($route))->assertOk();
        }

        // Listen, die auch ohne Daten ihre Tabelle (mit Spaltenköpfen) zeigen.
        foreach (['invoices.index', 'quotes.index', 'customers.index', 'articles.index', 'users.index', 'tenants.index', 'numbering.index'] as $route) {
            $this->get(route($route))->assertSee('sort-link', false);
        }

        foreach (['due', 'history', 'holds'] as $tab) {
            $this->get(route('dunning.index', ['tab' => $tab, 'sort' => 'number']))->assertOk();
        }
        foreach (['new', 'booked', 'ignored'] as $tab) {
            $this->get(route('bank.index', ['tab' => $tab, 'sort' => 'amount']))->assertOk()->assertSee('sort-link', false);
        }
    }

    /** Jede Sortierspalte jeder Liste in beiden Richtungen: deckt fehlerhafte SQL-Ausdrücke (Unterabfragen) auf. */
    public function test_every_sort_column_runs_in_both_directions(): void
    {
        $lists = [
            ['invoices.index', [], ['number', 'type', 'customer', 'date', 'gross', 'status']],
            ['quotes.index', [], ['number', 'customer', 'date', 'valid_until', 'gross', 'status']],
            ['recurring.index', [], ['name', 'customer', 'interval', 'next_date', 'end_date', 'drafts', 'status']],
            ['customers.index', [], ['number', 'company', 'city', 'email', 'until', 'status']],
            ['articles.index', [], ['number', 'name', 'unit', 'tax', 'price']],
            ['payments.index', [], ['number', 'customer', 'due', 'gross', 'paid', 'open', 'status']],
            ['dunning.index', ['tab' => 'due'], ['number', 'customer', 'due', 'step', 'open', 'fee', 'total']],
            ['dunning.index', ['tab' => 'history'], ['date', 'number', 'step', 'total', 'pay_by', 'status']],
            ['dunning.index', ['tab' => 'holds'], ['number', 'reason', 'until']],
            ['bank.index', ['tab' => 'new'], ['date', 'counterparty', 'purpose', 'amount']],
            ['bank.index', ['tab' => 'booked'], ['date', 'counterparty', 'purpose', 'amount', 'number']],
            ['audit.index', [], ['time', 'user', 'action', 'details']],
            ['users.index', [], ['name', 'username', 'email', 'role', 'two_factor']],
            ['tenants.index', [], ['name', 'city', 'mode']],
            ['mail-accounts.index', [], ['name', 'server', 'from', 'bcc', 'used_by']],
            ['numbering.index', [], ['type', 'format', 'last', 'next']],
            ['backups.index', [], ['file', 'created', 'size']],
            ['layouts.index', [], ['version', 'name', 'created', 'status']],
        ];

        foreach ($lists as [$route, $params, $columns]) {
            foreach ($columns as $column) {
                foreach (['asc', 'desc'] as $dir) {
                    $this->get(route($route, $params + ['sort' => $column, 'dir' => $dir]))
                        ->assertOk();
                }
            }
        }
    }

    public function test_invoices_and_quotes_filter_by_date_range(): void
    {
        $customer = $this->makeCustomer($this->tenant, '1', ['company' => 'Muster AG']);
        Tenancy::set($this->tenant->id);

        // heute: 11.10.2026
        foreach (['2026-03-15' => 'R-MAERZ', '2026-10-05' => 'R-OKT', '2025-12-31' => 'R-ALT'] as $date => $number) {
            Invoice::create([
                'type' => Invoice::TYPE_INVOICE, 'status' => Invoice::STATUS_DRAFT, 'customer_id' => $customer->id,
                'number' => $number, 'issue_date' => $date, 'currency' => 'EUR', 'tax_mode' => 'standard',
            ]);
            \App\Models\Quote::create([
                'status' => \App\Models\Quote::STATUS_DRAFT, 'customer_id' => $customer->id,
                'number' => 'A'.substr($number, 1), 'issue_date' => $date, 'valid_until' => '2027-01-01',
                'currency' => 'EUR', 'tax_mode' => 'standard',
            ]);
        }

        foreach (['invoices.index' => 'R', 'quotes.index' => 'A'] as $route => $p) {
            // Ganzes Jahr 2026: nicht der Jahreswechsel-Eintrag von 2025
            $this->get(route($route, ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
                ->assertSee($p.'-MAERZ')->assertSee($p.'-OKT')->assertDontSee($p.'-ALT')
                ->assertSee('Datum: 2026', false);
            // Dieser Monat (Oktober 2026), Grenzen inklusive
            $this->get(route($route, ['date_from' => '2026-10-05', 'date_to' => '2026-10-31']))
                ->assertSee($p.'-OKT')->assertDontSee($p.'-MAERZ');
            // Nur eine Grenze und ungültiges Datum
            $this->get(route($route, ['date_to' => '2025-12-31']))->assertSee($p.'-ALT')->assertDontSee($p.'-OKT');
            $this->get(route($route, ['date_from' => 'gestern']))->assertSee($p.'-ALT')->assertSee($p.'-OKT');
            // Ohne Filter: kein Zurücksetzen-Kreuz, Schnellwahlen vorhanden
            $this->get(route($route))->assertDontSee('dd-x', false)
                ->assertSee('date_from=2026-10-01&amp;date_to=2026-10-31', false);
        }
    }

    public function test_quotes_filter_by_valid_until_range(): void
    {
        $customer = $this->makeCustomer($this->tenant, '1', ['company' => 'Muster AG']);
        Tenancy::set($this->tenant->id);

        foreach (['2026-10-20' => 'A-BALD', '2026-12-31' => 'A-SPAET', '2026-09-30' => 'A-VORBEI'] as $valid => $number) {
            \App\Models\Quote::create([
                'status' => \App\Models\Quote::STATUS_DRAFT, 'customer_id' => $customer->id, 'number' => $number,
                'issue_date' => '2026-09-01', 'valid_until' => $valid, 'currency' => 'EUR', 'tax_mode' => 'standard',
            ]);
        }

        $this->get(route('quotes.index', ['valid_from' => '2026-10-11', 'valid_to' => '2026-11-10']))
            ->assertSee('A-BALD')->assertDontSee('A-SPAET')->assertDontSee('A-VORBEI')->assertSee('Gültig bis: 11.10.2026–10.11.2026', false);
        $this->get(route('quotes.index', ['valid_to' => '2026-09-30']))->assertSee('A-VORBEI')->assertDontSee('A-BALD');
        // beide Zeitraum-Filter zusammen: Belegdatum und Gültigkeit
        $this->get(route('quotes.index', ['date_from' => '2026-09-01', 'valid_from' => '2026-12-01']))
            ->assertSee('A-SPAET')->assertDontSee('A-BALD');
    }
}
