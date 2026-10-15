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

/** CSV-Kontoauszüge: Trennzeichen und Zeichensatz erkennen, Kopfzeile finden, Spalten zuordnen, Zeilen lesen. */
class BankCsvParser
{
    public const MAX_ROWS = 10000;

    public const FIELDS = ['date', 'value_date', 'amount', 'credit', 'debit', 'currency', 'name', 'iban', 'purpose', 'reference'];

    private const SYNONYMS = [
        'date' => ['Buchungstag', 'Buchungsdatum', 'Buchung', 'Bu.Tag', 'Booking date', 'Bookingdate', 'Datum', 'Date'],
        'value_date' => ['Valuta', 'Valutadatum', 'Wertstellung', 'Wertstellungsdatum', 'Value date', 'Wert'],
        'amount' => ['Betrag', 'Betrag (EUR)', 'Umsatz', 'Amount', 'Betrag in EUR'],
        'credit' => ['Haben', 'Gutschrift', 'Eingang', 'Credit'],
        'debit' => ['Soll', 'Lastschrift', 'Ausgang', 'Debit'],
        'currency' => ['Währung', 'Waehrung', 'Currency', 'Whrung'],
        'name' => ['Beguenstigter/Zahlungspflichtiger', 'Begünstigter/Zahlungspflichtiger', 'Zahlungspflichtiger', 'Auftraggeber',
            'Auftraggeber/Empfänger', 'Name Zahlungsbeteiligter', 'Zahlungsbeteiligter', 'Empfänger', 'Name', 'Partner', 'Counterparty'],
        'iban' => ['IBAN Zahlungsbeteiligter', 'Kontonummer/IBAN', 'IBAN', 'Gegenkonto'],
        'purpose' => ['Verwendungszweck', 'Zahlungsreferenz', 'Zweck', 'Purpose', 'Beschreibung', 'Buchungstext'],
        'reference' => ['Kundenreferenz', 'Ende-zu-Ende-Referenz', 'End to End Referenz', 'EndToEndId', 'Mandatsreferenz', 'Referenz', 'Reference'],
    ];

