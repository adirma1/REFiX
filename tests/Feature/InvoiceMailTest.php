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
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesTenants;
use Tests\Support\FakeMailSender;
use Tests\TestCase;

class InvoiceMailTest extends TestCase
{
    use CreatesMailSetup, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    private User $clerk;

    private Customer $customer;

    private FakeMailSender $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');

        $this->tenant = $this->makeTenant();
        $this->tenant->update(['mail_account_id' => $this->makeMailAccount([
            'bcc' => 'chef@example.com', 'reply_to' => 'antwort@example.com',
        ])->id]);

        $this->admin = $this->makeUser($this->tenant, 'admin');
        $this->accounting = $this->makeUser($this->tenant, 'accounting');
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
        $this->customer = $this->makeCustomer($this->tenant);
        $this->fake = $this->fakeMailer();
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);

        return $this;
    }

    private function mailData(array $overrides = []): array
    {
        return array_merge([
            'to' => 'kunde10001@example.com', 'subject' => 'Ihre Rechnung', 'body' => "Hallo,\r\nanbei die Rechnung.",
        ], $overrides);
    }

    private function logs()
    {
        Tenancy::set($this->tenant->id);

        return MailLog::orderBy('id')->get();
    }

    public function test_accounting_sends_an_issued_invoice_with_the_pdf_attached(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $this->post(route('invoices.mail.send', $invoice), $this->mailData())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('status');

        $this->assertCount(1, $this->fake->sent);
        $email = $this->fake->sent[0]['email'];

        $this->assertSame('rechnung@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Testfirma GmbH', $email->getFrom()[0]->getName());
        $this->assertSame(['kunde10001@example.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame(['chef@example.com'], array_map(fn ($a) => $a->getAddress(), $email->getBcc()));
        $this->assertSame('antwort@example.com', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('Ihre Rechnung', $email->getSubject());
        $this->assertSame("Hallo,\nanbei die Rechnung.", $email->getTextBody());

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('R-2026-0001.pdf', $attachments[0]->getFilename());
        $this->assertStringStartsWith('%PDF', $attachments[0]->getBody());
        // Es ist exakt das archivierte, unveränderliche PDF.
        $this->assertSame(Storage::disk('local')->get($invoice->pdf_path), $attachments[0]->getBody());

        $log = $this->logs()->sole();
        $this->assertTrue($log->wasSent());
        $this->assertSame($invoice->id, $log->invoice_id);
        $this->assertSame($this->accounting->id, $log->user_id);
        $this->assertSame('GMX Firma', $log->account_name);
        $this->assertSame('kunde10001@example.com', $log->recipients);
        $this->assertSame('chef@example.com', $log->bcc);

        $audit = AuditLog::where('action', 'invoice.mailed')->sole();
        $this->assertSame($invoice->id, $audit->subject_id);
    }

    public function test_dialog_is_prefilled_with_recipient_and_the_tenant_texts(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $this->get(route('invoices.mail', $invoice))->assertOk()
            ->assertSee('kunde10001@example.com')
            ->assertSee('Rechnung R-2026-0001 von Testfirma GmbH')
            ->assertSee('Sehr geehrte Damen und Herren,')
            ->assertSee('über 297,50 EUR')
            ->assertSee('bis zum 25.10.2026');

        $this->tenant->update(['mail_invoice_subject' => 'Eigener Betreff {belegnummer}']);
        $this->get(route('invoices.mail', $invoice))->assertSee('Eigener Betreff R-2026-0001');
    }

    public function test_dialog_addresses_the_contact_person(): void
    {
        $customer = $this->makeCustomer($this->tenant, '10002', ['salutation' => 'Frau', 'first_name' => 'Eva', 'last_name' => 'Muster']);
        $invoice = $this->issuedInvoice($this->tenant, $customer, $this->accounting);

        $this->get(route('invoices.mail', $invoice))->assertSee('Sehr geehrte Frau Muster,');
    }

    public function test_clerk_may_not_send_by_default_but_can_when_the_matrix_allows_it(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $this->actAs($this->clerk);
        $this->get(route('invoices.mail', $invoice))->assertForbidden();
        $this->post(route('invoices.mail.send', $invoice), $this->mailData())->assertForbidden();
        $this->get(route('invoices.show', $invoice))->assertOk()->assertDontSee('Per E-Mail senden');
        $this->assertCount(0, $this->fake->sent);

        $this->tenant->update(['mail_send_roles' => ['accounting', 'clerk']]);
        $this->get(route('invoices.show', $invoice))->assertSee('Per E-Mail senden');
        $this->post(route('invoices.mail.send', $invoice), $this->mailData())->assertSessionHas('status');
        $this->assertCount(1, $this->fake->sent);
    }

    public function test_accounting_loses_the_right_when_the_matrix_removes_it_but_admin_always_may(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);
        $this->tenant->update(['mail_send_roles' => []]);

        $this->actAs($this->accounting)->post(route('invoices.mail.send', $invoice), $this->mailData())->assertForbidden();

        $this->actAs($this->admin)->post(route('invoices.mail.send', $invoice), $this->mailData())->assertSessionHas('status');
        $this->assertCount(1, $this->fake->sent);
    }

    public function test_viewer_never_sees_or_uses_the_mail_function(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);
        $viewer = $this->makeUser($this->tenant, 'viewer');
        $this->tenant->update(['mail_send_roles' => ['accounting', 'clerk', 'viewer']]);

        $this->actAs($viewer)->get(route('invoices.mail', $invoice))->assertForbidden();
    }

    public function test_drafts_cannot_be_sent(): void
    {
        $this->actAs($this->clerk)->post('/invoices', [
            'type' => 'invoice', 'customer_id' => $this->customer->id, 'issue_date' => '2026-10-11', 'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [['article_id' => '', 'description' => 'X', 'quantity' => '1', 'unit' => '', 'unit_price' => '10,00', 'discount_percent' => '', 'tax_rate' => '19']],
        ]);
        Tenancy::set($this->tenant->id);
        $draft = Invoice::latest('id')->firstOrFail();

        $this->actAs($this->admin);
        $this->get(route('invoices.mail', $draft))->assertRedirect(route('invoices.show', $draft))->assertSessionHas('warning');
        $this->post(route('invoices.mail.send', $draft), $this->mailData())->assertRedirect(route('invoices.show', $draft))->assertSessionHas('warning');

        $this->assertCount(0, $this->fake->sent);
        $this->assertCount(0, $this->logs());
    }

    public function test_sending_needs_an_assigned_account(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);
        $this->tenant->update(['mail_account_id' => null]);

        $this->actAs($this->accounting);
        $this->get(route('invoices.mail', $invoice))->assertRedirect(route('invoices.show', $invoice))->assertSessionHas('warning');
        $this->post(route('invoices.mail.send', $invoice), $this->mailData())->assertSessionHas('warning');
        $this->assertCount(0, $this->fake->sent);
    }

    public function test_a_failed_dispatch_is_logged_and_shown_and_can_be_repeated(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);
        $this->fake->fail = new RuntimeException('535 Authentication failed');

        $this->post(route('invoices.mail.send', $invoice), $this->mailData())
            ->assertRedirect(route('invoices.mail', $invoice))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '535 Authentication failed'));

        $log = $this->logs()->sole();
        $this->assertFalse($log->wasSent());
        $this->assertSame('535 Authentication failed', $log->error);
        $this->assertSame(1, AuditLog::where('action', 'invoice.mail_failed')->count());

        $this->get(route('invoices.show', $invoice))->assertSee('fehlgeschlagen')->assertSee('535 Authentication failed');

        // Wiederholen klappt, sobald der Server wieder antwortet.
        $this->fake->fail = null;
        $this->post(route('invoices.mail.send', $invoice), $this->mailData())->assertSessionHas('status');
        $this->assertCount(2, $this->logs());
        $this->get(route('invoices.show', $invoice))->assertSee('gesendet');
    }

    public function test_recipients_and_texts_are_validated(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $this->post(route('invoices.mail.send', $invoice), $this->mailData(['to' => 'kaputt']))->assertSessionHasErrors('to');
        $this->post(route('invoices.mail.send', $invoice), $this->mailData(['to' => '']))->assertSessionHasErrors('to');
        $this->post(route('invoices.mail.send', $invoice), $this->mailData(['to' => 'a@x.de, b@x.de, c@x.de, d@x.de, e@x.de, f@x.de']))->assertSessionHasErrors('to');
        $this->post(route('invoices.mail.send', $invoice), $this->mailData(['subject' => '']))->assertSessionHasErrors('subject');
        $this->post(route('invoices.mail.send', $invoice), $this->mailData(['body' => '']))->assertSessionHasErrors('body');

        $this->assertCount(0, $this->fake->sent);
    }

    public function test_several_recipients_and_a_header_injection_attempt(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $this->post(route('invoices.mail.send', $invoice), $this->mailData([
            'to' => 'a@example.com, b@example.com', 'subject' => "Betreff\r\nBcc: boese@example.com",
        ]))->assertSessionHas('status');

        $email = $this->fake->sent[0]['email'];
        $this->assertCount(2, $email->getTo());
        $this->assertStringNotContainsString("\n", $email->getSubject());
        $this->assertSame(['chef@example.com'], array_map(fn ($a) => $a->getAddress(), $email->getBcc()));
    }

    public function test_other_tenants_cannot_reach_the_invoice(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $other = $this->makeTenant('Fremde GmbH');
        $otherAdmin = $this->makeUser($other, 'admin');

        $this->actingAs($otherAdmin)->withSession(['tenant_id' => $other->id]);
        $this->get(route('invoices.mail', $invoice))->assertNotFound();
        $this->post(route('invoices.mail.send', $invoice), $this->mailData())->assertNotFound();
        $this->assertCount(0, $this->fake->sent);
    }

    public function test_the_credit_note_dialog_uses_the_credit_note_text(): void
    {
        $invoice = $this->issuedInvoice($this->tenant, $this->customer, $this->accounting);

        $this->actAs($this->accounting)->post(route('invoices.credit-note', $invoice));
        Tenancy::set($this->tenant->id);
        $credit = Invoice::where('type', 'credit_note')->firstOrFail();
        $this->post(route('invoices.issue', $credit))->assertRedirect();

        $this->get(route('invoices.mail', $credit))->assertOk()->assertSee('Gutschrift G-2026-0001 von Testfirma GmbH');
    }
}
