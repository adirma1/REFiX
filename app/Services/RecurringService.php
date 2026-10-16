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

use App\Models\Article;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Models\RecurringInvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecurringService
{
    /** Obergrenze je Vorlage und Lauf, falls sehr viele Termine nachzuholen wären. */
    public const CATCH_UP_LIMIT = 36;

    private const MONTHS = [
        1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
    ];

    public function __construct(private InvoiceService $invoices) {}

    /** Platzhalter, die in Positions- und Kopf-/Fußtexten ersetzt werden. */
    public const PLACEHOLDERS = ['zeitraum', 'zeitraum_von', 'zeitraum_bis', 'monat', 'quartal', 'jahr'];

    /** @return array<string, string> */
    public static function placeholderValues(Carbon $from, Carbon $to): array
    {
        return [
            '{zeitraum}' => $from->format('d.m.Y').' – '.$to->format('d.m.Y'),
            '{zeitraum_von}' => $from->format('d.m.Y'),
            '{zeitraum_bis}' => $to->format('d.m.Y'),
            '{monat}' => self::MONTHS[$from->month].' '.$from->year,
            '{quartal}' => 'Q'.(int) ceil($from->month / 3).' '.$from->year,
            '{jahr}' => (string) $from->year,
        ];
    }

    public static function fill(?string $text, Carbon $from, Carbon $to): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return strtr($text, self::placeholderValues($from, $to));
    }

    /**
     * Vorlage speichern. Ändert sich Stichtag oder Rhythmus, rechnen die Stichtage ab dem neuen Datum neu.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function save(?RecurringInvoice $recurring, array $data, array $items, User $user): RecurringInvoice
    {
        return DB::transaction(function () use ($recurring, $data, $items, $user) {
            $next = Carbon::parse($data['next_date'])->startOfDay();

            if ($recurring === null) {
                $recurring = new RecurringInvoice(['created_by' => $user->id]);
                $reanchor = true;
            } else {
                $recurring = RecurringInvoice::lockForUpdate()->findOrFail($recurring->id);
                $reanchor = ! $recurring->next_date->isSameDay($next) || $recurring->interval !== $data['interval'];
            }

            $recurring->fill([
                'name' => $data['name'],
                'customer_id' => $data['customer_id'],
                'active' => (bool) ($data['active'] ?? false),
                'interval' => $data['interval'],
                'billing' => $data['billing'],
                'next_date' => $next,
                'end_date' => $data['end_date'] ?? null,
                'currency' => $data['currency'],
                'tax_mode' => Tenant::findOrFail(Tenancy::id())->taxModeFor($data['tax_mode'] ?? null),
                'discount_percent' => $data['discount_percent'] ?? 0,
                'payment_term_days' => $data['payment_term_days'] ?? null,
                'buyer_reference' => $data['buyer_reference'] ?? null,
                'intro_text' => $data['intro_text'] ?? null,
                'footer_text' => $data['footer_text'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
            ]);

            if ($reanchor) {
                $recurring->anchor_date = $next;
                $recurring->occurrence = 0;
            }

            $recurring->save();

            RecurringInvoiceItem::where('recurring_invoice_id', $recurring->id)->delete();

            foreach (array_values($items) as $i => $item) {
                RecurringInvoiceItem::create([
                    'recurring_invoice_id' => $recurring->id,
                    'position' => $i + 1,
                    'article_id' => $item['article_id'] ?? null,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'] ?? null,
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'tax_rate' => $item['tax_rate'],
                ]);
            }

            return $recurring->refresh();
        });
    }

    /** Legt aus einer vorhandenen Rechnung (Entwurf oder freigegeben) eine pausierte Vorlage an. */
    public function fromInvoice(Invoice $invoice, User $user): RecurringInvoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            $invoice->loadMissing('items');
            $customer = Customer::withTrashed()->find($invoice->customer_id);
            $next = $invoice->issue_date->copy()->addMonthNoOverflow()->startOfDay();

            $recurring = RecurringInvoice::create([
                'name' => mb_substr(__('messages.recurring.name_from').' '.($customer?->company ?: $invoice->displayNumber()), 0, 120),
                'customer_id' => $invoice->customer_id,
                'active' => false,
                'interval' => 'monthly',
                'billing' => RecurringInvoice::ADVANCE,
                'anchor_date' => $next,
                'occurrence' => 0,
                'next_date' => $next,
                'currency' => $invoice->currency,
                'tax_mode' => $invoice->tax_mode,
                'discount_percent' => $invoice->discount_percent,
                'payment_term_days' => $invoice->payment_term_days,
                'buyer_reference' => $invoice->buyer_reference,
                'intro_text' => $invoice->intro_text,
                'footer_text' => $invoice->footer_text,
                'internal_notes' => $invoice->internal_notes,
                'created_by' => $user->id,
            ]);

            foreach ($invoice->items as $i => $item) {
                RecurringInvoiceItem::create([
                    'recurring_invoice_id' => $recurring->id,
                    'position' => $i + 1,
                    'article_id' => $item->article_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $item->unit_price,
                    'discount_percent' => $item->discount_percent,
                    'tax_rate' => $item->tax_rate,
                ]);
            }

            AuditLog::record('recurring.created_from_invoice', $recurring, ['invoice_id' => $invoice->id]);

            return $recurring->refresh();
        });
    }

    /**
     * Erzeugt alle fälligen Entwürfe einer Vorlage (nachholend, jeweils mit dem regulären Stichtag).
     *
     * @return array<int, Invoice>
     */
    public function generate(RecurringInvoice $recurring, User $user, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $created = [];

        while (count($created) < self::CATCH_UP_LIMIT && ($invoice = $this->generateNext($recurring, $user, $today)) !== null) {
            $created[] = $invoice;
        }

        return $created;
    }

    /** Ein einzelner Entwurf zum nächsten Stichtag, falls fällig; sonst null. */
    private function generateNext(RecurringInvoice $recurring, User $user, Carbon $today): ?Invoice
    {
        return DB::transaction(function () use ($recurring, $user, $today) {
            $recurring = RecurringInvoice::lockForUpdate()->findOrFail($recurring->id);

            if (! $recurring->isDue($today)) {
                return null;
            }

            $k = (int) $recurring->occurrence;
            $date = $recurring->occurrenceDate($k);
            [$from, $to] = $recurring->periodFor($k);
            $customer = Customer::findOrFail($recurring->customer_id);

            // Gesperrter Kunde (inaktiv oder nach „Kunde bis“): kein Entwurf, kein Fehler; die Vorlage bleibt unverändert liegen.
            if ($customer->isBlockedFor($date)) {
                return null;
            }

            $lines = [];
            foreach ($recurring->items as $item) {
                $price = (float) $item->unit_price;

                // Stammartikel: gültiger Preis zum Stichtag aus der Preistabelle; freie Positionen behalten ihren Preis.
                if ($item->article_id && ($article = Article::find($item->article_id))) {
                    $found = $article->priceFor($date, $recurring->currency, $recurring->customer_id);
                    if ($found) {
                        $price = (float) $found->amount;
                    }
                }

                $lines[] = [
                    'article_id' => $item->article_id,
                    'description' => self::fill($item->description, $from, $to),
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => $price,
                    'discount_percent' => (float) $item->discount_percent,
                    'tax_rate' => (float) $item->tax_rate,
                ];
            }

            $invoice = $this->invoices->saveDraft(null, [
                'customer_id' => $customer->id,
                'issue_date' => $date->toDateString(),
                'service_date_from' => $from->toDateString(),
                'service_date_to' => $to->toDateString(),
                'currency' => $recurring->currency,
                'tax_mode' => $recurring->tax_mode,
                'discount_percent' => (float) $recurring->discount_percent,
                'buyer_reference' => $recurring->buyer_reference,
                'payment_term_days' => $recurring->payment_term_days ?? $customer->payment_term_days,
                'skonto1_percent' => $customer->discount_percent,
                'skonto1_days' => $customer->discount_days,
                'intro_text' => self::fill($recurring->intro_text, $from, $to),
                'footer_text' => self::fill($recurring->footer_text, $from, $to),
                'internal_notes' => trim(__('messages.recurring.invoice_note', ['name' => $recurring->name]).($recurring->internal_notes ? "\n".self::fill($recurring->internal_notes, $from, $to) : '')),
                'recurring_invoice_id' => $recurring->id,
            ], $lines, $user);

            $recurring->fill([
                'occurrence' => $k + 1,
                'next_date' => $recurring->occurrenceDate($k + 1),
                'last_generated_at' => now(),
            ])->save();

            AuditLog::record('recurring.generated', $recurring, [
                'invoice_id' => $invoice->id, 'issue_date' => $date->toDateString(),
                'period' => $from->toDateString().' – '.$to->toDateString(),
            ]);

            return $invoice;
        });
    }

    /**
     * Täglicher Lauf über alle Mandanten. Fehler einer Vorlage (z. B. gelöschter Kunde) stoppen nur diese Vorlage.
     *
     * @return array{created: int, failed: int}
     */
    public function runAll(?Carbon $today = null): array
    {
        $result = ['created' => 0, 'failed' => 0];

        foreach (Tenant::query()->get() as $tenant) {
            Tenancy::set($tenant->id);

            try {
                $result = $this->runForTenant($tenant, $today, $result);
            } finally {
                Tenancy::clear();
            }
        }

        return $result;
    }

    /**
     * @param  array{created: int, failed: int}  $result
     * @return array{created: int, failed: int}
     */
    public function runForTenant(Tenant $tenant, ?Carbon $today, array $result = ['created' => 0, 'failed' => 0], ?User $actor = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        $due = RecurringInvoice::where('active', true)->whereDate('next_date', '<=', $today)->orderBy('id')->get()
            ->filter(fn (RecurringInvoice $r) => $r->isDue($today));

        foreach ($due as $recurring) {
            $user = $actor ?? $this->actorFor($tenant, $recurring);

            if (! $user) {
                $result['failed']++;

                continue;
            }

            try {
                $result['created'] += count($this->generate($recurring, $user, $today));
            } catch (Throwable $e) {
                $result['failed']++;
                Log::warning('Wiederkehrende Rechnung '.$recurring->id.' konnte nicht erzeugt werden: '.$e->getMessage());
            }
        }

        return $result;
    }

    /** Ersteller der Vorlage, sonst ein Admin des Mandanten. */
    private function actorFor(Tenant $tenant, RecurringInvoice $recurring): ?User
    {
        if ($recurring->created_by && ($user = User::find($recurring->created_by))) {
            return $user;
        }

        return $tenant->users()->wherePivot('role', 'admin')->first();
    }
}
