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

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Locale;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class InvoiceService
{
    public function __construct(private InvoicePdf $pdf) {}

    /**
     * Entwurf anlegen oder aktualisieren.
     *
     * @param  array<string, mixed>  $data  validierte Kopfdaten
     * @param  array<int, array<string, mixed>>  $items  validierte Positionen
     */
    public function saveDraft(?Invoice $invoice, array $data, array $items, User $user, string $type = Invoice::TYPE_INVOICE): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $items, $user, $type) {
            $tenant = Tenant::findOrFail(Tenancy::id());
            $customer = Customer::findOrFail($data['customer_id']);
            $taxMode = $data['tax_mode'] ?? 'standard';

            if ($invoice === null) {
                $invoice = new Invoice([
                    'type' => $type,
                    'status' => Invoice::STATUS_DRAFT,
                    'is_test' => $tenant->test_mode,
                    'created_by' => $user->id,
                ]);
            } elseif (! $invoice->isDraft()) {
                throw new RuntimeException('Nur Entwürfe können bearbeitet werden.');
            }

            $isCredit = $invoice->type === Invoice::TYPE_CREDIT_NOTE;

            // Inaktive Kunden und Kunden nach ihrem „Kunde bis“-Datum bekommen keine neuen Rechnungen (Gutschriften schon).
            if (! $isCredit && $customer->isBlockedFor($data['issue_date'] ?? null)) {
                throw new RuntimeException($customer->blockedMessage());
            }

            $series = $this->resolveSeries($isCredit, $data, $customer, (string) ($data['currency'] ?? 'EUR'), (bool) $tenant->test_mode, $invoice);
            $data = array_merge($data, $series);
            $discount = (float) ($data['discount_percent'] ?? 0);
            $calc = InvoiceCalculator::calculate($items, $discount, $taxMode);

            $invoice->fill(array_merge($data, [
                'customer_snapshot' => $this->snapshot($customer),
                'tax_mode' => $taxMode,
                'discount_percent' => $discount,
                'net_total' => $calc['net_total'],
                'discount_total' => $calc['discount_total'],
                'tax_total' => $calc['tax_total'],
                'gross_total' => $calc['gross_total'],
                'tax_breakdown' => $calc['groups'],
            ]));

            if ($isCredit) {
                // Gutschriften haben kein Zahlungsziel und kein Skonto.
                $invoice->fill([
                    'payment_term_days' => null, 'due_date' => null,
                    'skonto1_percent' => null, 'skonto1_days' => null, 'skonto2_percent' => null, 'skonto2_days' => null,
                ]);
            } else {
                $days = $invoice->payment_term_days ?? $customer->payment_term_days ?? $tenant->default_payment_days;
                $invoice->payment_term_days = $days;
                $invoice->due_date = $invoice->issue_date->copy()->addDays((int) $days);
            }

            $invoice->save();

            InvoiceItem::where('invoice_id', $invoice->id)->delete();
            $this->storeItems($invoice, $items, $calc['lines'], $taxMode);

            return $invoice->refresh();
        });
    }

    /** Freigabe: Nummer vergeben, Werte einfrieren, PDF archivieren. Alles oder nichts. */
    public function issue(Invoice $invoice, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isDraft()) {
                throw new RuntimeException('Der Beleg ist bereits freigegeben.');
            }

            $items = $invoice->items()->get();

            if ($items->isEmpty()) {
                throw new RuntimeException('Ein Beleg ohne Positionen kann nicht freigegeben werden.');
            }

            $tenant = Tenant::findOrFail($invoice->tenant_id);
            $customer = Customer::withTrashed()->findOrFail($invoice->customer_id);

            if ($invoice->type !== Invoice::TYPE_CREDIT_NOTE && $customer->isBlockedFor($invoice->issue_date)) {
                throw new RuntimeException($customer->blockedMessage());
            }

            if ($invoice->inSeries()) {
                // Die Wurzel der Serie sperren, damit zwei gleichzeitige Freigaben nicht dieselbe Nummer bekommen.
                if ($invoice->series_invoice_id !== null) {
                    Invoice::lockForUpdate()->find($invoice->series_invoice_id);
                }

                if ($this->seriesIsClosed($invoice->seriesRootId(), $invoice)) {
                    throw new RuntimeException(__('messages.invoices.series_err_closed'));
                }

                $invoice->series_no = $invoice->seriesMembers()->count() + 1;
            }

            $calc = InvoiceCalculator::calculate(
                $items->map(fn ($i) => $i->only(['quantity', 'unit_price', 'discount_percent', 'tax_rate']))->all(),
                (float) $invoice->discount_percent,
                $invoice->tax_mode
            );

            $invoice->fill([
                'customer_snapshot' => $this->snapshot($customer),
                'net_total' => $calc['net_total'],
                'discount_total' => $calc['discount_total'],
                'tax_total' => $calc['tax_total'],
                'gross_total' => $calc['gross_total'],
                'tax_breakdown' => $calc['groups'],
                'number' => NumberGenerator::next($tenant, $invoice->type, $invoice->is_test, $invoice->issue_date),
                'status' => Invoice::STATUS_ISSUED,
                'issued_at' => now(),
                'issued_by' => $user->id,
            ]);

            $path = 'invoices/'.$tenant->id.'/'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number).'.pdf';
            Storage::disk('local')->put($path, $this->pdf->render($invoice));
            $invoice->pdf_path = $path;

            $invoice->save();

            return $invoice;
        });
    }

    /** Gutschrift als neuer Entwurf mit den Daten der Rechnung. */
    public function createCreditNote(Invoice $original, User $user): Invoice
    {
        if ($original->isDraft() || $original->isCreditNote()) {
            throw new RuntimeException('Eine Gutschrift kann nur zu einer freigegebenen Rechnung erstellt werden.');
        }

        return DB::transaction(function () use ($original, $user) {
            $credit = new Invoice([
                'type' => Invoice::TYPE_CREDIT_NOTE,
                'status' => Invoice::STATUS_DRAFT,
                'is_test' => $original->is_test,
                'customer_id' => $original->customer_id,
                'customer_snapshot' => $original->customer_snapshot,
                'original_invoice_id' => $original->id,
                'issue_date' => now()->toDateString(),
                'currency' => $original->currency,
                'tax_mode' => $original->tax_mode,
                'discount_percent' => $original->discount_percent,
                'buyer_reference' => $original->buyer_reference,
                'customer_order_ref' => $original->customer_order_ref,
                'intro_text' => Locale::with('de', fn () => __('messages.invoices.credit_for', ['number' => $original->number])),
                'footer_text' => null,
                'created_by' => $user->id,
            ]);
            $credit->save();

            foreach ($original->items as $item) {
                InvoiceItem::create($item->only([
                    'position', 'article_id', 'description', 'quantity', 'unit', 'unit_price',
                    'discount_percent', 'tax_rate', 'net_amount',
                ]) + ['invoice_id' => $credit->id]);
            }

            $credit->fill([
                'net_total' => $original->net_total,
                'discount_total' => $original->discount_total,
                'tax_total' => $original->tax_total,
                'gross_total' => $original->gross_total,
                'tax_breakdown' => $original->tax_breakdown,
            ])->save();

            return $credit;
        });
    }

    /**
     * Abrechnungsart und Serie eines Entwurfs prüfen und normalisieren.
     *
     * Teilrechnung ohne Bezug eröffnet eine neue Serie; mit Bezug wird sie der Serie des gewählten Belegs zugeordnet.
     * Eine Schlussrechnung braucht immer eine Serie, die noch keine Schlussrechnung hat. Gutschriften und gewöhnliche
     * Rechnungen gehören zu keiner Serie.
     *
     * @param  array<string, mixed>  $data
     * @return array{billing_kind: string, series_invoice_id: int|null}
     */
    private function resolveSeries(bool $isCredit, array $data, Customer $customer, string $currency, bool $isTest, ?Invoice $draft): array
    {
        $kind = $data['billing_kind'] ?? Invoice::BILLING_NORMAL;
        $kind = in_array($kind, Invoice::BILLING_KINDS, true) ? $kind : Invoice::BILLING_NORMAL;

        if ($isCredit || $kind === Invoice::BILLING_NORMAL) {
            return ['billing_kind' => Invoice::BILLING_NORMAL, 'series_invoice_id' => null];
        }

        $refId = $data['series_invoice_id'] ?? null;

        if ($refId === null) {
            if ($kind === Invoice::BILLING_FINAL) {
                throw ValidationException::withMessages(['series_invoice_id' => __('messages.invoices.series_err_required')]);
            }

            return ['billing_kind' => $kind, 'series_invoice_id' => null];
        }

        $ref = Invoice::query()->find($refId);

        if (! $ref || $ref->isCreditNote() || $ref->isDraft() || ! $ref->isPartial()
            || (int) $ref->customer_id !== (int) $customer->id || $ref->currency !== $currency
            || (bool) $ref->is_test !== $isTest || ($draft && $ref->id === $draft->id)) {
            throw ValidationException::withMessages(['series_invoice_id' => __('messages.invoices.series_err_invalid')]);
        }

        if ($this->seriesIsClosed($ref->seriesRootId(), $draft)) {
            throw ValidationException::withMessages(['series_invoice_id' => __('messages.invoices.series_err_closed')]);
        }

        return ['billing_kind' => $kind, 'series_invoice_id' => $ref->seriesRootId()];
    }

    /** Eine Serie ist abgeschlossen, sobald eine Schlussrechnung freigegeben ist. */
    private function seriesIsClosed(?int $root, ?Invoice $except = null): bool
    {
        if ($root === null) {
            return false;
        }

        return Invoice::query()
            ->where('type', Invoice::TYPE_INVOICE)->where('status', Invoice::STATUS_ISSUED)
            ->where('billing_kind', Invoice::BILLING_FINAL)->where('series_invoice_id', $root)
            ->when($except?->exists, fn ($q) => $q->where('id', '!=', $except->id))
            ->exists();
    }

    /** @return array<string, mixed> */
    public function snapshot(Customer $customer): array
    {
        return [
            'customer_number' => $customer->customer_number,
            'company' => $customer->company,
            'contact' => $customer->contactLine(),
            'street' => $customer->street,
            'zip' => $customer->zip,
            'city' => $customer->city,
            'country' => $customer->country,
            'vat_id' => $customer->vat_id,
            'email' => $customer->email,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, float>  $lines
     */
    private function storeItems(Invoice $invoice, array $items, array $lines, string $taxMode): void
    {
        foreach (array_values($items) as $i => $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'position' => $i + 1,
                'article_id' => $item['article_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit' => $item['unit'] ?? null,
                'unit_price' => $item['unit_price'],
                'discount_percent' => $item['discount_percent'] ?? 0,
                'tax_rate' => $taxMode === 'standard' ? $item['tax_rate'] : 0,
                'net_amount' => $lines[$i],
            ]);
        }
    }
}
