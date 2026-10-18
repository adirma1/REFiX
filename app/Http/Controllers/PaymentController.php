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

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentInvalid;
use App\Services\PaymentService;
use App\Support\DateRangeFilter;
use App\Support\ListSort;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/** Offene Posten und manuelles Buchen von Zahlungen. */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments) {}

    public function index(Request $request): View
    {
        $this->authorize('manage-payments');

        $filter = $request->query('filter') === 'overdue' ? 'overdue' : 'all';
        $search = trim((string) $request->query('q'));

        $all = $this->payments->openItems();

        $totals = [
            'open' => $all->sum(fn ($row) => $row['summary']['open']),
            'overdue' => $all->filter(fn ($row) => $row['summary']['overdue'])->sum(fn ($row) => $row['summary']['open']),
            'count' => $all->count(),
            'overdue_count' => $all->filter(fn ($row) => $row['summary']['overdue'])->count(),
        ];

        [$dueFrom, $dueTo] = DateRangeFilter::fromRequest($request, 'due');

        $rows = $all
            ->when($filter === 'overdue', fn ($c) => $c->filter(fn ($row) => $row['summary']['overdue']))
            // Fällig am im Zeitraum (Grenzen inklusive); Rechnungen ohne Fälligkeitsdatum fallen heraus, sobald ein Zeitraum gesetzt ist.
            ->when($dueFrom !== '' || $dueTo !== '', fn ($c) => $c->filter(function ($row) use ($dueFrom, $dueTo) {
                $due = $row['invoice']->due_date?->toDateString();

                return $due !== null && ($dueFrom === '' || $due >= $dueFrom) && ($dueTo === '' || $due <= $dueTo);
            }))
            ->when($search !== '', function ($c) use ($search) {
                $needle = mb_strtolower($search);

                return $c->filter(fn ($row) => str_contains(mb_strtolower((string) $row['invoice']->number), $needle)
                    || str_contains(mb_strtolower((string) ($row['invoice']->customer_snapshot['company'] ?? '')), $needle)
                    || str_contains(mb_strtolower((string) ($row['invoice']->customer_snapshot['customer_number'] ?? '')), $needle));
            })
            ->values();

        // Ohne Klick bleibt die Reihenfolge aus openItems(); die Sortierung gilt für alle Seiten.
        $sort = ListSort::make($request, 'payments', [
            'default' => fn () => 0,
            'number' => fn (array $r) => $r['invoice']->number,
            'customer' => fn (array $r) => $r['invoice']->customer_snapshot['company'] ?? '',
            'due' => fn (array $r) => $r['invoice']->due_date?->timestamp ?? PHP_INT_MAX,
            'gross' => fn (array $r) => $r['summary']['gross'],
            'paid' => fn (array $r) => $r['summary']['paid'] + $r['summary']['discount'] + $r['summary']['credited'],
            'open' => fn (array $r) => $r['summary']['open'],
            'status' => fn (array $r) => $r['summary']['status'],
        ], ['default', 'asc']);
        $rows = $sort->sortCollection($rows);

        $perPage = PerPage::for($request->user());
        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            $rows->count(), $perPage, $page,
            ['path' => route('payments.index'), 'query' => $request->query()]
        );

        return view('payments.index', [
            'sort' => $sort, 'dueFrom' => $dueFrom, 'dueTo' => $dueTo, 'rows' => $paginator, 'totals' => $totals, 'filter' => $filter, 'search' => $search,
        ]);
    }

    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('manage-payments');

        $request->merge(['amount' => $this->decimal($request->input('amount'))]);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'lt:10000000000'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'skonto' => ['nullable', 'boolean'],
        ]);

        try {
            $this->payments->book($invoice, $data, $request->user(), null, (bool) ($data['skonto'] ?? false));
        } catch (PaymentInvalid $e) {
            return redirect()->route('invoices.show', $invoice)->withInput()->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', __('messages.payments.booked'));
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('manage-payments');

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $invoice = $payment->invoice;

        try {
            $this->payments->reverse($payment, $data['reason'], $request->user());
        } catch (PaymentInvalid $e) {
            return redirect()->route('invoices.show', $invoice)->with('warning', $e->getMessage());
        }

        return redirect()->route('invoices.show', $invoice)->with('status', __('messages.payments.reversed'));
    }
}
