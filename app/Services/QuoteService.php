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

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Angebote: speichern, freigeben (Nummer), Status wechseln und in Rechnungsentwürfe umwandeln. */
class QuoteService
{
    public function __construct(private InvoiceService $invoices) {}

    /**
     * Angebot anlegen oder ändern (Entwurf oder offen).
     *
     * @param  array<string, mixed>  $data  validierte Kopfdaten
     * @param  array<int, array<string, mixed>>  $items  validierte Positionen
     */
    public function save(?Quote $quote, array $data, array $items, User $user): Quote
    {
        return DB::transaction(function () use ($quote, $data, $items, $user) {
            $tenant = Tenant::findOrFail(Tenancy::id());
            $customer = Customer::findOrFail($data['customer_id']);
            $taxMode = $tenant->taxModeFor($data['tax_mode'] ?? null);

            if ($quote === null) {
                $quote = new Quote([
                    'status' => Quote::STATUS_DRAFT,
                    'is_test' => $tenant->test_mode,
                    'created_by' => $user->id,
                ]);
            } else {
                $quote = Quote::lockForUpdate()->findOrFail($quote->id);

                if (! $quote->isEditable()) {
                    throw new RuntimeException(__('messages.quotes.not_editable'));
                }

                if ($quote->items()->whereNotNull('invoice_id')->exists()) {
                    throw new RuntimeException(__('messages.quotes.has_invoices'));
                }
            }

            $taxNote = null;
            if ($taxMode === 'exempt') {
                $taxNote = $tenant->vat_free ? $tenant->vatFreeNote() : ($quote->tax_note ?: $tenant->vatFreeNote());
            }

            $discount = (float) ($data['discount_percent'] ?? 0);
            $calc = InvoiceCalculator::calculate($items, $discount, $taxMode);

            $quote->fill(array_merge($data, [
                'customer_snapshot' => $this->invoices->snapshot($customer),
                'tax_mode' => $taxMode,
                'tax_note' => $taxNote,
                'discount_percent' => $discount,
                'net_total' => $calc['net_total'],
                'discount_total' => $calc['discount_total'],
                'tax_total' => $calc['tax_total'],
                'gross_total' => $calc['gross_total'],
                'tax_breakdown' => $calc['groups'],
            ]));

            if (empty($data['valid_until'])) {
                $quote->valid_until = $quote->issue_date->copy()->addDays((int) ($tenant->quote_valid_days ?: 30));
            }

            $quote->save();

            QuoteItem::where('quote_id', $quote->id)->delete();

            foreach (array_values($items) as $i => $item) {
                QuoteItem::create([
                    'quote_id' => $quote->id,
                    'position' => $i + 1,
                    'article_id' => $item['article_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'tax_rate' => $taxMode === 'standard' ? $item['tax_rate'] : 0,
                    'net_amount' => $calc['lines'][$i],
                ]);
            }

            return $quote->refresh();
        });
    }

    /** Freigabe: Nummer vergeben, Werte neu berechnen, Status „offen“. */
    public function issue(Quote $quote, User $user): Quote
    {
        return DB::transaction(function () use ($quote, $user) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if (! $quote->isDraft()) {
                throw new RuntimeException(__('messages.quotes.already_issued'));
            }

            $items = $quote->items()->get();

            if ($items->isEmpty()) {
                throw new RuntimeException(__('messages.quotes.no_items'));
            }

            $tenant = Tenant::findOrFail($quote->tenant_id);
            $customer = Customer::withTrashed()->findOrFail($quote->customer_id);

            $calc = InvoiceCalculator::calculate(
                $items->map(fn ($i) => $i->only(['quantity', 'unit_price', 'discount_percent', 'tax_rate']))->all(),
                (float) $quote->discount_percent,
                $quote->tax_mode
            );

            $quote->fill([
                'customer_snapshot' => $this->invoices->snapshot($customer),
                'net_total' => $calc['net_total'],
                'discount_total' => $calc['discount_total'],
                'tax_total' => $calc['tax_total'],
                'gross_total' => $calc['gross_total'],
                'tax_breakdown' => $calc['groups'],
                'number' => NumberGenerator::next($tenant, Quote::TYPE, $quote->is_test, $quote->issue_date),
                'status' => Quote::STATUS_OPEN,
                'issued_at' => now(),
                'issued_by' => $user->id,
                'status_changed_at' => now(),
            ])->save();

            return $quote;
        });
    }

