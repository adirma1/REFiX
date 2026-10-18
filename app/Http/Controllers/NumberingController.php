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

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\NumberGenerator;
use App\Support\ListSort;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Zählerstände der Nummernkreise einsehen und setzen (z. B. Übernahme aus dem Altprogramm). */
class NumberingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        $tenant = Tenant::findOrFail(Tenancy::id());
        $today = now();
        $rows = [];

        foreach ([Invoice::TYPE_INVOICE, Invoice::TYPE_CREDIT_NOTE, Quote::TYPE] as $type) {
            foreach ([false, true] as $isTest) {
                $last = NumberGenerator::lastFor($tenant, $type, $isTest, $today);
                $format = NumberGenerator::formatFor($tenant, $type);

                $rows[] = [
                    'type' => $type,
                    'is_test' => $isTest,
                    'format' => $format,
                    'last' => $last,
                    'last_number' => $last > 0 ? NumberGenerator::render($format, $last, $today, $isTest) : null,
                    'next_number' => NumberGenerator::render($format, $last + 1, $today, $isTest),
                ];
            }
        }

        // Kundennummern: eigener Kreis ohne Format, Zeitraum und Testzähler.
        $lastCustomer = NumberGenerator::lastFor($tenant, NumberGenerator::CUSTOMER, false, $today);
        $rows[] = [
            'type' => NumberGenerator::CUSTOMER,
            'is_test' => false,
            'format' => null,
            'last' => $lastCustomer,
            'last_number' => $lastCustomer > 0 ? (string) $lastCustomer : null,
            'next_number' => NumberGenerator::peekCustomerNumber($tenant),
        ];

        // Ohne Klick bleibt die feste Reihenfolge; „Art“ sortiert nach der angezeigten Bezeichnung.
        $sort = ListSort::make($request, 'numbering', [
            'default' => fn () => 0,
            'type' => fn (array $r) => match ($r['type']) {
                'customer' => __('messages.numbering.customers'), 'invoice' => __('messages.invoices.invoice'),
                'quote' => __('messages.quotes.title_single'), default => __('messages.invoices.credit_note'),
            }.($r['is_test'] ? '~' : ''),
            'format' => fn (array $r) => $r['format'] ?? '', 'last' => fn (array $r) => $r['last_number'] ?? '',
            'next' => fn (array $r) => $r['next_number'],
        ], ['default', 'asc']);

        return view('numbering.index', ['sort' => $sort, 'rows' => $sort->sortCollection(collect($rows)), 'tenant' => $tenant]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $data = $request->validate([
            'type' => ['required', Rule::in([Invoice::TYPE_INVOICE, Invoice::TYPE_CREDIT_NOTE, Quote::TYPE, NumberGenerator::CUSTOMER])],
            'is_test' => ['required', 'boolean'],
            'last' => ['required', 'integer', 'min:0', 'max:99999999'],
        ]);

        $tenant = Tenant::findOrFail(Tenancy::id());
        $isTest = $data['type'] === NumberGenerator::CUSTOMER ? false : (bool) $data['is_test'];
        $before = NumberGenerator::lastFor($tenant, $data['type'], $isTest, now());

        NumberGenerator::setLast($tenant, $data['type'], $isTest, now(), (int) $data['last']);

        AuditLog::record('numbering.updated', $tenant, [
            'type' => $data['type'], 'is_test' => $isTest, 'from' => $before, 'to' => (int) $data['last'],
        ]);

        return redirect()->route('numbering.index')->with('status', __('messages.numbering.saved'));
    }
}
