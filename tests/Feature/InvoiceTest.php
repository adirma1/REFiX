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
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoicePdf;
use App\Services\NumberGenerator;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Festes Datum, damit Nummern (Jahr) und Fälligkeiten reproduzierbar sind.
        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');
    }

    private function formData(Customer $customer, array $overrides = []): array
    {
        return array_merge([
            'type' => 'invoice',
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-11',
            'currency' => 'EUR',
            'tax_mode' => 'standard',
            'items' => [
                ['article_id' => '', 'description' => 'Beratung', 'quantity' => '2,5', 'unit' => 'Stunde',
                    'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19'],
                // Leere Zeile wird ignoriert.
                ['article_id' => '', 'description' => '', 'quantity' => '1', 'unit' => '', 'unit_price' => '', 'discount_percent' => '', 'tax_rate' => '19'],
            ],
        ], $overrides);
    }

    /** Legt über das Formular einen Entwurf an und liefert ihn zurück. */
    private function createDraft(Tenant $tenant, User $user, Customer $customer, array $overrides = []): Invoice
    {
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/invoices', $this->formData($customer, $overrides))
            ->assertSessionHasNoErrors();

        Tenancy::set($tenant->id);

        return Invoice::latest('id')->firstOrFail();
    }

    public function test_clerk_creates_a_draft_with_server_side_totals(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $clerk = $this->makeUser($tenant, 'clerk');

        $invoice = $this->createDraft($tenant, $clerk, $customer);

        $this->assertSame('draft', $invoice->status);
        $this->assertSame('invoice', $invoice->type);
        $this->assertNull($invoice->number);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('250.00', (string) $invoice->net_total);
        $this->assertSame('47.50', (string) $invoice->tax_total);
        $this->assertSame('297.50', (string) $invoice->gross_total);
        $this->assertSame('2026-10-25', $invoice->due_date->toDateString()); // 14 Tage Standard
        $this->assertSame('Kunde 10001 GmbH', $invoice->customer_snapshot['company']);
        $this->assertSame('250.00', (string) $invoice->items->first()->net_amount);
    }

    public function test_total_discount_and_skonto_are_stored(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $clerk = $this->makeUser($tenant, 'clerk');

        $invoice = $this->createDraft($tenant, $clerk, $customer, [
            'discount_percent' => '10', 'skonto1_percent' => '2,5', 'skonto1_days' => '10', 'payment_term_days' => '30',
        ]);

        $this->assertSame('225.00', (string) $invoice->net_total);
        $this->assertSame('25.00', (string) $invoice->discount_total);
        $this->assertSame('2.50', (string) $invoice->skonto1_percent);
        $this->assertSame('2026-11-10', $invoice->due_date->toDateString());
    }

    public function test_validation_rejects_missing_items_and_foreign_customers(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant('Andere GmbH');
        $foreign = $this->makeCustomer($other, '99999');
        $own = $this->makeCustomer($tenant);
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id]);

        $this->post('/invoices', $this->formData($own, ['items' => []]))->assertSessionHasErrors('items');
        $this->post('/invoices', $this->formData($foreign))->assertSessionHasErrors('customer_id');
        $this->post('/invoices', $this->formData($own, ['currency' => 'XXX']))->assertSessionHasErrors('currency');

        Tenancy::set($tenant->id);
        $this->assertSame(0, Invoice::count());
    }

    public function test_roles_for_invoices(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $viewer = $this->makeUser($tenant, 'viewer');
        $clerk = $this->makeUser($tenant, 'clerk');

        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id]);
        $this->get('/invoices')->assertOk()->assertDontSee('Neue Rechnung');
        $this->get('/invoices/create')->assertForbidden();
        $this->post('/invoices', $this->formData($customer))->assertForbidden();

        $invoice = $this->createDraft($tenant, $clerk, $customer);

        // Sachbearbeiter dürfen Entwürfe schreiben, aber nicht freigeben.
        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id])
            ->post(route('invoices.issue', $invoice))->assertForbidden();

        // Lesezugriff darf Entwurf ansehen und PDF öffnen, aber nichts ändern.
        $this->actingAs($viewer)->withSession(['tenant_id' => $tenant->id]);
        $this->get(route('invoices.show', $invoice))->assertOk();
        $this->put(route('invoices.update', $invoice), $this->formData($customer))->assertForbidden();
        $this->delete(route('invoices.destroy', $invoice))->assertForbidden();
    }

    public function test_accounting_issues_an_invoice_and_gets_number_and_archived_pdf(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $clerk = $this->makeUser($tenant, 'clerk');
        $accounting = $this->makeUser($tenant, 'accounting');

        $invoice = $this->createDraft($tenant, $clerk, $customer);

        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id])
            ->post(route('invoices.issue', $invoice))
            ->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('R-2026-0001', $invoice->number);
        $this->assertSame($accounting->id, $invoice->issued_by);
        $this->assertNotNull($invoice->issued_at);
        $this->assertNotNull($invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($invoice->pdf_path));

        // Zweite Rechnung bekommt die nächste Nummer.
        $second = $this->createDraft($tenant, $clerk, $customer);
        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id])->post(route('invoices.issue', $second));
        $this->assertSame('R-2026-0002', $second->fresh()->number);
    }

    public function test_pdf_is_served_for_drafts_and_issued_documents(): void
    {
        $tenant = $this->makeTenant();
        $tenant->update(['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001', 'bank_name' => 'Musterbank']);
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);

        $draftPdf = $this->get(route('invoices.pdf', $invoice));
        $draftPdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $draftPdf->getContent());

        $this->post(route('invoices.issue', $invoice));

        $issuedPdf = $this->get(route('invoices.pdf', [$invoice, 'download' => 1]));
        $issuedPdf->assertOk();
        $this->assertStringContainsString('attachment', $issuedPdf->headers->get('Content-Disposition'));
        $this->assertStringContainsString('R-2026-0001.pdf', $issuedPdf->headers->get('Content-Disposition'));
    }

    public function test_archived_pdf_can_be_rebuilt_and_the_old_version_is_kept(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');
        $clerk = $this->makeUser($tenant, 'clerk');

        $invoice = $this->createDraft($tenant, $admin, $customer);

        // Entwurf: kein Archiv-PDF, also auch nichts neu zu erzeugen.
        $this->post(route('invoices.rebuild-pdf', $invoice))->assertSessionHas('warning');

        $this->post(route('invoices.issue', $invoice));
        $invoice->refresh();
        $before = $invoice->only(['number', 'status', 'gross_total', 'net_total', 'pdf_path']);

        // Ein altes Archiv-PDF (z. B. mit schwarzem Logo) simulieren.
        Storage::disk('local')->put($invoice->pdf_path, 'ALTES-PDF');

        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id])
            ->post(route('invoices.rebuild-pdf', $invoice))->assertForbidden();
        $this->assertSame('ALTES-PDF', Storage::disk('local')->get($invoice->pdf_path));

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->post(route('invoices.rebuild-pdf', $invoice))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status');

        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($invoice->pdf_path));

        $copies = Storage::disk('local')->files('invoices/'.$tenant->id.'/replaced');
        $this->assertCount(1, $copies);
        $this->assertSame('ALTES-PDF', Storage::disk('local')->get($copies[0]));

        $log = AuditLog::where('action', 'invoice.pdf_rebuilt')->first();
        $this->assertNotNull($log);
        $this->assertSame(hash('sha256', 'ALTES-PDF'), $log->details['old_sha256']);
        $this->assertSame($copies[0], $log->details['old_copy']);

        // Die Belegdaten bleiben unberührt.
        $this->assertSame($before, $invoice->fresh()->only(['number', 'status', 'gross_total', 'net_total', 'pdf_path']));

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(__('messages.invoices.rebuild_pdf'));
    }

    public function test_issued_documents_are_immutable(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);
        $this->post(route('invoices.issue', $invoice));
        $invoice->refresh();

        // Über die Oberfläche
        $this->get(route('invoices.edit', $invoice))->assertRedirect(route('invoices.show', $invoice));
        $this->put(route('invoices.update', $invoice), $this->formData($customer, ['currency' => 'USD']))
            ->assertRedirect(route('invoices.show', $invoice));
        $this->delete(route('invoices.destroy', $invoice))->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame('EUR', $invoice->fresh()->currency);

        // Direkt im Modell
        try {
            $invoice->update(['intro_text' => 'nachträglich']);
            $this->fail('Änderung eines freigegebenen Belegs hätte scheitern müssen.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $invoice->items->first()->update(['description' => 'geändert']);
    }

    public function test_internal_note_may_change_after_issue_but_not_amounts(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);
        $this->post(route('invoices.issue', $invoice));

        $invoice->refresh()->update(['internal_notes' => 'Kunde hat angerufen']);
        $this->assertSame('Kunde hat angerufen', $invoice->fresh()->internal_notes);

        $this->expectException(LogicException::class);
        $invoice->update(['gross_total' => 1]);
    }

    public function test_issued_documents_cannot_be_deleted_in_the_model(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);
        $this->post(route('invoices.issue', $invoice));

        $this->expectException(LogicException::class);
        $invoice->refresh()->delete();
    }

    public function test_draft_can_be_edited_and_deleted(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $clerk = $this->makeUser($tenant, 'clerk');

        $invoice = $this->createDraft($tenant, $clerk, $customer);

        $this->put(route('invoices.update', $invoice), $this->formData($customer, [
            'items' => [['description' => 'Neu', 'quantity' => '1', 'unit' => 'Stück', 'unit_price' => '10', 'tax_rate' => '7']],
        ]))->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame('Neu', $invoice->items->first()->description);
        $this->assertSame('10.70', (string) $invoice->gross_total);

        $this->delete(route('invoices.destroy', $invoice))->assertRedirect(route('invoices.index'));
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, InvoiceItem::count());
    }

    public function test_credit_note_is_created_from_an_issued_invoice(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);

        // Aus einem Entwurf ist keine Gutschrift möglich.
        $this->post(route('invoices.credit-note', $invoice))->assertRedirect(route('invoices.show', $invoice));
        $this->assertSame(1, Invoice::count());

        $this->post(route('invoices.issue', $invoice));
        $response = $this->post(route('invoices.credit-note', $invoice->fresh()));

        $credit = Invoice::where('type', 'credit_note')->firstOrFail();
        $response->assertRedirect(route('invoices.edit', $credit));
        $this->assertSame('draft', $credit->status);
        $this->assertSame($invoice->id, $credit->original_invoice_id);
        $this->assertSame(1, $credit->items()->count());
        $this->assertSame('297.50', (string) $credit->gross_total);
        $this->assertStringContainsString('R-2026-0001', $credit->intro_text);
        $this->assertNull($credit->due_date);

        $this->post(route('invoices.issue', $credit));
        $this->assertSame('G-2026-0001', $credit->fresh()->number);

        // Zu einer Gutschrift gibt es keine weitere Gutschrift.
        $this->post(route('invoices.credit-note', $credit->fresh()));
        $this->assertSame(1, Invoice::where('type', 'credit_note')->count());

        $this->get(route('invoices.pdf', $credit))->assertOk();
    }

    public function test_test_mode_documents_use_their_own_number_range(): void
    {
        $tenant = $this->makeTenant();
        $tenant->update(['test_mode' => true]);
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);
        $this->assertTrue($invoice->is_test);

        $this->post(route('invoices.issue', $invoice));
        $this->assertSame('TEST-R-2026-0001', $invoice->fresh()->number);

        // Echtbetrieb: Zähler der echten Rechnungen ist unberührt.
        $tenant->update(['test_mode' => false]);
        $real = $this->createDraft($tenant, $admin, $customer);
        $this->assertFalse($real->is_test);
        $this->post(route('invoices.issue', $real));
        $this->assertSame('R-2026-0001', $real->fresh()->number);
    }

    public function test_failed_issue_does_not_consume_a_number(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);

        $this->mock(InvoicePdf::class, fn ($mock) => $mock->shouldReceive('render')->andThrow(new RuntimeException('PDF kaputt')));

        $this->post(route('invoices.issue', $invoice))->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->number);
        $this->assertSame(0, NumberGenerator::lastFor($tenant, 'invoice', false, Carbon::parse('2026-10-11')));
    }

    public function test_other_tenants_cannot_see_the_invoice(): void
    {
        $tenant = $this->makeTenant();
        $other = $this->makeTenant('Andere GmbH');
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');
        $stranger = $this->makeUser($other, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);

        $this->actingAs($stranger)->withSession(['tenant_id' => $other->id]);
        $this->get(route('invoices.show', $invoice))->assertNotFound();
        $this->get(route('invoices.pdf', $invoice))->assertNotFound();
        $this->post(route('invoices.issue', $invoice))->assertNotFound();
        $this->get('/invoices')->assertOk()->assertDontSee('Kunde 10001 GmbH');
    }

    public function test_article_price_lookup_for_the_position_row(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $clerk = $this->makeUser($tenant, 'clerk');

        Tenancy::set($tenant->id);
        $article = Article::create(['name' => 'Support', 'description' => 'Remote', 'unit' => 'Stunde', 'tax_rate' => 19]);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '90', 'valid_from' => '2026-01-01']);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => '2026-06-01']);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '80', 'valid_from' => '2026-01-01', 'customer_id' => $customer->id]);
        Tenancy::clear();

        $this->actingAs($clerk)->withSession(['tenant_id' => $tenant->id]);

        $standard = $this->getJson(route('invoices.article-price', $article).'?currency=EUR&date=2026-10-11');
        $standard->assertOk()->assertJsonPath('unit', 'Stunde');
        $this->assertEquals(95, $standard->json('price'));
        $this->assertEquals(19, $standard->json('tax_rate'));
        $this->assertSame("Support\nRemote", $standard->json('description'));

        $special = $this->getJson(route('invoices.article-price', $article).'?currency=EUR&date=2026-10-11&customer='.$customer->id);
        $this->assertEquals(80, $special->json('price'));

        $none = $this->getJson(route('invoices.article-price', $article).'?currency=USD&date=2026-10-11');
        $this->assertNull($none->json('price'));
    }

    public function test_invoice_form_and_list_render(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $admin = $this->makeUser($tenant, 'admin');

        $invoice = $this->createDraft($tenant, $admin, $customer);

        $this->get('/invoices')->assertOk()->assertSee('Neue Rechnung')->assertSee('Entwurf');
        $this->get('/invoices/create')->assertOk()->assertSee('Positionen');
        $this->get('/invoices/create?type=credit_note')->assertOk()->assertSee('Neue Gutschrift');
        $this->get(route('invoices.edit', $invoice))->assertOk()->assertSee('Beratung');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Freigeben');
        $this->get('/')->assertOk()->assertSee('Entwürfe');
    }

    public function test_numbering_page_is_admin_only_and_sets_the_counter(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $accounting = $this->makeUser($tenant, 'accounting');

        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id]);
        $this->get('/numbering')->assertForbidden();

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);
        $this->get('/numbering')->assertOk()->assertSee('R-2026-0001', false);

        $year = now()->format('Y');
        $this->post('/numbering', ['type' => 'invoice', 'is_test' => 0, 'last' => 19])->assertRedirect('/numbering');
        $this->assertSame(19, NumberGenerator::lastFor($tenant, 'invoice', false, now()));
        $this->get('/numbering')->assertSee('R-'.$year.'-0020', false);

        $this->post('/numbering', ['type' => 'invoice', 'is_test' => 0, 'last' => -1])->assertSessionHasErrors('last');
    }

    public function test_tenant_number_format_must_contain_a_counter(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->put(route('tenants.update', $tenant), ['name' => 'X GmbH', 'country' => 'DE', 'default_payment_days' => 14, 'invoice_number_format' => 'RECHNUNG'])
            ->assertSessionHasErrors('invoice_number_format');

        $this->put(route('tenants.update', $tenant), ['name' => 'X GmbH', 'country' => 'DE', 'default_payment_days' => 14, 'invoice_number_format' => 'RE{YY}-{NNN}'])
            ->assertSessionHasNoErrors();

        $this->assertSame('RE{YY}-{NNN}', $tenant->fresh()->invoice_number_format);
    }

    public function test_footer_contact_fields_are_free_text(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->put(route('tenants.update', $tenant), [
                'name' => 'X GmbH', 'country' => 'DE', 'default_payment_days' => 14,
                'phone' => 'Fon: +49 160 966 035 65', 'email' => 'Email: info@example.com', 'website' => 'WEB: www.example.com',
            ])->assertSessionHasNoErrors();

        $tenant->refresh();
        $this->assertSame('Email: info@example.com', $tenant->email);

        // Formular mit Tooltips muss sich rendern lassen.
        $this->get(route('tenants.edit', $tenant))->assertOk()->assertSee('class="tip"', false);

        $footer = view('pdf.footer', ['tenant' => $tenant])->render();
        $this->assertStringContainsString('Fon: +49 160 966 035 65', $footer);
        $this->assertStringContainsString('Email: info@example.com', $footer);
        $this->assertStringContainsString('WEB: www.example.com', $footer);
    }
}
