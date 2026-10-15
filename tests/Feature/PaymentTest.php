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
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPaymentSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use CreatesPaymentSetup, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    private User $clerk;

    private User $viewer;

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
        $this->viewer = $this->makeUser($this->tenant, 'viewer');
        $this->customer = $this->makeCustomer($this->tenant);
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function invoice(array $extra = [], string $net = '100,00'): Invoice
    {
        return $this->issueInvoice($this->tenant, $this->customer, $this->accounting, $extra, $net);
    }

    private function summary(Invoice $invoice): array
    {
        Tenancy::set($this->tenant->id);

        return app(PaymentService::class)->summary($invoice->fresh());
    }

    private function pay(Invoice $invoice, string $amount, array $extra = [])
    {
        $response = $this->actAs($this->accounting)->post(route('payments.store', $invoice), array_merge([
            'amount' => $amount, 'paid_on' => '2026-10-11', 'reference' => 'Überweisung',
        ], $extra));
        Tenancy::set($this->tenant->id);

        return $response;
    }

    public function test_full_payment_closes_the_invoice(): void
    {
        $invoice = $this->invoice();
        $this->assertSame('open', $this->summary($invoice)['status']);
        $this->assertSame(29750, $this->summary($invoice)['open']);

        $this->pay($invoice, '297,50')->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('status');

        $summary = $this->summary($invoice);
        $this->assertSame('paid', $summary['status']);
        $this->assertSame(0, $summary['open']);

        $payment = Payment::sole();
        $this->assertSame('297.50', (string) $payment->amount);
        $this->assertSame('payment', $payment->kind);
        $this->assertSame($this->accounting->id, $payment->created_by);
        $this->assertSame('Überweisung', $payment->reference);
        $this->assertNotNull(AuditLog::where('action', 'payment.created')->first());
    }

    public function test_partial_payments_add_up_and_overpayment_is_flagged(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, '100');
        $this->assertSame('partial', $this->summary($invoice)['status']);
        $this->assertSame(19750, $this->summary($invoice)['open']);

        $this->pay($invoice, '197,50');
        $this->assertSame('paid', $this->summary($invoice)['status']);

        $this->pay($invoice, '5,00');
        $summary = $this->summary($invoice);
        $this->assertSame('overpaid', $summary['status']);
        $this->assertSame(-500, $summary['open']);
    }

    public function test_german_and_thousand_separated_amounts_are_accepted(): void
    {
        $invoice = $this->invoice([], '1.000,00');   // 2,5 × 1.000 = 2.500 netto, 2.975,00 brutto
        $this->assertSame(297500, $this->summary($invoice)['gross']);

        $this->pay($invoice, '2.975,00');

        $this->assertSame('paid', $this->summary($invoice)['status']);
    }

    public function test_credit_note_reduces_the_open_amount(): void
    {
        $invoice = $this->invoice();

        $this->actAs($this->accounting)->post(route('invoices.credit-note', $invoice));
        $credit = Invoice::where('type', 'credit_note')->firstOrFail();

        // Entwurf einer Gutschrift zählt noch nicht.
        $this->assertSame(29750, $this->summary($invoice)['open']);

        $this->post(route('invoices.issue', $credit));

        $summary = $this->summary($invoice);
        $this->assertSame(0, $summary['open']);
        $this->assertSame('credited', $summary['status']);
        $this->assertSame(29750, $summary['credited']);

        // Gutschriften selbst sind keine Posten und können nicht bezahlt werden.
        $this->pay($credit->fresh(), '10')->assertSessionHas('warning');
        $this->assertSame(0, Payment::count());
    }

    public function test_reversal_needs_a_reason_keeps_the_row_and_restores_the_open_amount(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '297,50');
        $payment = Payment::sole();

        $this->actAs($this->accounting)->post(route('payments.reverse', $payment), ['reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->assertSame('paid', $this->summary($invoice)['status']);

        $this->post(route('payments.reverse', $payment), ['reason' => 'Falsche Rechnung gewählt'])
            ->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('status');

        $this->assertSame('open', $this->summary($invoice)['status']);
        $payment = Payment::sole()->fresh();   // die Zeile bleibt erhalten
        $this->assertTrue($payment->isReversed());
        $this->assertSame('Falsche Rechnung gewählt', $payment->reverse_reason);
        $this->assertSame($this->accounting->id, $payment->reversed_by);
        $this->assertNotNull(AuditLog::where('action', 'payment.reversed')->first());

        // Zweites Stornieren geht nicht.
        $this->post(route('payments.reverse', $payment), ['reason' => 'nochmal'])->assertSessionHas('warning');
    }

    public function test_cash_discount_is_booked_as_payment_plus_discount(): void
    {
        $invoice = $this->invoice(['skonto1_percent' => '2', 'skonto1_days' => '10']);

        // 297,50 × 98 % = 291,55 → Abzug 5,95
        $this->pay($invoice, '291,55', ['skonto' => '1'])->assertSessionHas('status');

        $summary = $this->summary($invoice);
        $this->assertSame('paid', $summary['status']);
        $this->assertSame(29155, $summary['paid']);
        $this->assertSame(595, $summary['discount']);
        $this->assertSame(2, Payment::count());
        $this->assertSame('discount', Payment::where('kind', 'discount')->sole()->kind);

        // Das Stornieren der Zahlung nimmt den Skontoabzug mit zurück.
        $this->post(route('payments.reverse', Payment::where('kind', 'payment')->sole()), ['reason' => 'Irrtum']);
        $summary = $this->summary($invoice);
        $this->assertSame('open', $summary['status']);
        $this->assertSame(0, $summary['discount']);
    }

    public function test_cash_discount_needs_the_exact_discounted_amount_and_no_earlier_payment(): void
    {
        $invoice = $this->invoice(['skonto1_percent' => '2', 'skonto1_days' => '10']);

        $this->pay($invoice, '290,00', ['skonto' => '1'])->assertSessionHas('warning');
        $this->assertSame(0, Payment::count());

        $this->pay($invoice, '50');
        $this->pay($invoice, '291,55', ['skonto' => '1'])->assertSessionHas('warning');
        $this->assertSame(1, Payment::count());
    }

    public function test_only_issued_non_test_invoices_can_be_booked(): void
    {
        $this->actAs($this->accounting)->post('/invoices', [
            'type' => 'invoice', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11', 'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [['article_id' => '', 'description' => 'x', 'quantity' => '1', 'unit' => 'Stk', 'unit_price' => '10', 'discount_percent' => '', 'tax_rate' => '19']],
        ]);
        $draft = Invoice::latest('id')->firstOrFail();

        $this->pay($draft, '5')->assertSessionHas('warning');

        $this->tenant->update(['test_mode' => true]);
        $test = $this->invoice();
        $this->assertTrue($test->is_test);
        $this->pay($test, '5')->assertSessionHas('warning');

        $this->assertSame(0, Payment::count());
    }

    public function test_input_is_validated(): void
    {
        $invoice = $this->invoice();

        $this->pay($invoice, '0')->assertSessionHasErrors('amount');
        $this->pay($invoice, '-5')->assertSessionHasErrors('amount');
        $this->pay($invoice, 'abc')->assertSessionHasErrors('amount');
        $this->pay($invoice, '10', ['paid_on' => '2026-12-31'])->assertSessionHasErrors('paid_on');
        $this->pay($invoice, '10', ['paid_on' => ''])->assertSessionHasErrors('paid_on');

        $this->assertSame(0, Payment::count());
    }

    public function test_open_items_list_has_selection_and_toolbar(): void
    {
        $open = $this->invoice();

        $this->actAs($this->accounting)->get(route('payments.index'))->assertOk()
            ->assertSee('data-pick-actions', false)->assertSee('type="radio"', false)
            ->assertSee('data-open="'.route('invoices.show', $open).'"', false)
            ->assertSee('data-book="'.route('invoices.show', $open).'#payments"', false)
            ->assertSee('data-pdf="'.route('invoices.pdf', $open).'"', false)
            ->assertSee('data-mail="'.route('invoices.mail', $open).'"', false)
            ->assertSee(__('messages.payments.book_payment'));
    }

    public function test_only_admin_and_accounting_may_book_and_see_open_items(): void
    {
        $invoice = $this->invoice();

        foreach ([$this->clerk, $this->viewer] as $user) {
            $this->actAs($user)->post(route('payments.store', $invoice), ['amount' => '10', 'paid_on' => '2026-10-11'])->assertForbidden();
            $this->get(route('payments.index'))->assertForbidden();
        }
        $this->assertSame(0, Payment::count());

        $this->actAs($this->admin)->post(route('payments.store', $invoice), ['amount' => '10', 'paid_on' => '2026-10-11'])->assertRedirect();
        $this->assertSame(1, Payment::count());

        $this->post(route('payments.reverse', Payment::sole()), ['reason' => 'x'])->assertRedirect();

        // Sachbearbeiter dürfen nicht stornieren.
        $this->pay($invoice, '10');
        $this->actAs($this->clerk)->post(route('payments.reverse', Payment::active()->sole()), ['reason' => 'x'])->assertForbidden();
    }

    public function test_invoice_page_shows_status_and_booking_form_only_to_those_who_may_book(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '100');

        $this->actAs($this->accounting)->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Teilweise bezahlt')->assertSee('197,50')->assertSee('Zahlung buchen');

        $this->actAs($this->clerk)->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Teilweise bezahlt')->assertDontSee('Zahlung buchen');

        $this->actAs($this->accounting)->get(route('invoices.index'))->assertOk()->assertSee('Teilweise bezahlt');
    }

    public function test_open_items_list_totals_and_overdue_filter(): void
    {
        $paid = $this->invoice();
        $this->pay($paid, '297,50');
        $open = $this->invoice();
        $partly = $this->invoice();
        $this->pay($partly, '97,50');

        $this->actAs($this->accounting)->get(route('payments.index'))->assertOk()
            ->assertSee($open->number)->assertSee($partly->number)->assertDontSee($paid->number)
            ->assertSee('497,50')   // 297,50 + 200,00 offen
            ->assertSee('2 Rechnungen');

        // Noch nicht fällig (25.10.2026) → keine überfälligen Posten.
        $this->get(route('payments.index', ['filter' => 'overdue']))->assertOk()
            ->assertDontSee($open->number)->assertSee('Keine offenen Posten');

        $this->travelTo(Carbon::parse('2026-11-05 10:00:00'));
        $this->get(route('payments.index', ['filter' => 'overdue']))->assertOk()
            ->assertSee($open->number)->assertSee('11 Tage überfällig');
    }

    public function test_tenants_cannot_touch_each_others_payments(): void
    {
        $invoice = $this->invoice();
        $this->pay($invoice, '100');
        $payment = Payment::sole();

        $other = $this->makeTenant('Fremdfirma GmbH');
        $otherUser = $this->makeUser($other, 'admin');

        $this->actingAs($otherUser)->withSession(['tenant_id' => $other->id]);
        Tenancy::set($other->id);

        $this->post(route('payments.store', $invoice), ['amount' => '10', 'paid_on' => '2026-10-11'])->assertNotFound();
        $this->post(route('payments.reverse', $payment), ['reason' => 'x'])->assertNotFound();
        $this->get(route('payments.index'))->assertOk()->assertDontSee($invoice->number);

        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Payment::active()->count());
    }
}