    /**
     * Statuswechsel: angenommen, abgelehnt oder wieder offen.
     *
     * @throws RuntimeException bei nicht erlaubtem Wechsel
     */
    public function setStatus(Quote $quote, string $to): Quote
    {
        return DB::transaction(function () use ($quote, $to) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);
            $from = $quote->status;

            $allowed = match ($to) {
                Quote::STATUS_ACCEPTED, Quote::STATUS_DECLINED => $from === Quote::STATUS_OPEN,
                // Wieder öffnen nur, solange noch keine Rechnung daraus entstanden ist.
                Quote::STATUS_OPEN => in_array($from, [Quote::STATUS_ACCEPTED, Quote::STATUS_DECLINED], true)
                    && ! $quote->items()->whereNotNull('invoice_id')->exists(),
                default => false,
            };

            if (! $allowed) {
                throw new RuntimeException(__('messages.quotes.status_not_allowed'));
            }

            $quote->fill(['status' => $to, 'status_changed_at' => now()])->save();

            AuditLog::record('quote.status', $quote, ['from' => $from, 'to' => $to, 'number' => $quote->number]);

            return $quote;
        });
    }

    /**
     * Ausgewählte (oder alle noch offenen) Positionen in einen Rechnungsentwurf übernehmen.
     * Das Angebot gilt danach als angenommen. Der Entwurf wird wie jede Rechnung geprüft und freigegeben.
     *
     * @param  array<int, int>|null  $itemIds  null = alle noch nicht übernommenen Positionen
     *
     * @throws RuntimeException wenn nichts umzuwandeln ist
     */
    public function convert(Quote $quote, ?array $itemIds, User $user): \App\Models\Invoice
    {
        return DB::transaction(function () use ($quote, $itemIds, $user) {
            $quote = Quote::lockForUpdate()->findOrFail($quote->id);

            if (! $quote->isConvertible()) {
                throw new RuntimeException(__('messages.quotes.convert_not_allowed'));
            }

            $pending = $quote->items()->whereNull('invoice_id')->get();
            $chosen = $itemIds === null
                ? $pending
                : $pending->whereIn('id', array_map('intval', $itemIds))->values();

            if ($chosen->isEmpty()) {
                throw new RuntimeException(__('messages.quotes.convert_nothing'));
            }

            $customer = Customer::withTrashed()->findOrFail($quote->customer_id);
            $lines = $chosen->map(fn (QuoteItem $i) => [
                'article_id' => $i->article_id,
                'description' => $i->description,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit,
                'unit_price' => (float) $i->unit_price,
                'discount_percent' => (float) $i->discount_percent,
                'tax_rate' => (float) $i->tax_rate,
            ])->all();

            $invoice = $this->invoices->saveDraft(null, [
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'currency' => $quote->currency,
                'tax_mode' => $quote->tax_mode,
                'tax_note' => $quote->tax_note,
                'discount_percent' => (float) $quote->discount_percent,
                'buyer_reference' => $quote->buyer_reference,
                'payment_term_days' => $customer->payment_term_days,
                'skonto1_percent' => $customer->discount_percent,
                'skonto1_days' => $customer->discount_days,
                'quote_id' => $quote->id,
                'internal_notes' => __('messages.quotes.invoice_note', ['number' => $quote->displayNumber()]),
            ], $lines, $user);

            QuoteItem::whereIn('id', $chosen->pluck('id'))->update(['invoice_id' => $invoice->id]);

            if ($quote->isOpen()) {
                $quote->fill(['status' => Quote::STATUS_ACCEPTED, 'status_changed_at' => now()])->save();
            }

            AuditLog::record('quote.converted', $quote, [
                'number' => $quote->number, 'invoice_id' => $invoice->id, 'items' => $chosen->pluck('position')->all(),
            ]);

            return $invoice;
        });
    }
}
