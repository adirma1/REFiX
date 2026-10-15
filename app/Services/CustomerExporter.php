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

use App\Services\Import\CustomerImporter;
use Illuminate\Database\Eloquent\Builder;

/** Kunden als CSV ausgeben: frei wählbare Felder, Trennzeichen, Zeichensatz und Datumsformat. */
final class CustomerExporter
{
    /** Felder je Gruppe für die Auswahl; die Reihenfolge der Spalten folgt CustomerImporter::FIELDS (wie die Importvorlage). */
    public const GROUPS = [
        'master' => ['customer_number', 'company', 'salutation', 'first_name', 'last_name', 'street', 'zip', 'city', 'country',
            'email', 'phone', 'vat_id', 'buyer_reference', 'website', 'birthday'],
        'terms' => ['currency', 'payment_term_days', 'discount_percent', 'discount_days', 'notes'],
        'status' => ['customer_since', 'customer_until', 'active', 'tags'],
    ];

    public const DELIMITERS = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t"];

    public const ENCODINGS = ['utf8', 'cp1252'];

    public const DATE_FORMATS = ['de', 'iso'];

    private const DATE_FIELDS = ['customer_since', 'customer_until', 'birthday'];

    /** Alle exportierbaren Felder in Spaltenreihenfolge. */
    public static function fields(): array
    {
        return CustomerImporter::FIELDS;
    }

    /** Standard: alle Felder der Importvorlage, damit sich ein Export wieder importieren lässt. */
    public static function defaults(): array
    {
        return [
            'fields' => self::fields(),
            'delimiter' => 'semicolon',
            'encoding' => 'utf8',
            'date_format' => 'de',
        ];
    }

    /** Gespeicherte Einstellungen eines Benutzers; unbrauchbare Werte fallen auf den Standard zurück. */
    public static function prefs(?string $json): array
    {
        $d = self::defaults();
        $saved = $json ? json_decode($json, true) : null;

        if (! is_array($saved)) {
            return $d;
        }

        $fields = array_values(array_intersect(self::fields(), (array) ($saved['fields'] ?? [])));

        return [
            'fields' => $fields !== [] ? $fields : $d['fields'],
            'delimiter' => array_key_exists($saved['delimiter'] ?? '', self::DELIMITERS) ? $saved['delimiter'] : $d['delimiter'],
            'encoding' => in_array($saved['encoding'] ?? '', self::ENCODINGS, true) ? $saved['encoding'] : $d['encoding'],
            'date_format' => in_array($saved['date_format'] ?? '', self::DATE_FORMATS, true) ? $saved['date_format'] : $d['date_format'],
        ];
    }

    public static function label(string $field): string
    {
        return __('messages.customers.'.($field === 'customer_number' ? 'number' : $field));
    }

    /** Wert eines Feldes als Text. */
    public static function value(\App\Models\Customer $customer, string $field, string $dateFormat): string
    {
        $value = $customer->{$field};

        if (in_array($field, self::DATE_FIELDS, true)) {
            return $value ? $value->format($dateFormat === 'iso' ? 'Y-m-d' : 'd.m.Y') : '';
        }

        if ($field === 'active') {
            return $value ? 'ja' : 'nein';
        }

        if ($field === 'discount_percent') {
            return $value === null ? '' : ($dateFormat === 'iso' ? (string) $value : str_replace('.', ',', (string) $value));
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Schützt vor Formeleinschleusung in Tabellenprogrammen: Beginnt ein Text mit = + - @ (oder Tab/Zeilenumbruch) und ist
     * mehr als eine Telefonnummer oder Zahl, bekommt er ein vorangestelltes Hochkomma.
     */
    public static function safe(string $text): string
    {
        if ($text !== '' && str_contains("=+-@\t\r", $text[0]) && ! preg_match('/^[+-]?[0-9][0-9 ()\/.,-]*$/', $text)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * Schreibt die Kunden als CSV in den Ausgabestrom.
     *
     * @param  array<int, string>  $fields  Feldnamen in Spaltenreihenfolge
     * @param  array{delimiter: string, encoding: string, date_format: string}  $options
     * @param  resource  $out
     */
    public function write(Builder $query, array $fields, array $options, $out): void
    {
        $delimiter = self::DELIMITERS[$options['delimiter']] ?? ';';
        $convert = fn (array $row) => $options['encoding'] === 'cp1252'
            ? array_map(fn ($cell) => mb_convert_encoding($cell, 'Windows-1252', 'UTF-8'), $row)
            : $row;

        if ($options['encoding'] !== 'cp1252') {
            fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel UTF-8 erkennt
        }

        fputcsv($out, $convert(array_map(fn ($f) => self::safe(self::label($f)), $fields)), $delimiter, '"', '', "\r\n");

        foreach ($query->cursor() as $customer) {
            $row = array_map(fn ($f) => self::safe(self::value($customer, $f, $options['date_format'])), $fields);
            fputcsv($out, $convert($row), $delimiter, '"', '', "\r\n");
        }
    }
}
