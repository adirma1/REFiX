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

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoice;
use App\Services\DashboardStats;
use App\Services\DunningService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** So viele Tags zeigt die Kundenkarte; der Rest wird als „weitere“ zusammengefasst. */
    private const TOP_TAGS = 8;

    public function index(DashboardStats $stats): View
    {
        $canPayments = Gate::allows('manage-payments');

        return view('dashboard', [
            'customerStats' => $this->customerStats(),
            'articleCount' => Article::count(),
            'draftCount' => Invoice::where('status', Invoice::STATUS_DRAFT)->count(),
            'issuedThisMonth' => Invoice::where('status', Invoice::STATUS_ISSUED)
                ->where('type', Invoice::TYPE_INVOICE)
                ->whereBetween('issue_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->count(),
            // Kachel nur, wenn der Mandant überhaupt wiederkehrende Rechnungen angelegt hat.
            'recurringDrafts' => RecurringInvoice::exists()
                ? Invoice::where('status', Invoice::STATUS_DRAFT)->whereNotNull('recurring_invoice_id')->count()
                : null,
            'stats' => $stats->compute(),
            'canPayments' => $canPayments,
            'dunningSuggestions' => $canPayments ? app(DunningService::class)->suggestions()->count() : null,
        ]);
    }

    /**
     * Kundenstatistik: gesamt, aktiv, inaktiv und die aktiven Kunden je Tag.
     * Ein Kunde mit mehreren Tags zählt bei jedem davon.
     *
     * @return array{total: int, active: int, inactive: int, active_pct: int, inactive_pct: int, untagged: int, tags: array<string, int>, more: int}
     */
    private function customerStats(): array
    {
        $total = Customer::count();
        $active = Customer::where('active', true)->count();
        $tags = [];
        $untagged = 0;

        Customer::where('active', true)->pluck('tags')->each(function ($value) use (&$tags, &$untagged) {
            $words = preg_split('/\s+/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                $untagged++;
            }
            foreach (array_unique($words) as $word) {
                $tags[$word] = ($tags[$word] ?? 0) + 1;
            }
        });

        // Häufigste zuerst, bei gleicher Anzahl alphabetisch; die Karte zeigt die ersten TOP_TAGS.
        uksort($tags, fn ($a, $b) => [$tags[$b], strtolower($a)] <=> [$tags[$a], strtolower($b)]);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'active_pct' => $total > 0 ? (int) round($active / $total * 100) : 0,
            'inactive_pct' => $total > 0 ? (int) round(($total - $active) / $total * 100) : 0,
            'untagged' => $untagged,
            'tags' => array_slice($tags, 0, self::TOP_TAGS, true),
            'more' => max(0, count($tags) - self::TOP_TAGS),
        ];
    }
}
