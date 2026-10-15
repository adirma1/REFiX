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

namespace App\Support;

/** Gemeinsame Regeln für Kundendaten (Formular und Import). */
final class CustomerRules
{
    /**
     * Vereinheitlicht Eingaben, bevor sie geprüft werden.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function normalize(array $input): array
    {
        return [
            'customer_since' => self::date($input['customer_since'] ?? ''),
            'customer_until' => self::date($input['customer_until'] ?? ''),
            'birthday' => self::date($input['birthday'] ?? ''),
            'website' => self::website($input['website'] ?? ''),
            'tags' => trim((string) preg_replace('/\s+/u', ' ', (string) ($input['tags'] ?? ''))),
            'country' => strtoupper((string) ($input['country'] ?? '')),
            'currency' => strtoupper((string) ($input['currency'] ?? '')),
            'discount_percent' => str_replace(',', '.', (string) ($input['discount_percent'] ?? '')),
            'vat_id' => strtoupper(str_replace(' ', '', (string) ($input['vat_id'] ?? ''))),
        ];
    }

    /** Datum aus Formular oder CSV: „2026-01-31“ oder „31.01.2026“ → „2026-01-31“; sonst unverändert. */
    private static function date(mixed $value): string
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return $value;
    }

    /** Homepage: ohne Schema wird https:// vorangestellt („example.com“ → „https://example.com“). */
    private static function website(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) ? 'https://'.$value : $value;
    }

    /** Häkchen aus dem Import: ja/nein/1/0/x/true/false; leer = null (Standard: aktiv). */
    public static function flag(mixed $value): ?string
    {
        $v = mb_strtolower(trim((string) $value));

        if ($v === '') {
            return null;
        }

        return in_array($v, ['1', 'ja', 'j', 'x', 'true', 'yes', 'y', 'aktiv'], true) ? '1' : '0';
    }

    /**
     * @param  array<int, mixed>  $numberRules  zusätzliche Regeln für die Kundennummer (z. B. Eindeutigkeit)
     * @param  array<string, mixed>  $input  die (normalisierten) Eingaben, für Prüfungen über mehrere Felder
     * @return array<string, array<int, mixed>>
     */
    public static function rules(array $numberRules = [], array $input = []): array
    {
        $until = ['nullable', 'date'];
        if (! empty($input['customer_since']) && strtotime((string) $input['customer_since']) !== false) {
            $until[] = 'after_or_equal:customer_since';
        }

        return [
            'customer_since' => ['nullable', 'date'],
            'customer_until' => $until,
            // Aktiv lässt sich nicht einschalten, solange das „Kunde bis“-Datum vorbei ist.
            'active' => ['nullable', 'boolean', function (string $attribute, mixed $value, \Closure $fail) use ($input) {
                $until = (string) ($input['customer_until'] ?? '');
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && $until !== '' && strtotime($until) !== false
                    && strtotime($until) < strtotime('today')) {
                    $fail(__('messages.customers.err_reactivate'));
                }
            }],
            'tags' => ['nullable', 'string', 'max:30'],
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'website' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'customer_number' => array_merge(['required', 'string', 'max:30'], $numberRules),
            // Firma oder (bei Privatpersonen) Vor-/Nachname genügt; der Name wird beim Speichern als Firma übernommen.
            'company' => ['nullable', 'required_without_all:first_name,last_name', 'string', 'max:255'],
            'salutation' => ['nullable', 'string', 'max:30'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'vat_id' => ['nullable', 'string', 'max:20'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'payment_term_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'buyer_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
