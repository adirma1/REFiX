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

namespace App\Services\Import;

use App\Models\Customer;
use App\Support\CustomerRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Kundenimport aus CSV (z. B. Export aus LibreOffice Base/Calc oder Excel): Trennzeichen und Zeichensatz werden erkannt,
 * die Spalten per Zuordnung den Kundenfeldern zugewiesen. Geprüft wird mit denselben Regeln wie im Kundenformular.
 */
class CustomerImporter
{
    public const MAX_ROWS = 5000;

    /** Felder in der Reihenfolge der Zuordnungsseite. */
    public const FIELDS = [
        'customer_number', 'company', 'salutation', 'first_name', 'last_name', 'street', 'zip', 'city', 'country',
        'email', 'phone', 'vat_id', 'currency', 'payment_term_days', 'discount_percent', 'discount_days',
        'buyer_reference', 'notes', 'customer_since', 'customer_until', 'active', 'tags',
        'birthday', 'website',
    ];

    /** Überschriften, die dem Feld zugeordnet werden (vereinfacht geschrieben, siehe simplify()). */
    private const SYNONYMS = [
        'customer_number' => ['kundennummer', 'kundennr', 'kdnr', 'kundenr', 'nummer', 'nr', 'customernumber', 'kunde'],
        'company' => ['firma', 'firmenname', 'unternehmen', 'name', 'name1', 'company', 'kundenname'],
        'salutation' => ['anrede', 'salutation'],
        'first_name' => ['vorname', 'firstname'],
        'last_name' => ['nachname', 'familienname', 'lastname'],
        'street' => ['strasse', 'strassehausnummer', 'strasseundhausnummer', 'str', 'adresse', 'anschrift', 'street'],
        'zip' => ['plz', 'postleitzahl', 'zip', 'postcode'],
        'city' => ['ort', 'stadt', 'city', 'wohnort'],
        'country' => ['land', 'laendercode', 'country', 'staat'],
        'email' => ['email', 'emailadresse', 'mail', 'emailrechnungsempfaenger'],
        'phone' => ['telefon', 'tel', 'fon', 'telefonnummer', 'phone'],
        'vat_id' => ['ustidnr', 'ustid', 'umsatzsteuerid', 'umsatzsteueridentifikationsnummer', 'vatid', 'ustidnummer'],
        'currency' => ['waehrung', 'currency'],
        'payment_term_days' => ['zahlungsziel', 'zahlungszieltage', 'zahlungsfrist', 'zahlungszielinTagen'],
        'discount_percent' => ['skonto', 'skontoprozent', 'skonto%'],
        'discount_days' => ['skontotage', 'skontofrist', 'skontofristtage'],
        'customer_since' => ['kundeseit', 'seit', 'customersince'],
        'customer_until' => ['kundebis', 'bis', 'customeruntil'],
        'active' => ['aktiv', 'active', 'status'],
        'birthday' => ['geburtstag', 'geburtsdatum', 'birthday', 'birthdate'],
        'website' => ['homepage', 'website', 'webseite', 'internet', 'url', 'web'],
        'tags' => ['tags', 'tag', 'schlagworte', 'kennzeichen'],
        'buyer_reference' => ['leitwegid', 'leitweg', 'kaeuferreferenz', 'buyerreference'],
        'notes' => ['notizen', 'notiz', 'bemerkung', 'bemerkungen', 'anmerkung', 'kommentar'],
    ];

