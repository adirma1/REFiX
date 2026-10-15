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

use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;

/**
 * Kennzahlen für die Übersichtsseite. Alle Beträge in Cent, getrennt nach Währung (keine Umrechnung).
 * Testbelege zählen nie mit. Gutschriften mindern den Umsatz im Monat ihres Belegdatums.
 */
class DashboardStats
{
    public function __construct(private PaymentService $payments) {}

    /**
     * @return array{
     *     currencies: array<int, string>,
     *     open: array<string, int>, open_count: int,
     *     overdue: array<string, int>, overdue_count: int,
     *     revenue_month: array<string, int>, revenue_prev_month: array<string, int>, revenue_year: array<string, int>,
     *     paid_month: array<string, int>,
     *     months: array<int, array{key: string, label: string, values: array<string, int>}>,
     *     overdue_top: \Illuminate\Support\Collection<int, array{invoice: Invoice, summary: array<string, mixed>}>
     * }
     */
    public function compute(?Carbon $today = null): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $windowStart = $monthStart->copy()->subMonthsNoOverflow(11);

        // --- Umsatz (netto) der letzten 12 Monate -------------------------------------------------
        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $m = $windowStart->copy()->addMonthsNoOverflow($i);
            $months[$m->format('Y-m')] = ['key' => $m->format('Y-m'), 'label' => $m->format('m/y'), 'values' => []];
        }

        $revenueMonth = $revenuePrev = $revenueYear = [];
        $prevKey = $monthStart->copy()->subMonthNoOverflow()->format('Y-m');
        $thisKey = $monthStart->format('Y-m');
        $year = $today->format('Y');
        $currencies = [];

        Invoice::query()
            ->where('status', Invoice::STATUS_ISSUED)->where('is_test', false)
            ->whereIn('type', [Invoice::TYPE_INVOICE, Invoice::TYPE_CREDIT_NOTE])
            ->where('issue_date', '>=', $windowStart->toDateString())->where('issue_date', '<=', $today->copy()->endOfMonth()->toDateString())
            ->get(['id', 'type', 'issue_date', 'net_total', 'currency'])
            ->each(function (Invoice $invoice) use (&$months, &$revenueMonth, &$revenuePrev, &$revenueYear, &$currencies, $prevKey, $thisKey, $year) {
                $cents = PaymentService::cents($invoice->net_total) * ($invoice->isCreditNote() ? -1 : 1);
                $cur = $invoice->currency;
                $key = $invoice->issue_date->format('Y-m');
                $currencies[$cur] = true;

                if (isset($months[$key])) {
                    $months[$key]['values'][$cur] = ($months[$key]['values'][$cur] ?? 0) + $cents;
                }
                if ($key === $thisKey) {
                    $revenueMonth[$cur] = ($revenueMonth[$cur] ?? 0) + $cents;
                }
                if ($key === $prevKey) {
                    $revenuePrev[$cur] = ($revenuePrev[$cur] ?? 0) + $cents;
                }
                if ($invoice->issue_date->format('Y') === $year) {
                    $revenueYear[$cur] = ($revenueYear[$cur] ?? 0) + $cents;
                }
            });

        // --- Offene Posten ------------------------------------------------------------------------
        $items = $this->payments->openItems();
        $open = $overdue = [];
        $overdueCount = 0;

        foreach ($items as $row) {
            $cur = $row['invoice']->currency;
            $currencies[$cur] = true;
            $open[$cur] = ($open[$cur] ?? 0) + $row['summary']['open'];

            if ($row['summary']['overdue']) {
                $overdue[$cur] = ($overdue[$cur] ?? 0) + $row['summary']['open'];
                $overdueCount++;
            }
        }

        // --- Zahlungseingang im Monat -------------------------------------------------------------
        $paid = [];
        Payment::active()->where('kind', Payment::KIND_PAYMENT)
            ->whereBetween('paid_on', [$monthStart->toDateString(), $today->copy()->endOfMonth()->toDateString()])
            ->with('invoice:id,currency,is_test')
            ->get()
            ->each(function (Payment $payment) use (&$paid, &$currencies) {
                if ($payment->invoice === null || $payment->invoice->is_test) {
                    return;
                }
                $cur = $payment->invoice->currency;
                $currencies[$cur] = true;
                $paid[$cur] = ($paid[$cur] ?? 0) + PaymentService::cents($payment->amount);
            });

        // EUR zuerst, dann alphabetisch
        $list = array_keys($currencies);
        usort($list, fn ($a, $b) => [$a === 'EUR' ? 0 : 1, $a] <=> [$b === 'EUR' ? 0 : 1, $b]);

        return [
            'currencies' => $list,
            'open' => $open, 'open_count' => $items->count(),
            'overdue' => $overdue, 'overdue_count' => $overdueCount,
            'revenue_month' => $revenueMonth, 'revenue_prev_month' => $revenuePrev, 'revenue_year' => $revenueYear,
            'paid_month' => $paid,
            'months' => array_values($months),
            'overdue_top' => $items->filter(fn ($row) => $row['summary']['overdue'])
                ->sortByDesc(fn ($row) => $row['summary']['days_overdue'])->take(5)->values(),
        ];
    }
}
