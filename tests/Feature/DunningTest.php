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
use App\Models\Dunning;
use App\Models\DunningHold;
use App\Models\Invoice;
use App\Models\LayoutVersion;
use App\Models\MailLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DunningInvalid;
use App\Services\DunningService;
use App\Services\Layout\LayoutService;
use App\Services\PaymentService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesPaymentSetup;
use Tests\Concerns\CreatesTenants;
use Tests\Support\FakeMailSender;
use Tests\TestCase;

class DunningTest extends TestCase
{
    use CreatesMailSetup, CreatesPaymentSetup, CreatesTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private User $accounting;

    private User $clerk;

    private User $viewer;

    private Customer $customer;

    private Invoice $invoice;

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

        // R-2026-0001: 297,50 €, Datum 11.10.2026, fällig 25.10.2026
        $this->invoice = $this->issueInvoice($this->tenant, $this->customer, $this->accounting);
    }

    private function actAs(User $user): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => $this->tenant->id]);
        Tenancy::set($this->tenant->id);

        return $this;
    }

    private function service(): DunningService
    {
        Tenancy::set($this->tenant->id);

        return app(DunningService::class);
    }

    private function at(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 10:00:00'));
        Tenancy::set($this->tenant->id);
    }

    private function suggestionLevels(): array
    {
        return $this->service()->suggestions()->mapWithKeys(fn ($row) => [$row['invoice']->number => $row['level']])->all();
    }

    private function create(?Invoice $invoice = null): Dunning
    {
        return $this->service()->create($invoice ?? $this->invoice, $this->accounting);
    }

    public function test_invoice_is_suggested_only_after_the_configured_days_past_due(): void
    {
        $this->at('2026-10-31');   // fällig war der 25.10., 7 Tage Karenz → ab 01.11.
        $this->assertSame([], $this->suggestionLevels());

        $this->at('2026-11-01');
        $this->assertSame(['R-2026-0001' => 1], $this->suggestionLevels());

        $this->tenant->update(['dunning_days_after_due' => 0]);
        $this->at('2026-10-26');
        $this->assertSame(['R-2026-0001' => 1], $this->suggestionLevels());
    }

    public function test_dunning_pdf_uses_the_active_dunning_layout_and_otherwise_the_builtin_one(): void
    {
        $this->at('2026-11-01');

        // Ohne eigenes Layout wird das eingebaute verwendet.
        $this->mock(LayoutService::class, fn ($mock) => $mock->shouldReceive('render')->never());
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($this->create()->pdf_path));
        $this->app->forgetInstance(LayoutService::class);

        // Ein aktives Rechnungs-Layout ändert daran nichts, ein aktives Mahn-Layout wird verwendet.
        $layouts = app(LayoutService::class);
        [$body, $footer] = LayoutService::standardTemplate('dunning');
        $layout = $layouts->createVersion($this->tenant, $this->admin, 'Mahn-Test', $body, $footer, LayoutService::DEFAULT_MARGINS, 'dunning');
        $layouts->activate($layout);
        $this->assertSame($layout->id, LayoutVersion::activeFor($this->tenant->id, 'dunning')->id);
        $this->assertNull(LayoutVersion::activeFor($this->tenant->id));

        $second = $this->issueInvoice($this->tenant, $this->customer, $this->accounting);
        $this->tenant->update(['dunning_days_after_due' => 0]);
        $this->at('2026-11-02');

        $this->mock(LayoutService::class, fn ($mock) => $mock->shouldReceive('render')->once()->andReturn(['<p>eigenes Mahn-Layout</p>', '<p>Fuß</p>']));
        $dunning = $this->create($second);

        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($dunning->pdf_path));
    }

    public function test_creating_a_reminder_archives_the_pdf_and_leaves_the_invoice_untouched(): void
    {
        $this->at('2026-11-01');
        $before = $this->invoice->fresh()->getAttributes();

        $dunning = $this->create();

        $this->assertSame(1, $dunning->level);
        $this->assertSame('2026-11-08', $dunning->pay_by->toDateString());
        $this->assertSame('297.50', (string) $dunning->open_amount);
        $this->assertSame('0.00', (string) $dunning->fee);
        $this->assertSame('297.50', (string) $dunning->total);
        $this->assertSame($this->accounting->id, $dunning->created_by);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($dunning->pdf_path));
        $this->assertNotNull(AuditLog::where('action', 'dunning.created')->first());
        $this->assertSame($before, $this->invoice->fresh()->getAttributes());

        // Bis zum Versand ist die Rechnung nicht mehr im Vorschlag.
        $this->assertSame([], $this->suggestionLevels());
        $this->expectException(DunningInvalid::class);
        $this->create();
    }

    public function test_next_level_comes_only_after_the_deadline_of_a_sent_letter(): void
    {
        $this->at('2026-11-01');
        $first = $this->create();
        $this->service()->markSent($first, $this->accounting, 'post');

        $this->at('2026-11-08');   // Frist läuft am 08.11. noch
        $this->assertSame([], $this->suggestionLevels());

        $this->at('2026-11-09');
        $this->assertSame(['R-2026-0001' => 2], $this->suggestionLevels());
        $second = $this->create();
        $this->service()->markSent($second, $this->accounting, 'post');

        $this->at('2026-11-17');
        $this->assertSame(['R-2026-0001' => 3], $this->suggestionLevels());
        $third = $this->create();
        $this->service()->markSent($third, $this->accounting, 'post');

        // Nach der letzten Mahnung schlägt die App nichts mehr vor.
        $this->at('2026-12-31');
        $this->assertSame([], $this->suggestionLevels());
    }

    public function test_fee_and_default_interest_are_calculated_from_the_second_step(): void
    {
        $this->tenant->update([
            'dunning_fee_1' => 0, 'dunning_fee_2' => 5, 'dunning_fee_3' => 10,
            'dunning_interest_enabled' => true, 'dunning_base_rate' => 3, 'dunning_interest_margin' => 9,
        ]);
        $this->at('2026-11-09');

        $service = $this->service();
        $tenant = $this->tenant->fresh();
        $summary = app(PaymentService::class)->summary($this->invoice->fresh());
        $invoice = $this->invoice->fresh();

        // Stufe 1: keine Zinsen, keine Gebühr
        $one = $service->calculate($invoice, $summary, 1, $tenant, today());
        $this->assertSame([0, 0, 29750], [$one['fee'], $one['interest'], $one['total']]);

        // Stufe 2: 297,50 × 12 % × 15 Tage / 365 = 1,467… → 1,47; plus 5,00 Gebühr
        $two = $service->calculate($invoice, $summary, 2, $tenant, today());
        $this->assertSame(500, $two['fee']);
        $this->assertSame(147, $two['interest']);
        $this->assertSame(15, $two['days']);
        $this->assertEquals(12.0, $two['rate']);
        $this->assertSame(29750 + 500 + 147, $two['total']);

        $three = $service->calculate($invoice, $summary, 3, $tenant, today());
        $this->assertSame(1000, $three['fee']);
    }

    public function test_no_interest_without_activation_or_base_rate(): void
    {
        $this->tenant->update(['dunning_interest_enabled' => true, 'dunning_base_rate' => null]);
        $this->at('2026-11-09');
        $invoice = $this->invoice->fresh();
        $summary = app(PaymentService::class)->summary($invoice);

        $this->assertSame(0, $this->service()->calculate($invoice, $summary, 2, $this->tenant->fresh(), today())['interest']);

        $this->tenant->update(['dunning_interest_enabled' => false, 'dunning_base_rate' => 3]);
        $this->assertSame(0, $this->service()->calculate($invoice, $summary, 2, $this->tenant->fresh(), today())['interest']);
    }

    public function test_dunning_uses_the_still_open_amount_and_paid_invoices_are_not_chased(): void
    {
        $this->actAs($this->accounting)->post(route('payments.store', $this->invoice), ['amount' => '100', 'paid_on' => '2026-10-11']);
        $this->at('2026-11-01');

        $dunning = $this->create();
        $this->assertSame('197.50', (string) $dunning->open_amount);
        $this->service()->markSent($dunning, $this->accounting, 'post');

        $this->actAs($this->accounting)->post(route('payments.store', $this->invoice), ['amount' => '197,50', 'paid_on' => '2026-11-01']);
        $this->at('2026-12-01');
        $this->assertSame([], $this->suggestionLevels());
    }

    public function test_test_mode_documents_and_invoices_without_due_date_are_never_chased(): void
    {
        $this->tenant->update(['test_mode' => true]);
        $test = $this->issueInvoice($this->tenant, $this->customer, $this->accounting);
        $this->assertTrue($test->is_test);

        $this->at('2026-12-01');
        $this->assertSame(['R-2026-0001' => 1], $this->suggestionLevels());   // nur die echte Rechnung

        $this->expectException(DunningInvalid::class);
        $this->service()->create($test, $this->accounting);
    }

    public function test_hold_blocks_dunning_until_released_or_expired(): void
    {
        $this->at('2026-11-01');

        $this->actAs($this->accounting)->post(route('dunning.hold', $this->invoice), ['reason' => 'Reklamation', 'hold_until' => '2026-11-10'])
            ->assertSessionHas('status');
        $this->assertSame([], $this->suggestionLevels());

        $this->at('2026-11-11');   // befristete Sperre abgelaufen
        $this->assertSame(['R-2026-0001' => 1], $this->suggestionLevels());

        $this->actAs($this->accounting)->post(route('dunning.hold', $this->invoice), ['reason' => 'Streit']);
        $this->assertSame([], $this->suggestionLevels());

        $hold = DunningHold::active()->sole();
        $this->post(route('dunning.release', $hold))->assertSessionHas('status');
        $this->assertSame(['R-2026-0001' => 1], $this->suggestionLevels());

        $this->post(route('dunning.hold', $this->invoice), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('dunning.hold', $this->invoice), ['reason' => 'x', 'hold_until' => '2020-01-01'])->assertSessionHasErrors('hold_until');
    }

    public function test_pending_letters_can_be_discarded_sent_ones_cannot(): void
    {
        $this->at('2026-11-01');
        $dunning = $this->create();

        $this->actAs($this->accounting)->post(route('dunning.void', $dunning))->assertSessionHas('status');
        $this->assertTrue($dunning->fresh()->isVoided());
        // Eine verworfene Mahnung zählt nicht: dieselbe Stufe wird wieder vorgeschlagen.
        $this->assertSame(['R-2026-0001' => 1], $this->suggestionLevels());

        $again = $this->create();
        $this->post(route('dunning.sent', $again))->assertSessionHas('status');
        $this->assertSame('post', $again->fresh()->sent_via);
        $this->assertSame($this->accounting->id, $again->fresh()->sent_by);

        $this->post(route('dunning.void', $again))->assertSessionHas('warning');
        $this->assertFalse($again->fresh()->isVoided());
    }

    public function test_letter_text_contains_the_filled_in_values_and_tenant_texts_win(): void
    {
        $this->at('2026-11-01');
        $dunning = $this->create()->load('invoice');
        $service = $this->service();

        $text = $service->letterText($dunning, $this->tenant->fresh());
        $this->assertStringContainsString('R-2026-0001', $text);
        $this->assertStringContainsString('297,50 EUR', $text);
        $this->assertStringContainsString('25.10.2026', $text);
        $this->assertStringContainsString('08.11.2026', $text);
        $this->assertStringNotContainsString('{', $text);

        $this->tenant->update(['dunning_text_1' => 'Eigener Text zu {belegnummer}']);
        $this->assertSame('Eigener Text zu R-2026-0001', $service->letterText($dunning, $this->tenant->fresh()));
    }

    public function test_pages_show_selection_and_toolbar_instead_of_row_buttons(): void
    {
        $this->at('2026-11-01');
        $this->actAs($this->accounting)->get(route('dunning.index'))->assertOk()
            ->assertSee('id="due-form"', false)->assertSee('class="pick"', false)
            ->assertSee(__('messages.dunning.create_selected'))->assertSee(__('messages.dunning.hold_selected'))
            ->assertSee(route('dunning.hold-many'), false);

        $this->post(route('dunning.store'), ['invoices' => [$this->invoice->id]]);
        $dunning = Dunning::sole();

        $this->get(route('dunning.index', ['tab' => 'history']))->assertOk()
            ->assertSee('id="hist-actions"', false)->assertSee('type="radio"', false)
            ->assertSee('data-pdf="'.route('dunning.pdf', $dunning).'"', false)
            ->assertSee('data-mail="'.route('dunning.mail', $dunning).'"', false)
            ->assertSee('data-sent="'.route('dunning.sent', $dunning).'"', false)
            ->assertSee('data-void="'.route('dunning.void', $dunning).'"', false);

        $this->post(route('dunning.void', $dunning));
        $this->get(route('dunning.index', ['tab' => 'history']))->assertOk()
            ->assertDontSee('data-pdf=', false)->assertDontSee('data-void=', false);
    }

    public function test_hold_many_sets_a_hold_for_every_marked_invoice(): void
    {
        $this->at('2026-11-01');
        $this->actAs($this->accounting)->post(route('dunning.hold-many'), [
            'invoices' => [$this->invoice->id], 'reason' => 'Reklamation', 'hold_until' => '2026-12-01',
        ])->assertRedirect(route('dunning.index', ['tab' => 'holds']))->assertSessionHas('status');

        $hold = DunningHold::active()->sole();
        $this->assertSame($this->invoice->id, $hold->invoice_id);
        $this->assertSame('Reklamation', $hold->reason);

        $this->post(route('dunning.hold-many'), ['invoices' => [], 'reason' => 'x'])->assertSessionHasErrors('invoices');
        $this->post(route('dunning.hold-many'), ['invoices' => [$this->invoice->id], 'reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('dunning.hold-many'), ['invoices' => [$this->invoice->id], 'reason' => 'x', 'hold_until' => '2020-01-01'])->assertSessionHasErrors('hold_until');
        $this->assertSame(1, DunningHold::active()->count());

        $this->actAs($this->clerk)->post(route('dunning.hold-many'), ['invoices' => [$this->invoice->id], 'reason' => 'x'])->assertForbidden();
    }

    public function test_pages_and_pdf_download_work_for_accounting(): void
    {
        $this->at('2026-11-01');
        $this->actAs($this->accounting)->get(route('dunning.index'))->assertOk()
            ->assertSee('R-2026-0001')->assertSee('Zahlungserinnerung')->assertSee('297,50');

        $this->post(route('dunning.store'), ['invoices' => [$this->invoice->id]])
            ->assertRedirect(route('dunning.index', ['tab' => 'history']))->assertSessionHas('status');

        $this->get(route('dunning.index', ['tab' => 'history']))->assertOk()->assertSee(__('messages.dunning.status_pending'))->assertSee('R-2026-0001');
        $this->get(route('dunning.index', ['tab' => 'holds']))->assertOk();

        $dunning = Dunning::sole();
        $response = $this->get(route('dunning.pdf', $dunning))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        $this->get(route('invoices.show', $this->invoice))->assertOk()->assertSee('Zahlungserinnerung');

        // Zweites Absenden erstellt nichts doppelt.
        $this->post(route('dunning.store'), ['invoices' => [$this->invoice->id]])->assertSessionHas('warning');
        $this->assertSame(1, Dunning::count());
        $this->post(route('dunning.store'), [])->assertSessionHas('warning');
    }

    public function test_mail_sends_the_archived_pdf_marks_the_letter_sent_and_logs_it(): void
    {
        $fake = $this->fakeMailer();
        $this->tenant->update(['mail_account_id' => $this->makeMailAccount(['bcc' => 'chef@example.com'])->id]);
        $this->at('2026-11-01');
        $dunning = $this->create();

        $this->actAs($this->accounting)->get(route('dunning.mail', $dunning))->assertOk()
            ->assertSee('kunde10001@example.com')->assertSee('Zahlungserinnerung zu Rechnung R-2026-0001')->assertSee('08.11.2026');

        $this->post(route('dunning.mail.send', $dunning), [
            'to' => 'kunde10001@example.com', 'subject' => 'Zahlungserinnerung zu Rechnung R-2026-0001', 'body' => 'Hallo',
        ])->assertRedirect(route('dunning.index', ['tab' => 'history']))->assertSessionHas('status');

        $this->assertCount(1, $fake->sent);
        $email = $fake->sent[0]['email'];
        $this->assertSame(['chef@example.com'], array_map(fn ($a) => $a->getAddress(), $email->getBcc()));
        $this->assertSame('Zahlungserinnerung-R-2026-0001.pdf', $email->getAttachments()[0]->getFilename());
        $this->assertSame(Storage::disk('local')->get($dunning->pdf_path), $email->getAttachments()[0]->getBody());

        $dunning = $dunning->fresh();
        $this->assertTrue($dunning->isSent());
        $this->assertSame('mail', $dunning->sent_via);

        Tenancy::set($this->tenant->id);
        $log = MailLog::sole();
        $this->assertSame($dunning->id, (int) $log->dunning_id);
        $this->assertSame($this->invoice->id, $log->invoice_id);
        $this->assertNotNull(AuditLog::where('action', 'dunning.mailed')->first());
    }

    public function test_failed_mail_does_not_mark_the_letter_as_sent(): void
    {
        $fake = $this->fakeMailer();
        $fake->fail = new \RuntimeException('Verbindung abgelehnt');
        $this->tenant->update(['mail_account_id' => $this->makeMailAccount()->id]);
        $this->at('2026-11-01');
        $dunning = $this->create();

        $this->actAs($this->accounting)->post(route('dunning.mail.send', $dunning), [
            'to' => 'kunde10001@example.com', 'subject' => 'x', 'body' => 'y',
        ])->assertRedirect(route('dunning.mail', $dunning))->assertSessionHas('warning');

        $this->assertFalse($dunning->fresh()->isSent());
        Tenancy::set($this->tenant->id);
        $this->assertSame('failed', MailLog::sole()->status);
    }

    public function test_mail_needs_the_send_right_and_an_account(): void
    {
        $this->fakeMailer();
        $this->at('2026-11-01');
        $dunning = $this->create();

        // Ohne Zugang
        $this->actAs($this->accounting)->get(route('dunning.mail', $dunning))->assertRedirect()->assertSessionHas('warning');

        $this->tenant->update(['mail_account_id' => $this->makeMailAccount()->id, 'mail_send_roles' => []]);
        $this->actAs($this->clerk)->get(route('dunning.mail', $dunning))->assertForbidden();
        $this->actAs($this->viewer)->get(route('dunning.mail', $dunning))->assertForbidden();
    }

    public function test_only_admin_and_accounting_may_use_dunning_and_only_admin_the_settings(): void
    {
        $this->at('2026-11-01');
        $dunning = $this->create();

        foreach ([$this->clerk, $this->viewer] as $user) {
            $this->actAs($user);
            $this->get(route('dunning.index'))->assertForbidden();
            $this->post(route('dunning.store'), ['invoices' => [$this->invoice->id]])->assertForbidden();
            $this->get(route('dunning.pdf', $dunning))->assertForbidden();
            $this->post(route('dunning.hold', $this->invoice), ['reason' => 'x'])->assertForbidden();
            $this->post(route('dunning.void', $dunning))->assertForbidden();
        }

        $this->actAs($this->accounting)->get(route('dunning.settings'))->assertForbidden();
        $this->post(route('dunning.settings.update'), [])->assertForbidden();

        $this->actAs($this->admin)->get(route('dunning.settings'))->assertOk()->assertSee('Zahlungserinnerung');
    }

    public function test_settings_are_validated_and_saved(): void
    {
        $this->actAs($this->admin);
        $valid = [
            'dunning_days_after_due' => '10', 'dunning_grace_days' => '14',
            'dunning_fee_1' => '0', 'dunning_fee_2' => '5,00', 'dunning_fee_3' => '10',
            'dunning_interest_enabled' => '1', 'dunning_base_rate' => '2,27', 'dunning_interest_margin' => '9',
        ];

        $this->post(route('dunning.settings.update'), $valid)->assertRedirect(route('dunning.settings'))->assertSessionHas('status');

        $tenant = $this->tenant->fresh();
        $this->assertSame(10, $tenant->dunning_days_after_due);
        $this->assertSame(14, $tenant->dunning_grace_days);
        $this->assertSame('5.00', (string) $tenant->dunning_fee_2);
        $this->assertTrue($tenant->dunning_interest_enabled);
        $this->assertSame('2.27', (string) $tenant->dunning_base_rate);
        $this->assertNull($tenant->dunning_text_1);

        // Zinsen ohne Basiszinssatz geht nicht.
        $this->post(route('dunning.settings.update'), array_merge($valid, ['dunning_base_rate' => '']))->assertSessionHasErrors('dunning_base_rate');
        // Fristen und Beträge im erlaubten Bereich
        $this->post(route('dunning.settings.update'), array_merge($valid, ['dunning_grace_days' => '0']))->assertSessionHasErrors('dunning_grace_days');
        $this->post(route('dunning.settings.update'), array_merge($valid, ['dunning_fee_2' => '-1']))->assertSessionHasErrors('dunning_fee_2');
        // Unbekannte Platzhalter werden abgefangen
        $this->post(route('dunning.settings.update'), array_merge($valid, ['dunning_text_2' => 'Hallo {kundenname}']))->assertSessionHasErrors('dunning_text_2');

        // Eigener Text wird gespeichert, der Standardtext nicht.
        $this->post(route('dunning.settings.update'), array_merge($valid, [
            'dunning_text_1' => 'Bitte zahlen bis {frist}.', 'dunning_text_2' => DunningService::defaultText(2),
        ]))->assertSessionHasNoErrors();
        $tenant = $this->tenant->fresh();
        $this->assertSame('Bitte zahlen bis {frist}.', $tenant->dunning_text_1);
        $this->assertNull($tenant->dunning_text_2);
    }

    public function test_tenants_do_not_see_each_others_dunnings(): void
    {
        $this->at('2026-11-01');
        $dunning = $this->create();

        $other = $this->makeTenant('Fremdfirma GmbH');
        $otherUser = $this->makeUser($other, 'admin');
        $this->actingAs($otherUser)->withSession(['tenant_id' => $other->id]);
        Tenancy::set($other->id);

        $this->get(route('dunning.pdf', $dunning))->assertNotFound();
        $this->post(route('dunning.void', $dunning))->assertNotFound();
        $this->get(route('dunning.index'))->assertOk()->assertDontSee('R-2026-0001');
        $this->get(route('dunning.index', ['tab' => 'history']))->assertOk()->assertDontSee('R-2026-0001');
    }
}
