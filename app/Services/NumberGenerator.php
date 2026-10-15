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

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\NumberCounter;
use App\Models\Quote;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lückenlose, frei definierbare Belegnummern.
 *
 * Platzhalter im Format: {YYYY} {YY} {MM} und {N}, {NN}, {NNNN} … (Anzahl N = Mindeststellen).
 * Der Zähler startet neu, sobald sich der Zeitraum ändert (Jahr bzw. Monat im Format).
 * Testbelege haben ein eigenes Präfix und einen eigenen Zähler.
 */
class NumberGenerator
{
    public const TEST_PREFIX = 'TEST-';

    /** Nummernkreis der Kundennummern: reine Zahl, kein Zeitraum, kein Testkreis. */
    public const CUSTOMER = 'customer';

    public static function validateFormat(string $format): bool
    {
        return (bool) preg_match('/\{N+\}/', $format) && strlen($format) <= 60;
    }

    public static function formatFor(Tenant $tenant, string $type): string
    {
        return match ($type) {
            Invoice::TYPE_CREDIT_NOTE => $tenant->credit_note_number_format,
            Quote::TYPE => $tenant->quote_number_format,
            default => $tenant->invoice_number_format,
        };
    }

    /** Zeitraum-Schlüssel des Zählers abhängig vom Format. */
    public static function period(string $format, CarbonInterface $date): string
    {
        // (Kundennummern haben keinen Zeitraum, siehe periodFor().)
        if (str_contains($format, '{MM}')) {
            return $date->format('Y-m');
        }

        if (str_contains($format, '{YYYY}') || str_contains($format, '{YY}')) {
            return $date->format('Y');
        }

        return '';
    }

    public static function render(string $format, int $number, CarbonInterface $date, bool $isTest = false): string
    {
        if (! self::validateFormat($format)) {
            throw new InvalidArgumentException('Ungültiges Nummernformat: '.$format);
        }

        $result = str_replace(
            ['{YYYY}', '{YY}', '{MM}'],
            [$date->format('Y'), $date->format('y'), $date->format('m')],
            $format
        );

        $result = preg_replace_callback(
            '/\{(N+)\}/',
            fn ($m) => str_pad((string) $number, strlen($m[1]), '0', STR_PAD_LEFT),
            $result
        );

        return ($isTest ? self::TEST_PREFIX : '').$result;
    }

    /** Vergibt die nächste Nummer. Muss innerhalb der Transaktion der Freigabe aufgerufen werden. */
    public static function next(Tenant $tenant, string $type, bool $isTest, CarbonInterface $date): string
    {
        $format = self::formatFor($tenant, $type);
        $period = self::period($format, $date);

        return DB::transaction(function () use ($tenant, $type, $isTest, $date, $format, $period) {
            $counter = self::lockedCounter($tenant->id, $type, $isTest, $period);

            do {
                $counter->last_number++;
                $candidate = self::render($format, $counter->last_number, $date, $isTest);
                // Schutz gegen von Hand zu niedrig gesetzte Zähler: bereits vergebene Nummern überspringen.
                $model = $type === Quote::TYPE ? Quote::class : Invoice::class;
                $taken = $model::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)->where('number', $candidate)->exists();
            } while ($taken);

            $counter->save();

            return $candidate;
        });
    }

    /** Zeitraum-Schlüssel des Zählers für eine Belegart; Kundennummern laufen ohne Zeitraum. */
    private static function periodFor(Tenant $tenant, string $type, CarbonInterface $date): string
    {
        return $type === self::CUSTOMER ? '' : self::period(self::formatFor($tenant, $type), $date);
    }

    /** Ist die Kundennummer im Mandanten schon vergeben (auch bei gelöschten Kunden)? */
    private static function customerNumberTaken(int $tenantId, string $number): bool
    {
        return Customer::withoutGlobalScopes()->withTrashed()
            ->where('tenant_id', $tenantId)->where('customer_number', $number)->exists();
    }

    /** Höchste rein numerische Kundennummer des Mandanten (Startwert, solange der Zähler noch nie benutzt wurde). */
    private static function highestCustomerNumber(int $tenantId): int
    {
        $max = 0;
        Customer::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenantId)->pluck('customer_number')
            ->each(function ($n) use (&$max) {
                if (ctype_digit((string) $n) && strlen((string) $n) < 10) {
                    $max = max($max, (int) $n);
                }
            });

        return $max;
    }

    /** Nächste freie Kundennummer, ohne den Zähler zu verändern (Vorschlag im Formular). */
    public static function peekCustomerNumber(Tenant $tenant): string
    {
        $last = (int) NumberCounter::where([
            'tenant_id' => $tenant->id, 'type' => self::CUSTOMER, 'is_test' => false, 'period' => '',
        ])->value('last_number');

        $n = $last > 0 ? $last : self::highestCustomerNumber($tenant->id);

        do {
            $n++;
        } while (self::customerNumberTaken($tenant->id, (string) $n));

        return (string) $n;
    }

    /** Vergibt die nächste freie Kundennummer und merkt sie sich im Zähler. */
    public static function nextCustomerNumber(Tenant $tenant): string
    {
        return DB::transaction(function () use ($tenant) {
            $counter = self::lockedCounter($tenant->id, self::CUSTOMER, false, '');

            if ($counter->last_number === 0) {
                $counter->last_number = self::highestCustomerNumber($tenant->id);
            }

            do {
                $counter->last_number++;
            } while (self::customerNumberTaken($tenant->id, (string) $counter->last_number));

            $counter->save();

            return (string) $counter->last_number;
        });
    }

    /** Zuletzt vergebene Nummer (Zählerstand) setzen, z. B. für die Übernahme aus dem Altprogramm. */
    public static function setLast(Tenant $tenant, string $type, bool $isTest, CarbonInterface $date, int $last): void
    {
        $period = self::periodFor($tenant, $type, $date);

        DB::transaction(function () use ($tenant, $type, $isTest, $period, $last) {
            $counter = self::lockedCounter($tenant->id, $type, $isTest, $period);
            $counter->last_number = max(0, $last);
            $counter->save();
        });
    }

    public static function lastFor(Tenant $tenant, string $type, bool $isTest, CarbonInterface $date): int
    {
        $period = self::periodFor($tenant, $type, $date);

        return (int) NumberCounter::where([
            'tenant_id' => $tenant->id, 'type' => $type, 'is_test' => $isTest, 'period' => $period,
        ])->value('last_number');
    }

    private static function lockedCounter(int $tenantId, string $type, bool $isTest, string $period): NumberCounter
    {
        $keys = ['tenant_id' => $tenantId, 'type' => $type, 'is_test' => $isTest, 'period' => $period];

        NumberCounter::firstOrCreate($keys, ['last_number' => 0]);

        return NumberCounter::where($keys)->lockForUpdate()->firstOrFail();
    }
}
