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

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Zeitraum-Filter für Listen (Knopf mit Schnellwahlen, Komponente <x-range-filter>): Werte aus der URL prüfen, Schnellwahlen und Beschriftung bilden. */
class DateRangeFilter
{
    /** Datum aus der URL (JJJJ-MM-TT) oder leer, wenn es keins ist. */
    public static function date(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : '';
    }

    /** @return array{0: string, 1: string} [von, bis] aus den Parametern ${prefix}_from und ${prefix}_to */
    public static function fromRequest(Request $request, string $prefix = 'date'): array
    {
        return [self::date($request->query($prefix.'_from')), self::date($request->query($prefix.'_to'))];
    }

    /**
     * Schnellwahlen für Belegdaten (Rechnungen, Angebote): meist rückblickend.
     *
     * @return array<int, array{label: string, query: array<string, string>}>
     */
    public static function documentPresets(string $prefix = 'date', ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $year = $today->year;
        $range = fn (Carbon $a, Carbon $b) => [$prefix.'_from' => $a->toDateString(), $prefix.'_to' => $b->toDateString()];

        return [
            ['label' => __('messages.filter.range_this_month'), 'query' => $range($today->copy()->startOfMonth(), $today->copy()->endOfMonth())],
            ['label' => __('messages.filter.range_last_month'), 'query' => $range($today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth())],
            ['label' => __('messages.filter.range_this_quarter'), 'query' => $range($today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter())],
            ['label' => __('messages.filter.range_last_quarter'), 'query' => $range($today->copy()->subMonthsNoOverflow(3)->firstOfQuarter(), $today->copy()->subMonthsNoOverflow(3)->lastOfQuarter())],
            ['label' => __('messages.filter.until_this_year', ['y' => $year]), 'query' => $range(Carbon::create($year, 1, 1), Carbon::create($year, 12, 31))],
            ['label' => __('messages.filter.until_last_year', ['y' => $year - 1]), 'query' => $range(Carbon::create($year - 1, 1, 1), Carbon::create($year - 1, 12, 31))],
            ['label' => __('messages.filter.range_last_30'), 'query' => $range($today->copy()->subDays(29), $today)],
        ];
    }

    /**
     * Schnellwahlen für „Kunde bis“ (vorausschauend, dazu „abgelaufen“ und „ohne Enddatum“ als Sonderwerte im Parameter until).
     *
     * @return array<int, array{label: string, query: array<string, string>, on?: bool}>
     */
    public static function untilPresets(string $mode, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $year = $today->year;
        $range = fn (Carbon $a, Carbon $b) => ['until_from' => $a->toDateString(), 'until_to' => $b->toDateString()];

        return [
            ['label' => __('messages.filter.until_this_year', ['y' => $year]), 'query' => $range(Carbon::create($year, 1, 1), Carbon::create($year, 12, 31))],
            ['label' => __('messages.filter.until_next_year', ['y' => $year + 1]), 'query' => $range(Carbon::create($year + 1, 1, 1), Carbon::create($year + 1, 12, 31))],
            ['label' => __('messages.filter.until_last_year', ['y' => $year - 1]), 'query' => $range(Carbon::create($year - 1, 1, 1), Carbon::create($year - 1, 12, 31))],
            ['label' => __('messages.filter.until_quarter'), 'query' => $range($today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter())],
            ['label' => __('messages.filter.until_30'), 'query' => $range($today, $today->copy()->addDays(30))],
            ['label' => __('messages.filter.until_expired'), 'query' => ['until' => 'expired'], 'on' => $mode === 'expired'],
            ['label' => __('messages.filter.until_none'), 'query' => ['until' => 'none'], 'on' => $mode === 'none'],
        ];
    }

    /**
     * Schnellwahlen für Fälligkeiten (Offene Posten): Vergangenes und Kommendes.
     *
     * @return array<int, array{label: string, query: array<string, string>}>
     */
    public static function duePresets(string $prefix = 'due', ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $range = fn (Carbon $a, Carbon $b) => [$prefix.'_from' => $a->toDateString(), $prefix.'_to' => $b->toDateString()];

        return [
            ['label' => __('messages.filter.range_today'), 'query' => $range($today, $today)],
            ['label' => __('messages.filter.range_this_week'), 'query' => $range($today->copy()->startOfWeek(), $today->copy()->endOfWeek())],
            ['label' => __('messages.filter.range_next_7'), 'query' => $range($today, $today->copy()->addDays(7))],
            ['label' => __('messages.filter.until_30'), 'query' => $range($today, $today->copy()->addDays(30))],
            ['label' => __('messages.filter.range_this_month'), 'query' => $range($today->copy()->startOfMonth(), $today->copy()->endOfMonth())],
            ['label' => __('messages.filter.range_last_month'), 'query' => $range($today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth())],
            ['label' => __('messages.filter.range_this_quarter'), 'query' => $range($today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter())],
        ];
    }

    /**
     * Schnellwahlen für „Gültig bis“ bei Angeboten: vorausschauend.
     *
     * @return array<int, array{label: string, query: array<string, string>}>
     */
    public static function validityPresets(string $prefix = 'valid', ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $year = $today->year;
        $range = fn (Carbon $a, Carbon $b) => [$prefix.'_from' => $a->toDateString(), $prefix.'_to' => $b->toDateString()];

        return [
            ['label' => __('messages.filter.range_next_7'), 'query' => $range($today, $today->copy()->addDays(7))],
            ['label' => __('messages.filter.until_30'), 'query' => $range($today, $today->copy()->addDays(30))],
            ['label' => __('messages.filter.range_this_month'), 'query' => $range($today->copy()->startOfMonth(), $today->copy()->endOfMonth())],
            ['label' => __('messages.filter.range_this_quarter'), 'query' => $range($today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter())],
            ['label' => __('messages.filter.until_this_year', ['y' => $year]), 'query' => $range(Carbon::create($year, 1, 1), Carbon::create($year, 12, 31))],
            ['label' => __('messages.filter.until_next_year', ['y' => $year + 1]), 'query' => $range(Carbon::create($year + 1, 1, 1), Carbon::create($year + 1, 12, 31))],
        ];
    }

    /** Text für den Knopf bei aktivem Zeitraum: „2026“ für ein ganzes Jahr, sonst „01.03.2026–31.05.2026“ (offene Grenze als „…“). */
    public static function label(string $from, string $to): string
    {
        if ($from === '' && $to === '') {
            return '';
        }

        if ($from !== '' && $to !== '' && substr($from, 0, 4) === substr($to, 0, 4) && substr($from, 5) === '01-01' && substr($to, 5) === '12-31') {
            return substr($from, 0, 4);
        }

        $format = fn (string $d) => Carbon::parse($d)->format('d.m.Y');

        return ($from !== '' ? $format($from) : '…').'–'.($to !== '' ? $format($to) : '…');
    }
}
