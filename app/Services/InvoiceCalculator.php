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

/**
 * Berechnet Positionssummen, Steuergruppen und Gesamtbeträge.
 *
 * Rundungsregeln (kaufmännisch, auf 2 Stellen):
 *  1. Positionsnetto = Menge × Einzelpreis × (1 − Positionsrabatt)
 *  2. Positionen werden je Steuersatz summiert
 *  3. Ein Gesamtrabatt mindert jede Steuergruppe einzeln (die Steuer bleibt korrekt je Satz)
 *  4. Steuer = Gruppen-Netto × Satz, je Gruppe gerundet
 */
class InvoiceCalculator
{
    public const TAX_MODES = ['standard', 'reverse_charge', 'intra_community', 'export'];

    /**
     * @param  array<int, array{quantity: mixed, unit_price: mixed, discount_percent?: mixed, tax_rate: mixed}>  $items
     * @return array{lines: array<int, float>, groups: array<int, array{rate: float, net: float, tax: float}>, net_total: float, discount_total: float, tax_total: float, gross_total: float}
     */
    public static function calculate(array $items, float $discountPercent = 0.0, string $taxMode = 'standard'): array
    {
        $lines = [];
        $groupNet = [];

        foreach (array_values($items) as $i => $item) {
            $net = round(
                (float) $item['quantity'] * (float) $item['unit_price'] * (1 - (float) ($item['discount_percent'] ?? 0) / 100),
                2
            );
            $lines[$i] = $net;

            // Außerhalb des Normalfalls (Reverse Charge, Export …) fällt keine Steuer an.
            $rate = $taxMode === 'standard' ? round((float) $item['tax_rate'], 2) : 0.0;
            $key = number_format($rate, 2, '.', '');
            $groupNet[$key] = ($groupNet[$key] ?? 0.0) + $net;
        }

        krsort($groupNet, SORT_NUMERIC);

        $groups = [];
        $netBefore = 0.0;
        $netTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($groupNet as $key => $sum) {
            $sum = round($sum, 2);
            $netBefore += $sum;
            $discounted = round($sum * (1 - $discountPercent / 100), 2);
            $tax = round($discounted * (float) $key / 100, 2);

            $groups[] = ['rate' => (float) $key, 'net' => $discounted, 'tax' => $tax];
            $netTotal += $discounted;
            $taxTotal += $tax;
        }

        $netTotal = round($netTotal, 2);
        $taxTotal = round($taxTotal, 2);

        return [
            'lines' => $lines,
            'groups' => $groups,
            'net_total' => $netTotal,
            'discount_total' => round(round($netBefore, 2) - $netTotal, 2),
            'tax_total' => $taxTotal,
            'gross_total' => round($netTotal + $taxTotal, 2),
        ];
    }
}
