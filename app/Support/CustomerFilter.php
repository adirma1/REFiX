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

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/** Suche und Filter der Kundenliste; wird auch vom CSV-Export benutzt, damit beide dieselbe Auswahl meinen. */
final class CustomerFilter
{
    /** Alle vorkommenden Werte für die Auswahlfelder (Land, Währung, Tags). */
    public static function options(): array
    {
        $countries = Customer::query()->whereNotNull('country')->where('country', '<>', '')
            ->distinct()->orderBy('country')->pluck('country')->all();
        $currencies = Customer::query()->whereNotNull('currency')->where('currency', '<>', '')
            ->distinct()->orderBy('currency')->pluck('currency')->all();

        // Einzelne Wörter aus dem Tag-Feld.
        $tags = Customer::query()->whereNotNull('tags')->where('tags', '<>', '')->pluck('tags')
            ->flatMap(fn ($t) => preg_split('/\s+/', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return ['countries' => $countries, 'currencies' => $currencies, 'tags' => $tags];
    }

    /**
     * Liest Suche und Filter aus der URL (nur erlaubte Werte).
     *
     * @return array{search: string, filters: array<string, string>}
     */
    public static function fromRequest(Request $request, array $options): array
    {
        return [
            'search' => trim((string) $request->query('q')),
            'filters' => [
                'kind' => ListFilter::pick($request, 'kind', ['company', 'private']),
                'country' => ListFilter::pick($request, 'country', $options['countries']),
                'currency' => ListFilter::pick($request, 'currency', $options['currencies']),
                'ref' => ListFilter::pick($request, 'ref', ['1']),
                'status' => ListFilter::pick($request, 'status', ['active', 'inactive']),
                'tag' => ListFilter::pick($request, 'tag', $options['tags']),
            ],
        ];
    }

    /** @param  array<string, string>  $filters */
    public static function query(string $search, array $filters, bool $withTrashed = false): Builder
    {
        return Customer::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('customer_number', 'like', $like)
                        ->orWhere('company', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('street', 'like', $like)
                        ->orWhere('zip', 'like', $like)
                        ->orWhere('city', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('tags', 'like', $like);
                });
            })
            ->when(($filters['kind'] ?? '') === 'private', fn ($q) => $q->privatePersons())
            ->when(($filters['kind'] ?? '') === 'company', fn ($q) => $q->businesses())
            ->when(($filters['country'] ?? '') !== '', fn ($q) => $q->where('country', $filters['country']))
            ->when(($filters['currency'] ?? '') !== '', fn ($q) => $q->where('currency', $filters['currency']))
            ->when(($filters['ref'] ?? '') === '1', fn ($q) => $q->whereNotNull('buyer_reference')->where('buyer_reference', '<>', ''))
            ->when(($filters['status'] ?? '') === 'active', fn ($q) => $q->where('active', true))
            ->when(($filters['status'] ?? '') === 'inactive', fn ($q) => $q->where('active', false))
            ->when(($filters['tag'] ?? '') !== '', function ($q) use ($filters) {
                // Ganzes Tag-Wort treffen (nicht „#m“ in „#mm“): Wort steht allein, am Anfang, am Ende oder zwischen Leerzeichen.
                $tag = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['tag']);
                $q->where(fn ($w) => $w->where('tags', $filters['tag'])
                    ->orWhere('tags', 'like', $tag.' %')
                    ->orWhere('tags', 'like', '% '.$tag)
                    ->orWhere('tags', 'like', '% '.$tag.' %'));
            })
            ->orderBy('customer_number');
    }
}
