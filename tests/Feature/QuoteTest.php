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
use App\Models\MailLog;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class QuoteTest extends TestCase
{
    use CreatesMailSetup, CreatesTenants, RefreshDatabase;

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

    private function form(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11', 'valid_until' => '',
            'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [
                ['article_id' => '', 'description' => 'Beratung', 'quantity' => '2', 'unit' => 'Stunde', 'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19'],
                ['article_id' => '', 'description' => 'Reise', 'quantity' => '1', 'unit' => 'pauschal', 'unit_price' => '50,00', 'discount_percent' => '', 'tax_rate' => '19'],
            ],
        ], $overrides);
    }

    private function draft(array $overrides = []): Quote
    {
        $this->actAs($this->accounting)->post(route('quotes.store'), $this->form($overrides))->assertSessionHasNoErrors();

        return Quote::latest('id')->firstOrFail();
    }

    private function openQuote(array $overrides = []): Quote
    {
        $quote = $this->draft($overrides);
        $this->post(route('quotes.issue', $quote))->assertSessionHasNoErrors();

        return $quote->fresh();
    }

    public function test_saving_a_draft_calculates_totals_and_default_validity(): void
    {
        $quote = $this->draft();

        $this->assertSame('draft', $quote->status);
        $this->assertNull($quote->number);
        $this->assertSame('250.00', $quote->net_total);
        $this->assertSame('297.50', $quote->gross_total);
        $this->assertSame('2026-11-10', $quote->valid_until->toDateString());
        $this->assertCount(2, $quote->items);
    }

    public function test_issuing_assigns_the_quote_number_and_opens_it(): void
    {
        $quote = $this->openQuote();

        $this->assertSame('open', $quote->status);
        $this->assertSame('A-2026-0001', $quote->number);

        $second = $this->openQuote();
        $this->assertSame('A-2026-0002', $second->number);

        // Rechnungen haben einen eigenen Nummernkreis.
        $this->assertSame(0, Invoice::count());
    }

    public function test_test_mode_uses_its_own_counter_with_prefix(): void
    {
        $this->tenant->update(['test_mode' => true]);

        $quote = $this->openQuote();
        $this->assertTrue((bool) $quote->is_test);
        $this->assertSame('TEST-A-2026-0001', $quote->number);
    }

    public function test_open_quote_stays_editable_but_not_after_status_change(): void
    {
        $quote = $this->openQuote();

        $this->put(route('quotes.update', $quote), $this->form(['intro_text' => 'Neu']))->assertSessionHasNoErrors();
        $this->assertSame('Neu', $quote->fresh()->intro_text);

        $this->post(route('quotes.status', $quote), ['to' => 'declined'])->assertSessionHasNoErrors();
        $this->put(route('quotes.update', $quote), $this->form(['intro_text' => 'Nochmal']))->assertSessionHas('warning');
        $this->assertSame('Neu', $quote->fresh()->intro_text);
    }

    public function test_status_transitions(): void
    {
        $quote = $this->openQuote();

        $this->post(route('quotes.status', $quote), ['to' => 'accepted']);
        $this->assertSame('accepted', $quote->fresh()->status);

        // angenommen -> abgelehnt ist nicht erlaubt, wieder öffnen schon
        $this->post(route('quotes.status', $quote), ['to' => 'declined'])->assertSessionHas('warning');
        $this->assertSame('accepted', $quote->fresh()->status);

        $this->post(route('quotes.status', $quote), ['to' => 'open']);
        $this->assertSame('open', $quote->fresh()->status);

        $this->assertSame(2, AuditLog::where('action', 'quote.status')->count());
    }

    public function test_expired_is_derived_from_the_validity_date(): void
    {
        $quote = $this->openQuote(['valid_until' => '2026-10-20']);
        $this->assertSame('open', $quote->displayStatus());

        $this->travelTo(Carbon::parse('2026-10-21 08:00:00'));
        $this->assertSame('open', $quote->fresh()->status);
        $this->assertSame('expired', $quote->fresh()->displayStatus());
    }

    public function test_full_conversion_creates_an_invoice_draft_and_accepts_the_quote(): void
    {
        $quote = $this->openQuote();

        $this->post(route('quotes.convert', $quote))->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertTrue($invoice->isDraft());
        $this->assertSame($quote->id, $invoice->quote_id);
        $this->assertSame($this->customer->id, $invoice->customer_id);
        $this->assertSame('297.50', $invoice->gross_total);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('accepted', $quote->fresh()->status);
        $this->assertSame(0, $quote->items()->whereNull('invoice_id')->count());
        $this->assertSame(1, AuditLog::where('action', 'quote.converted')->count());

        // Nichts mehr übrig: zweite Umwandlung wird abgewiesen.
        $this->post(route('quotes.convert', $quote))->assertSessionHas('warning');
        $this->assertSame(1, Invoice::count());
    }

    public function test_partial_conversion_takes_only_selected_items(): void
    {
        $quote = $this->openQuote();
        $first = $quote->items()->orderBy('position')->first();

        $this->post(route('quotes.convert', $quote), ['selection' => 1, 'items' => [$first->id]])->assertSessionHasNoErrors();

        $invoice = Invoice::sole();
        $this->assertSame(['Beratung'], $invoice->items->pluck('description')->all());
        $this->assertSame('238.00', $invoice->gross_total);
        $this->assertSame(1, $quote->items()->whereNull('invoice_id')->count());

        // Rest später in eine zweite Rechnung
        $this->post(route('quotes.convert', $quote))->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::count());
        $this->assertSame(0, $quote->items()->whereNull('invoice_id')->count());

        // Angebot mit Rechnungen ist nicht mehr änderbar
        $this->put(route('quotes.update', $quote), $this->form())->assertSessionHas('warning');
    }

    public function test_empty_selection_is_rejected(): void
    {
        $quote = $this->openQuote();

        $this->post(route('quotes.convert', $quote), ['selection' => 1])->assertSessionHas('warning');
        $this->assertSame(0, Invoice::count());
        $this->assertSame('open', $quote->fresh()->status);
    }

    public function test_deleting_the_invoice_draft_releases_the_items(): void
    {
        $quote = $this->openQuote();
        $this->post(route('quotes.convert', $quote));

        $invoice = Invoice::sole();
        $this->delete(route('invoices.destroy', $invoice));

        $this->assertSame(2, $quote->items()->whereNull('invoice_id')->count());
    }

    public function test_draft_can_be_deleted_but_issued_quote_cannot(): void
    {
        $draft = $this->draft();
        $this->delete(route('quotes.destroy', $draft))->assertRedirect(route('quotes.index'));
        $this->assertSame(0, Quote::count());

        $open = $this->openQuote();
        $this->delete(route('quotes.destroy', $open))->assertSessionHas('warning');
        $this->assertSame(1, Quote::count());
    }

    public function test_roles(): void
    {
        $quote = $this->openQuote();

        $this->actAs($this->viewer);
        $this->get(route('quotes.index'))->assertOk();
        $this->get(route('quotes.show', $quote))->assertOk();
        $this->get(route('quotes.create'))->assertForbidden();
        $this->post(route('quotes.store'), $this->form())->assertForbidden();
        $this->post(route('quotes.convert', $quote))->assertForbidden();

        // Sachbearbeiter dürfen anlegen, aber nicht freigeben.
        $this->actAs($this->clerk);
        $this->post(route('quotes.store'), $this->form())->assertSessionHasNoErrors();
        $draft = Quote::latest('id')->firstOrFail();
        $this->post(route('quotes.issue', $draft))->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_other_tenants_quotes_are_invisible(): void
    {
        $quote = $this->openQuote();

        $other = $this->makeTenant('Andere GmbH');
        $otherAdmin = $this->makeUser($other, 'admin');
        $this->actingAs($otherAdmin)->withSession(['tenant_id' => $other->id]);

        $this->get(route('quotes.show', $quote))->assertNotFound();
        $this->get(route('quotes.pdf', $quote))->assertNotFound();
        $this->post(route('quotes.convert', $quote))->assertNotFound();
        $this->get(route('quotes.index'))->assertOk()->assertDontSee('A-2026-0001');
    }

    public function test_index_shows_and_filters_quotes(): void
    {
        $this->openQuote();
        $this->draft();

        $this->get(route('quotes.index'))->assertOk()->assertSee('A-2026-0001')->assertSee('Kunde 10001 GmbH');
    }

    public function test_pdf_for_draft_and_open_quote(): void
    {
        $draft = $this->draft();
        $response = $this->get(route('quotes.pdf', $draft))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $open = $this->openQuote();
        $response = $this->get(route('quotes.pdf', $open))->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('A-2026-0001', $response->headers->get('Content-Disposition'));
    }

    public function test_mail_dialog_and_sending_with_pdf_attached(): void
    {
        $this->tenant->update(['mail_account_id' => $this->makeMailAccount()->id]);
        $fake = $this->fakeMailer();
        $quote = $this->openQuote();

        $this->get(route('quotes.mail', $quote))->assertOk()
            ->assertSee('kunde10001@example.com')
            ->assertSee('A-2026-0001');

        $this->post(route('quotes.mail.send', $quote), [
            'to' => 'kunde10001@example.com', 'subject' => 'Ihr Angebot', 'body' => "Hallo,\r\nanbei das Angebot.",
        ])->assertSessionHasNoErrors()->assertRedirect(route('quotes.show', $quote));

        $this->assertCount(1, $fake->sent);
        $attachments = $fake->sent[0]['email']->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('A-2026-0001.pdf', $attachments[0]->getFilename());
        $this->assertStringStartsWith('%PDF', $attachments[0]->getBody());

        $log = MailLog::sole();
        $this->assertSame($quote->id, $log->quote_id);
        $this->assertNull($log->invoice_id);
        $this->assertTrue($log->wasSent());
        $this->assertSame(1, AuditLog::where('action', 'quote.mailed')->count());
    }

    public function test_drafts_cannot_be_mailed(): void
    {
        $this->tenant->update(['mail_account_id' => $this->makeMailAccount()->id]);
        $this->fakeMailer();
        $draft = $this->draft();

        $this->get(route('quotes.mail', $draft))->assertRedirect(route('quotes.show', $draft))->assertSessionHas('warning');
        $this->post(route('quotes.mail.send', $draft), ['to' => 'a@example.com', 'subject' => 'x', 'body' => 'y'])->assertSessionHas('warning');
        $this->assertSame(0, MailLog::count());
    }

    public function test_numbering_page_lists_the_quote_range(): void
    {
        $this->actAs($this->admin)->get(route('numbering.index'))->assertOk()->assertSee('A-2026-0001', false);
    }

    public function test_quote_number_format_and_validity_are_tenant_settings(): void
    {
        $this->tenant->update(['quote_number_format' => 'ANG-{YYYY}-{NNN}', 'quote_valid_days' => 14]);

        $quote = $this->draft();
        $this->assertSame('2026-10-25', $quote->valid_until->toDateString());

        $this->post(route('quotes.issue', $quote));
        $this->assertSame('ANG-2026-001', $quote->fresh()->number);
    }
}