    /**
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, delimiter: string}
     *
     * @throws BankFileInvalid
     */
    public static function parse(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $raw), fn ($l) => trim($l) !== ''));
        $delimiter = self::delimiter(array_slice($lines, 0, 40));

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $all = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn ($c) => trim((string) $c), $cells);
            if (trim(implode('', $cells)) === '') {
                continue;
            }
            $all[] = $cells;
            if (count($all) > self::MAX_ROWS + 50) {
                fclose($handle);
                throw new BankFileInvalid(__('messages.bank.err_too_many', ['max' => self::MAX_ROWS]));
            }
        }
        fclose($handle);

        // Kopfzeile: erste der ersten 40 Zeilen mit mindestens 3 Zellen, die wie eine Überschrift aussieht.
        $headerAt = null;
        foreach (array_slice($all, 0, 40, true) as $i => $cells) {
            if (count($cells) >= 3 && self::headerScore($cells) >= 2) {
                $headerAt = $i;
                break;
            }
        }
        $headerAt ??= 0;

        $headers = $all[$headerAt] ?? [];
        $width = count($headers);
        $rows = [];
        foreach (array_slice($all, $headerAt + 1) as $cells) {
            if (count($cells) < min($width, 3)) {
                continue; // Fußzeilen wie "Kontostand;1.234,00" auslassen
            }
            $rows[] = $cells;
        }

        if ($width < 3 || $rows === []) {
            throw new BankFileInvalid(__('messages.bank.err_csv_empty'));
        }

        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    public static function guess(array $headers): array
    {
        $simple = array_map(fn ($h) => self::simplify($h), $headers);
        $used = [];
        $map = [];

        foreach (self::FIELDS as $field) {
            $map[$field] = null;
            foreach (self::SYNONYMS[$field] as $synonym) {
                $index = array_search(self::simplify($synonym), $simple, true);
                if ($index !== false && ! in_array($index, $used, true)) {
                    $map[$field] = $index;
                    $used[] = $index;
                    break;
                }
            }
        }

        // Wenn Betrag und Haben/Soll gleichzeitig erkannt wurden, gilt die Betragsspalte.
        if ($map['amount'] !== null) {
            $map['credit'] = $map['debit'] = null;
        }

        return $map;
    }

    /**
     * Wandelt die Zeilen mit der Zuordnung in Kontobewegungen um.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int|null>  $mapping
     * @return array{transactions: array<int, ParsedTransaction>, skipped: int}
     *
     * @throws BankFileInvalid
     */
    public static function convert(array $rows, array $mapping): array
    {
        if (($mapping['date'] ?? null) === null) {
            throw new BankFileInvalid(__('messages.bank.err_map_date'));
        }
        if (($mapping['amount'] ?? null) === null && ($mapping['credit'] ?? null) === null && ($mapping['debit'] ?? null) === null) {
            throw new BankFileInvalid(__('messages.bank.err_map_amount'));
        }

        $get = fn (array $cells, string $field) => isset($mapping[$field]) ? trim((string) ($cells[$mapping[$field]] ?? '')) : '';

        $transactions = [];
        $skipped = 0;

        foreach ($rows as $cells) {
            $date = self::date($get($cells, 'date'));

            if (($mapping['amount'] ?? null) !== null) {
                $cents = self::amount($get($cells, 'amount'));
            } else {
                $credit = self::amount($get($cells, 'credit'));
                $debit = self::amount($get($cells, 'debit'));
                $cents = ($credit !== null || $debit !== null) ? abs($credit ?? 0) - abs($debit ?? 0) : null;
            }

            if ($date === null || $cents === null) {
                $skipped++;

                continue;
            }

            $iban = strtoupper(str_replace(' ', '', $get($cells, 'iban')));
            $currency = strtoupper($get($cells, 'currency'));
            $clip = fn (string $v, int $max) => $v === '' ? null : mb_substr(trim(preg_replace('/\s+/', ' ', $v)), 0, $max);

            $transactions[] = new ParsedTransaction(
                $date,
                self::date($get($cells, 'value_date')),
                $cents,
                preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'EUR',
                $clip($get($cells, 'name'), 255),
                preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban) ? $iban : null,
                $clip($get($cells, 'purpose'), 2000),
                $clip($get($cells, 'reference'), 255),
            );
        }

        if ($transactions === []) {
            throw new BankFileInvalid(__('messages.bank.err_no_transactions'));
        }

        return ['transactions' => $transactions, 'skipped' => $skipped];
    }

    /** Betrag in Cent mit Vorzeichen; null, wenn nicht lesbar. Versteht 1.234,56 / 1,234.56 / 1234,56 / 12,34- / (12,34). */
    public static function amount(string $text): ?int
    {
        $text = trim(str_replace(["\u{00A0}", ' ', '€', 'EUR'], '', $text));
        if ($text === '') {
            return null;
        }

        $negative = false;
        if (str_starts_with($text, '(') && str_ends_with($text, ')')) {
            $negative = true;
            $text = substr($text, 1, -1);
        }
        if (str_ends_with($text, '-')) {
            $negative = true;
            $text = substr($text, 0, -1);
        } elseif (str_ends_with($text, '+')) {
            $text = substr($text, 0, -1);
        }
        if (str_starts_with($text, '-')) {
            $negative = ! $negative;
            $text = substr($text, 1);
        } elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        if (! preg_match('/^\d[\d.,]*$/', $text)) {
            return null;
        }

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');

        if ($comma !== false && $dot !== false) {
            $decimalSep = $comma > $dot ? ',' : '.';
        } elseif ($comma !== false) {
            $decimalSep = substr_count($text, ',') === 1 ? ',' : null;
        } elseif ($dot !== false) {
            // "1.234" ist im deutschen Kontext ein Tausenderpunkt, "12.34" ein Dezimalpunkt.
            $decimalSep = (substr_count($text, '.') === 1 && strlen($text) - $dot - 1 !== 3) ? '.' : null;
        } else {
            $decimalSep = null;
        }

        if ($decimalSep === null) {
            $value = (float) str_replace([',', '.'], '', $text);
        } else {
            $thousands = $decimalSep === ',' ? '.' : ',';
            $number = str_replace($decimalSep, '.', str_replace($thousands, '', $text));
            if (substr_count($number, '.') > 1) {
                return null;
            }
            $value = (float) $number;
        }

        $cents = (int) round($value * 100);

        return $negative ? -$cents : $cents;
    }

    /** Datum aus TT.MM.JJJJ, TT.MM.JJ, JJJJ-MM-TT oder TT/MM/JJJJ; sonst null. */
    public static function date(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[.\/](\d{1,2})[.\/](\d{4}|\d{2})/', $text, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) {
                $y += 2000;
            }
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** @param  array<int, string>  $lines */
    private static function delimiter(array $lines): string
    {
        $best = ';';
        $bestScore = -1;

        foreach ([';', "\t", ','] as $candidate) {
            $score = 0;
            foreach ($lines as $line) {
                if (substr_count($line, $candidate) >= 2) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @param  array<int, string>  $cells */
    private static function headerScore(array $cells): int
    {
        $known = [];
        foreach (self::SYNONYMS as $synonyms) {
            foreach ($synonyms as $s) {
                $known[self::simplify($s)] = true;
            }
        }

        $score = 0;
        foreach ($cells as $cell) {
            if (isset($known[self::simplify($cell)])) {
                $score++;
            }
        }

        return $score;
    }

    private static function simplify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
    }
}