    /**
     * Zerlegt den Inhalt einer CSV-Datei.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>, delimiter: string}
     *
     * @throws ImportInvalid
     */
    public static function parse(string $raw, string $lang = 'customer_import'): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        // Excel und ältere Programme speichern oft in Windows-1252 statt UTF-8.
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($raw, "\r\n") ?: '';
        $delimiter = collect([';', ',', "\t"])->sortByDesc(fn ($d) => substr_count($firstLine, $d))->first();
        if (substr_count($firstLine, $delimiter) === 0) {
            throw new ImportInvalid(__('messages.'.$lang.'.err_no_columns'));
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            if ($cells === [null] || self::blank($cells)) {
                continue;
            }
            $cells = array_map(fn ($c) => trim((string) $c), $cells);

            if ($headers === null) {
                $headers = $cells;

                continue;
            }

            $rows[] = $cells;
            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw new ImportInvalid(__('messages.'.$lang.'.err_too_many', ['max' => self::MAX_ROWS]));
            }
        }
        fclose($handle);

        if ($headers === null || $rows === []) {
            throw new ImportInvalid(__('messages.'.$lang.'.err_empty'));
        }

        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /**
     * Schlägt die Zuordnung Feld → Spaltenindex anhand der Überschriften vor.
     *
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

        return $map;
    }

    /**
     * Prüft alle Zeilen, ohne etwas zu speichern.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int|null>  $mapping  Feld → Spaltenindex
     * @return array{rows: array<int, array<string, mixed>>, ok: int, duplicate: int, error: int}
     */
    public function analyze(array $rows, array $mapping): array
    {
        $existing = Customer::withTrashed()->pluck('customer_number')->map(fn ($n) => (string) $n)->flip()->all();

        // Automatische Nummern setzen hinter der höchsten vorhandenen oder in der Datei genannten Zahl an.
        $numericMax = 0;
        foreach (array_keys($existing) as $number) {
            if (ctype_digit((string) $number) && strlen((string) $number) < 10) {
                $numericMax = max($numericMax, (int) $number);
            }
        }
        foreach ($rows as $cells) {
            $n = $this->cell($cells, $mapping['customer_number'] ?? null);
            if ($n !== '' && ctype_digit($n) && strlen($n) < 10) {
                $numericMax = max($numericMax, (int) $n);
            }
        }

        $seen = $existing;
        $result = ['rows' => [], 'ok' => 0, 'duplicate' => 0, 'error' => 0];

        foreach ($rows as $i => $cells) {
            $data = [];
            foreach (self::FIELDS as $field) {
                $data[$field] = $this->cell($cells, $mapping[$field] ?? null);
            }

            // Häkchen „Aktiv“: leer = ja (Standard).
            $data['active'] = CustomerRules::flag($data['active']) ?? '';

            $data['country'] = $data['country'] === '' ? 'DE' : $data['country'];
            $data['currency'] = $data['currency'] === '' ? 'EUR' : $data['currency'];
            $auto = $data['customer_number'] === '';
            if ($auto) {
                $data['customer_number'] = (string) (++$numericMax);
            }
            $data = array_merge($data, CustomerRules::normalize($data));

            $entry = ['line' => $i + 2, 'data' => $data, 'auto_number' => $auto, 'status' => 'ok', 'reason' => ''];

            $validator = Validator::make($data, CustomerRules::rules([], $data));
            $validator->setAttributeNames(collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => $this->label($f)])->all());

            if ($validator->fails()) {
                $entry['status'] = 'error';
                $entry['reason'] = implode(' ', array_map(fn ($messages) => $messages[0], $validator->errors()->messages()));
                $result['error']++;
            } elseif (isset($seen[$data['customer_number']])) {
                $entry['status'] = 'duplicate';
                $entry['reason'] = __(isset($existing[$data['customer_number']])
                    ? 'messages.customer_import.dup_existing' : 'messages.customer_import.dup_file', ['number' => $data['customer_number']]);
                $result['duplicate']++;
            } else {
                $seen[$data['customer_number']] = true;
                $result['ok']++;
            }

            $result['rows'][] = $entry;
        }

        return $result;
    }

    /**
     * Legt alle fehlerfreien Zeilen an (alles oder nichts). Doppelte und fehlerhafte Zeilen werden übersprungen.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int|null>  $mapping
     * @return array{created: int, duplicate: int, error: int}
     */
    public function run(array $rows, array $mapping): array
    {
        $analysis = $this->analyze($rows, $mapping);

        DB::transaction(function () use ($analysis) {
            foreach ($analysis['rows'] as $entry) {
                if ($entry['status'] === 'ok') {
                    $row = array_map(fn ($v) => $v === '' ? null : $v, $entry['data']);
                    // Leere Werte sollen die Standardwerte des Models greifen lassen (seit = heute, aktiv = ja).
                    foreach (['customer_since', 'active'] as $key) {
                        if ($row[$key] === null) {
                            unset($row[$key]);
                        }
                    }
                    Customer::create($row);
                }
            }
        });

        return ['created' => $analysis['ok'], 'duplicate' => $analysis['duplicate'], 'error' => $analysis['error']];
    }

    public function label(string $field): string
    {
        return __('messages.customers.'.($field === 'customer_number' ? 'number' : $field));
    }

    /** Vereinfachte Schreibweise für den Überschriften-Vergleich: klein, Umlaute aufgelöst, nur Buchstaben und Ziffern. */
    public static function simplify(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);

        return preg_replace('/[^a-z0-9%]+/', '', $text) ?? '';
    }

    /** @param  array<int, string>  $cells */
    private function cell(array $cells, ?int $index): string
    {
        return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
    }

    /** @param  array<int, mixed>  $cells */
    private static function blank(array $cells): bool
    {
        return trim(implode('', array_map(fn ($c) => (string) $c, $cells))) === '';
    }
}
