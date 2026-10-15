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
use App\Services\InvoiceSeries;
use App\Services\Layout\DocumentModel;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** Teilrechnungen und Schlussrechnung als Rechnungsserie. */
class InvoiceSeriesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $accounting;

    private User $clerk;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');

        $this->tenant = $this->makeTenant();
        $this->accounting = $this->makeUser($this->tenant, 'accounting');
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
        $this->customer = $this->makeCustomer($this->tenant);
        $this->actingAs($this->accounting)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);
    }

    private function form(array $overrides = [], string $net = '1000,00'): array
    {
        return array_merge([
            'type' => 'invoice', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11',
            'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [['article_id' => '', 'description' => 'Teilleistung', 'quantity' => '1', 'unit' => 'Pauschale',
                'unit_price' => $net, 'discount_percent' => '', 'tax_rate' => '19']],
        ], $overrides);
    }

    /** Legt einen Entwurf an und liefert ihn zurück. */
    private function draft(array $overrides = [], string $net = '1000,00'): Invoice
    {
        $this->post('/invoices', $this->form($overrides, $net))->assertSessionHasNoErrors();
        Tenancy::set($this->tenant->id);

        return Invoice::latest('id')->firstOrFail();
    }

    private function issued(array $overrides = [], string $net = '1000,00'): Invoice
    {
        $invoice = $this->draft($overrides, $net);
        $this->post(route('invoices.issue', $invoice))->assertRedirect();
        Tenancy::set($this->tenant->id);

        return $invoice->fresh();
    }

    public function test_a_partial_invoice_without_reference_starts_a_series(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);

        $this->assertSame('partial', $first->billing_kind);
        $this->assertNull($first->series_invoice_id);
        $this->assertSame(1, $first->series_no);
        $this->assertSame($first->id, $first->seriesRootId());
        $this->assertSame('1. Teilrechnung', $first->documentTitle());
        $this->assertSame('R-2026-0001', $first->number);   // gleiche Nummernfolge wie normale Rechnungen
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($first->pdf_path));
    }

    public function test_follow_up_invoices_join_the_series_and_are_numbered(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);
        $second = $this->issued(['billing_kind' => 'partial', 'series_invoice_id' => $first->id]);
        // Bezug auf eine spätere Teilrechnung wird auf die Wurzel der Serie normalisiert.
        $third = $this->issued(['billing_kind' => 'partial', 'series_invoice_id' => $second->id]);

        $this->assertSame($first->id, $second->series_invoice_id);
        $this->assertSame($first->id, $third->series_invoice_id);
        $this->assertSame([1, 2, 3], [$first->series_no, $second->series_no, $third->series_no]);
        $this->assertSame('3. Teilrechnung', $third->documentTitle());
        $this->assertSame(['R-2026-0001', 'R-2026-0002', 'R-2026-0003'], [$first->number, $second->number, $third->number]);
    }

    public function test_final_invoice_needs_a_series_and_closes_it(): void
    {
        $this->post('/invoices', $this->form(['billing_kind' => 'final']))->assertSessionHasErrors('series_invoice_id');
        $this->assertSame(0, Invoice::count());

        $first = $this->issued(['billing_kind' => 'partial']);
        $final = $this->issued(['billing_kind' => 'final', 'series_invoice_id' => $first->id]);

        $this->assertSame('final', $final->billing_kind);
        $this->assertSame(2, $final->series_no);
        $this->assertSame('Schlussrechnung', $final->documentTitle());
        $this->assertTrue($final->seriesClosed());

        // Eine geschlossene Serie nimmt weder weitere Teilrechnungen noch eine zweite Schlussrechnung auf.
        foreach (['partial', 'final'] as $kind) {
            $this->post('/invoices', $this->form(['billing_kind' => $kind, 'series_invoice_id' => $first->id]))
                ->assertSessionHasErrors('series_invoice_id');
        }
        $this->assertSame(2, Invoice::count());
    }

    public function test_a_draft_that_was_prepared_before_the_final_invoice_cannot_be_issued_afterwards(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);
        $lateDraft = $this->draft(['billing_kind' => 'partial', 'series_invoice_id' => $first->id]);
        $this->issued(['billing_kind' => 'final', 'series_invoice_id' => $first->id]);

        $this->post(route('invoices.issue', $lateDraft))->assertSessionHas('warning');
        $this->assertTrue($lateDraft->fresh()->isDraft());
    }

    public function test_series_reference_is_checked_for_customer_currency_and_state(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);
        $other = $this->makeCustomer($this->tenant, '10002');
        Tenancy::set($this->tenant->id);

        // anderer Kunde
        $this->post('/invoices', $this->form(['billing_kind' => 'partial', 'series_invoice_id' => $first->id, 'customer_id' => $other->id]))
            ->assertSessionHasErrors('series_invoice_id');
        // andere Währung
        $this->post('/invoices', $this->form(['billing_kind' => 'partial', 'series_invoice_id' => $first->id, 'currency' => 'USD']))
            ->assertSessionHasErrors('series_invoice_id');
        // Entwurf (nicht freigegeben) oder normale Rechnung sind kein Bezug
        $draft = $this->draft(['billing_kind' => 'partial']);
        $normal = $this->issued();
        foreach ([$draft, $normal] as $invalid) {
            $this->post('/invoices', $this->form(['billing_kind' => 'partial', 'series_invoice_id' => $invalid->id]))
                ->assertSessionHasErrors('series_invoice_id');
        }
    }

    public function test_series_reference_of_another_tenant_is_rejected(): void
    {
        $foreign = $this->makeTenant('Fremdfirma GmbH');
        $foreignUser = $this->makeUser($foreign, 'accounting');
        $foreignCustomer = $this->makeCustomer($foreign, '90001');

        $this->actingAs($foreignUser)->withSession(['tenant_id' => $foreign->id]);
        Tenancy::set($foreign->id);
        $this->post('/invoices', $this->form(['billing_kind' => 'partial', 'customer_id' => $foreignCustomer->id]))->assertSessionHasNoErrors();
        Tenancy::set($foreign->id);
        $foreignRoot = Invoice::latest('id')->firstOrFail();
        $this->post(route('invoices.issue', $foreignRoot));

        $this->actingAs($this->accounting)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);
        $this->post('/invoices', $this->form(['billing_kind' => 'partial', 'series_invoice_id' => $foreignRoot->id]))
            ->assertSessionHasErrors('series_invoice_id');
    }

    public function test_normal_invoices_and_credit_notes_never_belong_to_a_series(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);

        $normal = $this->draft(['billing_kind' => 'normal', 'series_invoice_id' => $first->id]);
        $this->assertSame('normal', $normal->billing_kind);
        $this->assertNull($normal->series_invoice_id);
        $this->assertFalse($normal->inSeries());
        $this->assertSame('Rechnung', $normal->documentTitle());

        $this->post(route('invoices.credit-note', $first))->assertRedirect();
        Tenancy::set($this->tenant->id);
        $credit = Invoice::latest('id')->firstOrFail();
        $this->assertTrue($credit->isCreditNote());
        $this->assertSame('normal', $credit->billing_kind);
        $this->assertNull($credit->series_invoice_id);
        $this->assertSame('Gutschrift', $credit->documentTitle());
    }

    public function test_summary_lists_previous_invoices_reduced_by_credit_notes(): void
    {
        $first = $this->issued(['billing_kind' => 'partial'], '1000,00');
        $second = $this->issued(['billing_kind' => 'partial', 'series_invoice_id' => $first->id], '500,00');
        $final = $this->draft(['billing_kind' => 'final', 'series_invoice_id' => $first->id], '250,00');

        $summary = InvoiceSeries::summary($final);
        $this->assertSame(['R-2026-0001', 'R-2026-0002'], array_slice(array_column($summary['rows'], 'number'), 0, 2));
        $this->assertSame([false, false, true], array_column($summary['rows'], 'is_this'));
        $this->assertSame(1750.0, $summary['total_net']);
        $this->assertSame(2082.5, $summary['total_gross']);

        // Eine freigegebene Gutschrift zur ersten Teilrechnung mindert die bisher abgerechneten Beträge.
        $this->post(route('invoices.credit-note', $first))->assertRedirect();
        Tenancy::set($this->tenant->id);
        $credit = Invoice::latest('id')->firstOrFail();
        $this->post(route('invoices.issue', $credit))->assertRedirect();
        Tenancy::set($this->tenant->id);

        $summary = InvoiceSeries::summary($final->fresh());
        $this->assertCount(2, $summary['rows']);   // die vollständig gutgeschriebene Rechnung entfällt
        $this->assertSame(750.0, $summary['total_net']);
        $this->assertSame(892.5, $summary['total_gross']);

        // Keine Serie → keine Übersicht.
        $this->assertNull(InvoiceSeries::summary($this->issued()));
    }

    public function test_layout_model_exposes_title_flags_and_series_rows(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);
        $final = $this->issued(['billing_kind' => 'final', 'series_invoice_id' => $first->id], '500,00');

        $model = DocumentModel::fromInvoice($final, $this->tenant, null, null, [], InvoiceSeries::summary($final));

        $this->assertSame('Schlussrechnung', $model['doc']['title']);
        $this->assertSame('final', $model['doc']['billing_kind']);
        $this->assertTrue($model['doc']['is_final']);
        $this->assertFalse($model['doc']['is_partial']);
        $this->assertTrue($model['series']['show']);
        $this->assertSame(['R-2026-0001', 'R-2026-0002'], array_column($model['series']['rows'], 'number'));
        $this->assertSame('1.500,00', $model['series']['total_net']);
        $this->assertSame('1.785,00', $model['series']['total_gross']);

        $normal = DocumentModel::fromInvoice($this->issued(), $this->tenant, null, null, [], null);
        $this->assertFalse($normal['series']['show']);
        $this->assertSame('Rechnung', $normal['doc']['title']);
    }

    public function test_form_prefills_follow_up_data_and_shows_the_series_controls(): void
    {
        $first = $this->issued(['billing_kind' => 'partial', 'customer_order_ref' => 'PO-77']);

        $this->get(route('invoices.create', ['series' => $first->id, 'billing' => 'final']))
            ->assertOk()
            ->assertSee(__('messages.invoices.billing_kind'))
            ->assertSee('PO-77')
            ->assertSee('value="'.$first->id.'" selected', false);

        // Gutschriften haben kein Serienfeld.
        $this->get(route('invoices.create', ['type' => 'credit_note']))->assertOk()->assertDontSee(__('messages.invoices.billing_kind'));
    }

    public function test_detail_page_shows_series_and_follow_up_buttons_until_it_is_closed(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);

        $this->get(route('invoices.show', $first))->assertOk()
            ->assertSee(__('messages.invoices.next_partial'))
            ->assertSee(__('messages.invoices.create_final'))
            ->assertSee(__('messages.invoices.series_title'));

        $final = $this->issued(['billing_kind' => 'final', 'series_invoice_id' => $first->id]);

        $this->get(route('invoices.show', $first))->assertOk()
            ->assertDontSee(__('messages.invoices.next_partial'))
            ->assertSee(__('messages.invoices.series_closed'))
            ->assertSee($final->number);

        // Normale Rechnungen zeigen weder Serie noch Folgerechnungs-Buttons.
        $this->get(route('invoices.show', $this->issued()))->assertOk()
            ->assertDontSee(__('messages.invoices.next_partial'))
            ->assertDontSee(__('messages.invoices.series_title'));
    }

    public function test_clerk_can_prepare_drafts_but_follow_up_buttons_need_edit_rights_only(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);

        $this->actingAs($this->clerk)->withSession(['tenant_id' => $this->tenant->id]);
        $this->get(route('invoices.show', $first))->assertOk()->assertSee(__('messages.invoices.next_partial'));
        $this->post('/invoices', $this->form(['billing_kind' => 'partial', 'series_invoice_id' => $first->id]))->assertSessionHasNoErrors();
        Tenancy::set($this->tenant->id);

        $this->assertSame($first->id, Invoice::latest('id')->firstOrFail()->series_invoice_id);
    }

    public function test_series_invoice_pdf_is_rendered_for_issued_documents(): void
    {
        $first = $this->issued(['billing_kind' => 'partial']);
        $final = $this->issued(['billing_kind' => 'final', 'series_invoice_id' => $first->id]);

        $response = $this->get(route('invoices.pdf', $final));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }
}
