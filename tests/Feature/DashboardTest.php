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
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DashboardStats;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPaymentSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesPaymentSetup, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    private User $clerk;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');

        $this->tenant = $this->makeTenant();
        $this->admin = $this->makeUser($this->tenant, 'admin');
        $this->accounting = $this->makeUser($this->tenant, 'accounting');
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
        $this->customer = $this->makeCustomer($this->tenant);
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function at(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 10:00:00'));
        Tenancy::set($this->tenant->id);
    }

    private function stats(): array
    {
        Tenancy::set($this->tenant->id);

        return app(DashboardStats::class)->compute();
    }

    public function test_empty_dashboard_renders_for_every_role(): void
    {
        foreach ([$this->admin, $this->accounting, $this->clerk, $this->makeUser($this->tenant, 'viewer')] as $user) {
            $this->actAs($user)->get(route('dashboard'))->assertOk()->assertSee(__('messages.dashboard.no_revenue'));
        }
    }

    public function test_tiles_show_open_overdue_revenue_and_payments(): void
    {
        $invoice = $this->issueInvoice($this->tenant, $this->customer, $this->accounting);   // 250,00 netto, 297,50 brutto, fällig 25.10.
        $this->at('2026-11-05');
        Payment::create(['invoice_id' => $invoice->id, 'kind' => 'payment', 'amount' => '100.00', 'paid_on' => '2026-11-03']);

        $stats = $this->stats();
        $this->assertSame(['EUR' => 19750], $stats['open']);
        $this->assertSame(1, $stats['open_count']);
        $this->assertSame(['EUR' => 19750], $stats['overdue']);
        $this->assertSame(1, $stats['overdue_count']);
        $this->assertSame([], $stats['revenue_month']);                // im November noch nichts freigegeben
        $this->assertSame(['EUR' => 25000], $stats['revenue_prev_month']);
        $this->assertSame(['EUR' => 25000], $stats['revenue_year']);
        $this->assertSame(['EUR' => 10000], $stats['paid_month']);

        $this->actAs($this->accounting)->get(route('dashboard'))->assertOk()
            ->assertSee('197,50 EUR')
            ->assertSee('250,00 EUR')
            ->assertSee('100,00 EUR')
            ->assertSee('R-2026-0001')
            ->assertSee('11 Tage überfällig')
            ->assertSee(route('payments.index'), false)
            ->assertSee(route('dunning.index'), false);
    }

    public function test_revenue_is_net_by_month_minus_credit_notes_and_ignores_test_documents(): void
    {
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting);                    // 250,00
        $second = $this->issueInvoice($this->tenant, $this->customer, $this->accounting, [], '40,00'); // 100,00

        $this->post(route('invoices.credit-note', $second))->assertRedirect();
        Tenancy::set($this->tenant->id);
        $credit = Invoice::latest('id')->firstOrFail();
        $this->post(route('invoices.issue', $credit))->assertRedirect();

        // Testbeleg (Testmodus) zählt nicht mit.
        $this->tenant->update(['test_mode' => true]);
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting, [], '1000,00');
        $this->tenant->update(['test_mode' => false]);

        $stats = $this->stats();
        $this->assertSame(['EUR' => 25000], $stats['revenue_month']);   // 250 + 100 − 100
        $this->assertSame(['EUR' => 25000], $stats['revenue_year']);
        $this->assertSame('2026-10', end($stats['months'])['key']);
        $this->assertSame(25000, end($stats['months'])['values']['EUR']);
    }

    public function test_twelve_month_window_and_month_buckets(): void
    {
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting, ['issue_date' => '2026-09-15']);
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting, ['issue_date' => '2025-12-01']);
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting, ['issue_date' => '2025-09-30']);   // außerhalb des Fensters

        $stats = $this->stats();
        $keys = array_column($stats['months'], 'key');
        $this->assertCount(12, $keys);
        $this->assertSame('2025-11', $keys[0]);
        $this->assertSame('2026-10', $keys[11]);

        $byKey = array_column($stats['months'], 'values', 'key');
        $this->assertSame(['EUR' => 25000], $byKey['2026-09']);
        $this->assertSame(['EUR' => 25000], $byKey['2025-12']);
        $this->assertSame([], $byKey['2026-10']);
        $this->assertSame(['EUR' => 25000], $stats['revenue_year']);   // nur 2026
    }

    public function test_currencies_are_kept_apart_without_conversion(): void
    {
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting);
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting, ['currency' => 'USD']);

        $stats = $this->stats();
        $this->assertSame(['EUR', 'USD'], $stats['currencies']);
        $this->assertSame(['EUR' => 29750, 'USD' => 29750], $stats['open']);
        $this->assertSame(['EUR' => 25000, 'USD' => 25000], $stats['revenue_month']);

        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee('297,50 EUR')->assertSee('297,50 USD');
    }

    public function test_dunning_suggestions_are_counted_and_hidden_without_payment_rights(): void
    {
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting);
        $this->at('2026-11-05');   // 7 Tage Karenz nach dem 25.10. sind vorbei

        $this->actAs($this->accounting)->get(route('dashboard'))->assertOk()->assertSee(__('messages.dashboard.dunning_due'));

        $this->actAs($this->clerk)->get(route('dashboard'))->assertOk()
            ->assertDontSee(__('messages.dashboard.dunning_due'))
            ->assertDontSee('href="'.route('payments.index').'"', false)
            ->assertDontSee('href="'.route('dunning.index').'"', false)
            ->assertSee('R-2026-0001');   // die Liste der überfälligen Rechnungen sehen alle
    }

    public function test_recurring_drafts_tile_appears_only_with_templates_and_counts_open_drafts(): void
    {
        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertDontSee(__('messages.dashboard.recurring_drafts'));

        $recurring = \App\Models\RecurringInvoice::create([
            'name' => 'Wartung', 'customer_id' => $this->customer->id, 'active' => true, 'interval' => 'monthly',
            'billing' => 'advance', 'anchor_date' => '2026-10-01', 'next_date' => '2026-10-01',
            'currency' => 'EUR', 'tax_mode' => 'standard',
        ]);
        $recurring->items()->create([
            'position' => 1, 'description' => 'Wartung', 'quantity' => 1, 'unit' => 'pauschal',
            'unit_price' => 100, 'tax_rate' => 19,
        ]);

        // Vorlage ohne Entwurf: Kachel mit 0.
        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee(__('messages.dashboard.recurring_drafts'))
            ->assertSee(route('recurring.index', ['drafts' => 1]), false);

        app(\App\Services\RecurringService::class)->generate($recurring, $this->admin, now()->startOfDay()->addDay());
        $count = Invoice::whereNotNull('recurring_invoice_id')->where('status', Invoice::STATUS_DRAFT)->count();
        $this->assertGreaterThan(0, $count);

        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['>'.$count.'</div>', __('messages.dashboard.recurring_drafts')], false);
    }

    public function test_other_tenants_data_never_shows_up(): void
    {
        $other = $this->makeTenant('Fremdfirma GmbH');
        $otherUser = $this->makeUser($other, 'accounting');
        $otherCustomer = $this->makeCustomer($other, '90001');
        $this->issueInvoice($other, $otherCustomer, $otherUser);

        $stats = $this->stats();
        $this->assertSame([], $stats['open']);
        $this->assertSame([], $stats['revenue_month']);
        $this->assertSame(0, $stats['open_count']);

        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()->assertSee(__('messages.dashboard.no_revenue'));
    }

    public function test_chart_is_drawn_when_there_is_revenue(): void
    {
        $this->issueInvoice($this->tenant, $this->customer, $this->accounting);

        $this->actAs($this->admin)->get(route('dashboard'))->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('10/26: 250,00 EUR', false);
    }
}
