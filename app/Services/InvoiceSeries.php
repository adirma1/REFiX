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

/** Übersicht einer Rechnungsserie (Teil- und Schlussrechnungen) für den Ausdruck. */
class InvoiceSeries
{
    /**
     * Bereits freigegebene Rechnungen der Serie plus der aktuelle Beleg. Beträge früherer Rechnungen sind um
     * freigegebene Gutschriften gemindert. Gibt null zurück, wenn der Beleg zu keiner Serie gehört.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_net: float, total_gross: float}|null
     */
    public static function summary(Invoice $invoice): ?array
    {
        if (! $invoice->inSeries()) {
            return null;
        }

        $previous = $invoice->seriesMembers()->where('id', '!=', $invoice->id)->values();
        $credits = $previous->isEmpty() ? collect() : Invoice::query()
            ->where('type', Invoice::TYPE_CREDIT_NOTE)->where('status', Invoice::STATUS_ISSUED)
            ->whereIn('original_invoice_id', $previous->pluck('id'))
            ->get(['original_invoice_id', 'net_total', 'gross_total'])
            ->groupBy('original_invoice_id');

        $rows = [];
        $net = 0.0;
        $gross = 0.0;

        foreach ($previous as $member) {
            $credited = $credits->get($member->id, collect());
            $rowNet = round((float) $member->net_total - (float) $credited->sum('net_total'), 2);
            $rowGross = round((float) $member->gross_total - (float) $credited->sum('gross_total'), 2);

            if ($rowNet == 0.0 && $rowGross == 0.0) {
                continue;
            }

            $rows[] = ['number' => (string) $member->number, 'date' => $member->issue_date, 'net' => $rowNet, 'gross' => $rowGross, 'is_this' => false];
            $net += $rowNet;
            $gross += $rowGross;
        }

        $rows[] = [
            'number' => $invoice->number ?? __('messages.invoices.no_number'),
            'date' => $invoice->issue_date,
            'net' => (float) $invoice->net_total,
            'gross' => (float) $invoice->gross_total,
            'is_this' => true,
        ];

        return [
            'rows' => $rows,
            'total_net' => round($net + (float) $invoice->net_total, 2),
            'total_gross' => round($gross + (float) $invoice->gross_total, 2),
        ];
    }
}
