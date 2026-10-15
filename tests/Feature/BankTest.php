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

use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bank\BankMatcher;
use App\Services\PaymentService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPaymentSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BankTest extends TestCase
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

    private function actAs(User $user, ?Tenant $tenant = null): static
    {
        $tenant ??= $this->tenant;
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
        Tenancy::set($tenant->id);

        return $this;
    }

    /**
     * CAMT.052-Datei: je [Betrag (negativ = Ausgang), Verwendungszweck, Name, optional Tag im Oktober 2026].
     * Ohne Tagesangabe bekommt Zeile i den Tag 12 + i.
     */
    private function camt(array $lines): string
    {
        $entries = '';
        foreach ($lines as $i => $line) {
            [$amount, $purpose, $name] = $line;
            $day = sprintf('%02d', $line[3] ?? 12 + $i);
            $debit = str_starts_with($amount, '-');
            $amount = str_replace(',', '.', ltrim($amount, '-'));
            $party = $debit ? 'Cdtr' : 'Dbtr';
            $entries .= '<Ntry><Amt Ccy="EUR">'.$amount.'</Amt><CdtDbtInd>'.($debit ? 'DBIT' : 'CRDT').'</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts>'
                ."<BookgDt><Dt>2026-10-{$day}</Dt></BookgDt><NtryDtls><TxDtls>"
                .'<RltdPties><'.$party.'><Pty><Nm>'.htmlspecialchars($name).'</Nm></Pty></'.$party.'></RltdPties>'
                .'<RmtInf><Ustrd>'.htmlspecialchars($purpose).'</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08"><BkToCstmrAcctRpt><Rpt>'
            .'<Acct><Id><IBAN>DE02120300000000202051</IBAN></Id></Acct>'.$entries.'</Rpt></BkToCstmrAcctRpt></Document>';
    }

    private function upload(string $content, string $name = 'auszug.xml')
    {
        return $this->actAs($this->accounting)->post(route('bank.upload'), [
            'file' => UploadedFile::fake()->createWithContent($name, $content),
        ]);
    }

    private function invoice(array $extra = [], ?Customer $customer = null): Invoice
    {
        return $this->issueInvoice($this->tenant, $customer ?? $this->customer, $this->accounting, $extra);
    }

    private function tx(string $purposeLike): BankTransaction
    {
        Tenancy::set($this->tenant->id);

        return BankTransaction::where('purpose', 'like', "%{$purposeLike}%")->firstOrFail();
    }

    public function test_camt_file_is_imported_credits_new_debits_ignored(): void
    {
        $content = $this->camt([['297,50', 'Rechnung R-2026-0001', 'Kunde 10001 GmbH'], ['-50,00', 'Kontofuehrung', 'Bank']]);

        $this->upload($content)->assertRedirect(route('bank.index'))->assertSessionHas('status');

        Tenancy::set($this->tenant->id);
        $import = BankImport::sole();
        $this->assertSame('camt', $import->format);
        $this->assertSame('DE02120300000000202051', $import->account_iban);
        $this->assertSame(2, $import->new_count);
        $this->assertSame(0, $import->duplicate_count);
        $this->assertSame($this->accounting->id, $import->imported_by);

        $credit = $this->tx('R-2026-0001');
        $this->assertSame('new', $credit->status);
        $this->assertSame('297.50', (string) $credit->amount);
        $this->assertSame('Kunde 10001 GmbH', $credit->counterparty_name);
        $this->assertSame('2026-10-12', $credit->booking_date->toDateString());

        $this->assertSame('ignored', $this->tx('Kontofuehrung')->status);
    }

    public function test_importing_the_same_or_an_overlapping_file_again_adds_nothing_twice(): void
    {
        $first = $this->camt([['100,00', 'Zahlung A', 'A GmbH'], ['200,00', 'Zahlung B', 'B GmbH']]);
        $this->upload($first);

        $this->upload($first)->assertSessionHas('status');
        Tenancy::set($this->tenant->id);
        $this->assertSame(2, BankTransaction::count());
        $this->assertSame(2, BankImport::latest('id')->first()->duplicate_count);
        $this->assertSame(0, BankImport::latest('id')->first()->new_count);

        // Überlappender Auszug: A und B kennen wir, C ist neu.
        $this->upload($this->camt([['100,00', 'Zahlung A', 'A GmbH'], ['200,00', 'Zahlung B', 'B GmbH'], ['300,00', 'Zahlung C', 'C GmbH']]));
        Tenancy::set($this->tenant->id);
        $this->assertSame(3, BankTransaction::count());
    }

    public function test_two_identical_payments_in_one_file_are_both_kept(): void
    {
        $same = $this->camt([['70,00', 'Doppelt', 'X', 20], ['70,00', 'Doppelt', 'X', 20]]);
        $this->upload($same);

        Tenancy::set($this->tenant->id);
        $this->assertSame(2, BankTransaction::where('purpose', 'Doppelt')->count());

        $this->upload($same);   // erneut: beide sind Dubletten
        Tenancy::set($this->tenant->id);
        $this->assertSame(2, BankTransaction::where('purpose', 'Doppelt')->count());
    }

    public function test_camt_file_is_imported(): void
    {
        $xml = file_get_contents(__DIR__.'/../Support/bank/sample.camt052.xml');

        $this->upload($xml, 'umsaetze.xml')->assertSessionHas('status');

        Tenancy::set($this->tenant->id);
        $this->assertSame('camt', BankImport::sole()->format);
        $this->assertSame(4, BankImport::sole()->tx_count);
        $this->assertSame(3, BankTransaction::where('status', 'new')->count());      // 1 Eingang + 2 Sammelposten
        $this->assertSame(1, BankTransaction::where('status', 'ignored')->count());  // der Ausgang
    }

    public function test_unreadable_files_are_rejected_with_a_message(): void
    {
        $this->upload('das ist keine Bankdatei')->assertRedirect(route('bank.index'))->assertSessionHas('warning');
        $this->upload(str_replace('<Sts><Cd>BOOK</Cd></Sts>', '<Sts><Cd>PDNG</Cd></Sts>', $this->camt([['5,00', 'x', 'y']])))->assertSessionHas('warning');
        $this->upload('<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"><Ntry>', 'x.xml')->assertSessionHas('warning');

        Tenancy::set($this->tenant->id);
        $this->assertSame(0, BankImport::count());
    }

    public function test_csv_goes_through_column_mapping(): void
    {
        $csv = "Buchungstag;Betrag;Verwendungszweck;Name\n13.10.2026;297,50;R-2026-0001 Danke;Kunde 10001 GmbH\n14.10.2026;-20,00;Gebühr;Bank\n";

        $response = $this->upload($csv, 'umsatz.csv');
        $response->assertRedirect();
        $this->assertMatchesRegularExpression('#/bank/csv/[a-f0-9]{32}$#', $response->headers->get('Location'));
        $location = $response->headers->get('Location');

        $this->get($location)->assertOk()->assertSee('Buchungsdatum')->assertSee('Verwendungszweck');

        $token = substr($location, -32);
        $this->post(route('bank.csv.import', $token), ['map' => ['date' => '0', 'amount' => '1', 'purpose' => '2', 'name' => '3']])
            ->assertRedirect(route('bank.index'))->assertSessionHas('status');

        Tenancy::set($this->tenant->id);
        $this->assertSame('csv', BankImport::sole()->format);
        $this->assertSame('umsatz.csv', BankImport::sole()->filename);
        $this->assertSame(29750, $this->tx('R-2026-0001')->cents());
        $this->assertSame('ignored', $this->tx('Gebühr')->status);
        $this->assertSame([], Storage::disk('local')->allFiles('bank-imports'));
    }

    public function test_csv_import_needs_date_and_amount_and_unknown_tokens_are_refused(): void
    {
        $csv = "Buchungstag;Betrag;Verwendungszweck\n13.10.2026;10,00;x\n";
        $location = $this->upload($csv, 'u.csv')->headers->get('Location');
        $token = substr($location, -32);

        $this->post(route('bank.csv.import', $token), ['map' => ['purpose' => '2']])
            ->assertRedirect($location)->assertSessionHas('warning');
        Tenancy::set($this->tenant->id);
        $this->assertSame(0, BankImport::count());

        $this->get(route('bank.csv', str_repeat('a', 32)))->assertRedirect(route('bank.index'))->assertSessionHas('warning');
    }

    public function test_matcher_suggests_invoices_with_a_confidence_level(): void
    {
        $other = $this->makeCustomer($this->tenant, '10002');
        $a = $this->invoice(['skonto1_percent' => '2', 'skonto1_days' => '10']);     // R-2026-0001, Skonto 291,55
        $b = $this->invoice([], $other);                                               // R-2026-0002

        $this->upload($this->camt([
            ['297,50', 'Rechnung R-2026-0001', 'Irgendwer'],      // Nummer + Betrag
            ['291,55', 'R2026-0001 abzgl Skonto', 'Irgendwer'],   // Nummer + Skontobetrag
            ['100,00', 'Anzahlung R-2026-0002', 'Irgendwer'],     // Nummer, anderer Betrag
            ['297,50', 'Danke und Gruss', 'Kunde 10002 GmbH'],    // nur Betrag + Name
            ['50,00', 'R-2026-0001 und R-2026-0002', 'Irgendwer'],// zwei Nummern
            ['12,00', 'Beleg R-2026-00011', 'Irgendwer'],      // Nummer nur als Teil einer längeren Zahl
        ]));

        Tenancy::set($this->tenant->id);
        $suggestions = app(BankMatcher::class)->suggest(BankTransaction::all());
        $byPurpose = fn (string $p) => $suggestions[$this->tx($p)->id] ?? null;

        $this->assertSame([$a->id, 'high'], [$byPurpose('Rechnung R-2026-0001')['invoice']->id, $byPurpose('Rechnung R-2026-0001')['confidence']]);
        $this->assertSame([$a->id, 'skonto'], [$byPurpose('Skonto')['invoice']->id, $byPurpose('Skonto')['confidence']]);
        $this->assertSame([$b->id, 'partial'], [$byPurpose('Anzahlung')['invoice']->id, $byPurpose('Anzahlung')['confidence']]);
        $this->assertSame([$b->id, 'low'], [$byPurpose('Danke')['invoice']->id, $byPurpose('Danke')['confidence']]);
        $this->assertNull($byPurpose('und R-2026-0002'));
        $this->assertNull($byPurpose('00011'));
    }

    public function test_bank_page_preselects_exact_matches_but_not_partial_ones(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'Kunde']]));

        $this->actAs($this->accounting)->get(route('bank.index'))->assertOk()
            ->assertSee('Nummer + Betrag passen')
            ->assertSee('R-2026-0001 – Kunde 10001 GmbH – 297,50 EUR')
            ->assertSee('Markierte buchen');
        $this->get(route('bank.index'))->assertSee('checked', false);
    }

    public function test_booking_a_bank_transaction_creates_the_payment_and_marks_it_booked(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'Kunde']]));
        $tx = $this->tx('R-2026-0001');

        $this->actAs($this->accounting)->post(route('bank.book'), [
            'book' => [$tx->id => '1'], 'invoice' => [$tx->id => $a->id],
        ])->assertRedirect(route('bank.index'))->assertSessionHas('status');

        Tenancy::set($this->tenant->id);
        $payment = Payment::sole();
        $this->assertSame($a->id, $payment->invoice_id);
        $this->assertSame('297.50', (string) $payment->amount);
        $this->assertSame('2026-10-12', $payment->paid_on->toDateString());
        $this->assertSame($tx->id, $payment->bank_transaction_id);

        $tx = $tx->fresh();
        $this->assertSame('booked', $tx->status);
        $this->assertSame($payment->id, $tx->payment_id);
        $this->assertSame('paid', app(PaymentService::class)->summary($a->fresh())['status']);

        // Erneutes Absenden bucht nichts doppelt.
        $this->actAs($this->accounting)->post(route('bank.book'), ['book' => [$tx->id => '1'], 'invoice' => [$tx->id => $a->id]]);
        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Payment::count());
    }

    public function test_booking_with_cash_discount_and_partial_amounts(): void
    {
        $a = $this->invoice(['skonto1_percent' => '2', 'skonto1_days' => '10']);
        $b = $this->invoice();
        $this->upload($this->camt([
            ['291,55', 'Skontozahlung R-2026-0001', 'X'],
            ['100,00', 'Teilzahlung R-2026-0002', 'Y'],
        ]));
        $skonto = $this->tx('Skontozahlung');
        $partial = $this->tx('Teilzahlung');

        $this->actAs($this->accounting)->post(route('bank.book'), [
            'book' => [$skonto->id => '1', $partial->id => '1'],
            'invoice' => [$skonto->id => $a->id, $partial->id => $b->id],
            'skonto' => [$skonto->id => '1'],
        ])->assertSessionHas('status');

        Tenancy::set($this->tenant->id);
        $service = app(PaymentService::class);
        $this->assertSame('paid', $service->summary($a->fresh())['status']);
        $this->assertSame(595, $service->summary($a->fresh())['discount']);
        $this->assertSame('partial', $service->summary($b->fresh())['status']);
        $this->assertSame(3, Payment::count());
    }

    public function test_rows_without_an_invoice_or_with_a_wrong_skonto_flag_are_reported_and_stay_open(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Eins', 'X'], ['100,00', 'Zwei', 'Y']]));
        $one = $this->tx('Eins');
        $two = $this->tx('Zwei');

        $this->actAs($this->accounting)->post(route('bank.book'), [
            'book' => [$one->id => '1', $two->id => '1'],
            'invoice' => [$two->id => $a->id],
            'skonto' => [$two->id => '1'],     // die Rechnung hat gar kein Skonto
        ])->assertSessionHas('warning');

        Tenancy::set($this->tenant->id);
        $this->assertSame(0, Payment::count());
        $this->assertSame(2, BankTransaction::where('status', 'new')->count());

        $this->post(route('bank.book'), [])->assertSessionHas('warning');
    }

    public function test_reversing_a_payment_reopens_the_bank_transaction(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'Kunde']]));
        $tx = $this->tx('R-2026-0001');
        $this->actAs($this->accounting)->post(route('bank.book'), ['book' => [$tx->id => '1'], 'invoice' => [$tx->id => $a->id]]);

        Tenancy::set($this->tenant->id);
        $this->post(route('payments.reverse', Payment::sole()), ['reason' => 'falsche Zuordnung']);

        Tenancy::set($this->tenant->id);
        $tx = $tx->fresh();
        $this->assertSame('new', $tx->status);
        $this->assertNull($tx->payment_id);

        // ... und lässt sich nun der richtigen Rechnung zuordnen.
        $this->post(route('bank.book'), ['book' => [$tx->id => '1'], 'invoice' => [$tx->id => $a->id]])->assertSessionHas('status');
        Tenancy::set($this->tenant->id);
        $this->assertSame(1, Payment::active()->count());
    }

    public function test_new_tab_has_toolbar_and_ignore_many_marks_selected_transactions(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'K'], ['10,00', 'Privat', 'P'], ['5,00', 'Sonstiges', 'S']]));
        $private = $this->tx('Privat');
        $other = $this->tx('Sonstiges');
        $booked = $this->tx('R-2026-0001');

        $this->actAs($this->accounting)->get(route('bank.index'))->assertOk()
            ->assertSee('data-multi', false)->assertSee(route('bank.ignore-many'), false)
            ->assertSee(__('messages.bank.ignore_selected'));

        $this->post(route('bank.ignore-many'), ['book' => [$private->id => '1', $other->id => '1']])
            ->assertRedirect(route('bank.index'))->assertSessionHas('status');
        $this->assertSame('ignored', $private->fresh()->status);
        $this->assertSame('ignored', $other->fresh()->status);
        $this->assertSame('new', $booked->fresh()->status);

        $this->post(route('bank.ignore-many'), ['book' => []])->assertSessionHas('warning');

        $this->get(route('bank.index', ['tab' => 'ignored']))->assertOk()
            ->assertSee('data-restore="'.route('bank.restore', $private).'"', false)->assertSee(__('messages.bank.restore'));

        $this->post(route('bank.book'), ['book' => [$booked->id => '1'], 'invoice' => [$booked->id => $a->id]]);
        $this->get(route('bank.index', ['tab' => 'booked']))->assertOk()
            ->assertSee('data-open="'.route('invoices.show', $a).'#payments"', false)->assertSee(__('messages.bank.to_invoice'));

        $this->actAs($this->clerk)->post(route('bank.ignore-many'), ['book' => [$private->id => '1']])->assertForbidden();
    }

    public function test_transactions_can_be_ignored_and_restored_but_booked_ones_cannot(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'K'], ['10,00', 'Privat', 'P']]));
        $booked = $this->tx('R-2026-0001');
        $private = $this->tx('Privat');

        $this->actAs($this->accounting)->post(route('bank.ignore', $private))->assertRedirect(route('bank.index'));
        $this->assertSame('ignored', $private->fresh()->status);

        $this->get(route('bank.index', ['tab' => 'ignored']))->assertOk()->assertSee('Privat')->assertSee('Wieder öffnen');

        $this->post(route('bank.restore', $private))->assertRedirect();
        $this->assertSame('new', $private->fresh()->status);

        $this->post(route('bank.book'), ['book' => [$booked->id => '1'], 'invoice' => [$booked->id => $a->id]]);
        $this->post(route('bank.ignore', $booked));
        $this->post(route('bank.restore', $booked));
        $this->assertSame('booked', $booked->fresh()->status);

        $this->get(route('bank.index', ['tab' => 'booked']))->assertOk()->assertSee('R-2026-0001');
    }

    public function test_only_admin_and_accounting_may_use_the_bank_pages(): void
    {
        $a = $this->invoice();
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'K']]));
        $tx = $this->tx('R-2026-0001');

        $this->actAs($this->clerk);
        $this->get(route('bank.index'))->assertForbidden();
        $this->post(route('bank.upload'), ['file' => UploadedFile::fake()->createWithContent('a.xml', $this->camt([['1,00', 'x', 'y']]))])->assertForbidden();
        $this->post(route('bank.book'), ['book' => [$tx->id => '1'], 'invoice' => [$tx->id => $a->id]])->assertForbidden();
        $this->post(route('bank.ignore', $tx))->assertForbidden();

        $this->actAs($this->admin)->get(route('bank.index'))->assertOk();

        Tenancy::set($this->tenant->id);
        $this->assertSame(0, Payment::count());
    }

    public function test_menu_shows_payments_only_to_those_who_may_use_them(): void
    {
        $this->actAs($this->accounting)->get(route('dashboard'))->assertSee(route('payments.index'), false);
        $this->actAs($this->clerk)->get(route('dashboard'))->assertDontSee(route('payments.index'), false);
    }

    public function test_tenants_do_not_see_or_change_each_others_bank_data(): void
    {
        $this->upload($this->camt([['297,50', 'Rechnung R-2026-0001', 'K']]));
        $tx = $this->tx('R-2026-0001');

        $other = $this->makeTenant('Fremdfirma GmbH');
        $otherUser = $this->makeUser($other, 'accounting');

        $this->actAs($otherUser, $other);
        $this->get(route('bank.index'))->assertOk()->assertDontSee('R-2026-0001');
        $this->post(route('bank.ignore', $tx))->assertNotFound();

        // Dieselbe Datei beim anderen Mandanten ist keine Dublette.
        $this->post(route('bank.upload'), ['file' => UploadedFile::fake()->createWithContent('a.xml', $this->camt([['297,50', 'Rechnung R-2026-0001', 'K']]))])
            ->assertSessionHas('status');
        Tenancy::set($other->id);
        $this->assertSame(1, BankTransaction::count());

        Tenancy::set($this->tenant->id);
        $this->assertSame('new', $tx->fresh()->status);
        $this->assertSame(1, BankTransaction::count());
    }
}
