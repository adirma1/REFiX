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

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Services\PaymentService;
use Illuminate\Support\Collection;

/**
 * Schlägt zu Bankeingängen die passende offene Rechnung vor. Nichts wird automatisch gebucht:
 * Die Vorschläge werden dem Benutzer gezeigt, der sie bestätigt.
 */
class BankMatcher
{
    public const HIGH = 'high';       // Rechnungsnummer und Betrag stimmen
    public const SKONTO = 'skonto';   // Rechnungsnummer und Betrag nach Skonto stimmen
    public const PARTIAL = 'partial'; // Rechnungsnummer stimmt, Betrag weicht ab (Teil-/Mehrzahlung)
    public const LOW = 'low';         // nur Betrag und Kundenname passen

    public function __construct(private PaymentService $payments) {}

    /**
     * @param  iterable<int, BankTransaction>  $transactions
     * @return array<int, array{invoice: Invoice, confidence: string, open: int}> Bewegungs-ID → Vorschlag
     */
    public function suggest(iterable $transactions): array
    {
        $open = $this->payments->openItems();
        if ($open->isEmpty()) {
            return [];
        }

        $customers = [];
        $suggestions = [];

        foreach ($transactions as $tx) {
            if ($tx->status !== BankTransaction::NEW || $tx->cents() <= 0) {
                continue;
            }

            $candidates = $open->filter(fn ($row) => $row['invoice']->currency === $tx->currency);
            $haystack = ' '.($tx->purpose ?? '').' '.($tx->reference ?? '').' ';

            $byNumber = $candidates->filter(fn ($row) => $row['invoice']->number !== null
                && preg_match(self::numberPattern($row['invoice']->number), $haystack) === 1);

            // Mehrere verschiedene Nummern im Text: Sammelzahlung – der Benutzer entscheidet.
            if ($byNumber->count() === 1) {
                $row = $byNumber->first();
                $suggestions[$tx->id] = ['invoice' => $row['invoice'], 'confidence' => $this->confidence($tx, $row), 'open' => $row['summary']['open']];

                continue;
            }

            if ($byNumber->count() > 1) {
                continue;
            }

            // Ohne Nummer: genau eine offene Rechnung mit exakt diesem Betrag und passendem Kundennamen.
            $byAmount = $candidates->filter(fn ($row) => $row['summary']['open'] === $tx->cents());
            if ($byAmount->count() >= 1) {
                $named = $byAmount->filter(fn ($row) => $this->nameMatches($row['invoice'], $tx, $customers));
                if ($named->count() === 1) {
                    $row = $named->first();
                    $suggestions[$tx->id] = ['invoice' => $row['invoice'], 'confidence' => self::LOW, 'open' => $row['summary']['open']];
                }
            }
        }

        return $suggestions;
    }

    /** Regulärer Ausdruck für eine Rechnungsnummer: Trenner (-, /, Leerzeichen, Punkt) dürfen fehlen oder abweichen. */
    public static function numberPattern(string $number): string
    {
        $chunks = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $number), fn ($c) => $c !== ''));
        if ($chunks === []) {
            return '/(?!)/';
        }

        $parts = array_map(fn ($c) => preg_quote($c, '/'), $chunks);

        // Ein reines Buchstaben-Präfix (z. B. "RE") darf im Zahlungstext fehlen, wenn der Rest lang genug ist.
        $prefix = '';
        if (count($parts) > 1 && ctype_alpha($chunks[0]) && strlen(implode('', array_slice($chunks, 1))) >= 6) {
            // Fehlt das Präfix, darf davor kein anderes Buchstaben-Präfix stehen (z. B. "GS-2026-0001").
            $prefix = '(?:'.array_shift($parts).'[\W_]*|(?<![A-Za-z][-_\/.])(?<![A-Za-z]))';
        }

        return '/(?<![A-Za-z0-9])'.$prefix.implode('[\W_]*', $parts).'(?![0-9])/iu';
    }

    /** @param  array{invoice: Invoice, summary: array<string, mixed>}  $row */
    private function confidence(BankTransaction $tx, array $row): string
    {
        $cents = $tx->cents();

        if ($cents === $row['summary']['open']) {
            return self::HIGH;
        }

        if ($row['summary']['open'] === $row['summary']['gross']) {
            foreach ($this->payments->skontoLevels($row['invoice']) as $level) {
                if ($level['pay'] === $cents) {
                    return self::SKONTO;
                }
            }
        }

        return self::PARTIAL;
    }

    /** @param  array<int, array<int, string>>  $cache */
    private function nameMatches(Invoice $invoice, BankTransaction $tx, array &$cache): bool
    {
        $company = $invoice->customer_snapshot['company'] ?? $invoice->customer?->company ?? '';
        $cache[$invoice->id] ??= self::tokens($company);

        if ($cache[$invoice->id] === []) {
            return false;
        }

        $text = ' '.implode(' ', self::tokens(($tx->counterparty_name ?? '').' '.($tx->purpose ?? ''))).' ';

        foreach ($cache[$invoice->id] as $token) {
            if (! str_contains($text, ' '.$token.' ')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int, string> Wörter ab 3 Zeichen ohne Rechtsformen */
    private static function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $words = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $legal = ['gmbh', 'mbh', 'ag', 'kg', 'ug', 'ohg', 'gbr', 'co', 'und', 'eg', 'ev', 'se', 'ltd', 'inc'];

        return array_values(array_filter($words, fn ($w) => strlen($w) >= 3 && ! in_array($w, $legal, true)));
    }
}
