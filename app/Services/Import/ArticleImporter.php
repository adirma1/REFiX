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

use App\Models\Article;
use App\Models\ArticlePrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Artikelimport aus CSV. Die Datei wird wie beim Kundenimport gelesen (CustomerImporter::parse), die Spalten per
 * Zuordnung den Feldern zugewiesen. Artikel mit bereits vergebener Artikelnummer werden übersprungen oder – auf Wunsch –
 * aktualisiert; ein geänderter Preis kommt dann als neuer Preis dazu, der alte bleibt erhalten.
 * Alles gilt nur für den aktuellen Mandanten (BelongsToTenant).
 */
class ArticleImporter
{
    public const SKIP = 'skip';

    public const UPDATE = 'update';

    /** Felder in der Reihenfolge der Zuordnungsseite. */
    public const FIELDS = ['article_number', 'name', 'description', 'unit', 'tax_rate', 'price', 'currency', 'valid_from', 'active'];

    /** Überschriften, die dem Feld zugeordnet werden (vereinfacht geschrieben, siehe CustomerImporter::simplify()). */
    private const SYNONYMS = [
        'article_number' => ['artikelnummer', 'artikelnr', 'artnr', 'artikelno', 'nummer', 'nr', 'sku', 'articlenumber', 'number'],
        'name' => ['bezeichnung', 'artikelbezeichnung', 'artikelname', 'name', 'artikel', 'produkt', 'titel', 'kurztext'],
        'description' => ['beschreibung', 'langtext', 'text', 'description', 'artikelbeschreibung'],
        'unit' => ['einheit', 'me', 'mengeneinheit', 'unit'],
        'tax_rate' => ['steuersatz', 'mwst', 'mwstsatz', 'ust', 'ustsatz', 'umsatzsteuer', 'steuer', 'taxrate', 'mwst%'],
        'price' => ['preis', 'preisnetto', 'nettopreis', 'vkpreis', 'vk', 'verkaufspreis', 'einzelpreis', 'price', 'betrag'],
        'currency' => ['waehrung', 'currency'],
        'valid_from' => ['gueltigab', 'gueltigvon', 'preisgueltigab', 'validfrom', 'gueltig'],
        'active' => ['aktiv', 'active', 'status'],
    ];

    /**
     * Prüft alle Zeilen, ohne etwas zu speichern.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int|null>  $mapping  Feld → Spaltenindex
     * @return array{rows: array<int, array<string, mixed>>, ok: int, update: int, duplicate: int, error: int}
     */
    public function analyze(array $rows, array $mapping, string $mode = self::SKIP): array
    {
        $existing = Article::withTrashed()->whereNotNull('article_number')->get()->keyBy(fn ($a) => (string) $a->article_number);
        $seen = [];
        $result = ['rows' => [], 'ok' => 0, 'update' => 0, 'duplicate' => 0, 'error' => 0];

        foreach ($rows as $i => $cells) {
            $raw = [];
            foreach (self::FIELDS as $field) {
                $raw[$field] = $this->cell($cells, $mapping[$field] ?? null);
            }

            $entry = ['line' => $i + 2, 'data' => $raw, 'status' => 'ok', 'reason' => '', 'article_id' => null, 'price' => null, 'note' => ''];
            $found = $raw['article_number'] !== '' ? ($existing[$raw['article_number']] ?? null) : null;
            $isUpdate = $mode === self::UPDATE && $found !== null && ! $found->trashed();

            [$data, $errors] = $this->normalize($raw, $mapping, $isUpdate);
            $entry['data'] = $data + $raw;

            if ($errors !== []) {
                $entry['status'] = 'error';
                $entry['reason'] = implode(' ', $errors);
                $result['error']++;
            } elseif ($raw['article_number'] !== '' && isset($seen[$raw['article_number']])) {
                $entry['status'] = 'duplicate';
                $entry['reason'] = __('messages.article_import.dup_file', ['number' => $raw['article_number']]);
                $result['duplicate']++;
            } elseif ($found !== null && ! $isUpdate) {
                $entry['status'] = 'duplicate';
                $entry['reason'] = __('messages.article_import.dup_existing', ['number' => $raw['article_number']]);
                $result['duplicate']++;
            } else {
                if ($raw['article_number'] !== '') {
                    $seen[$raw['article_number']] = true;
                }

                if ($isUpdate) {
                    $entry['status'] = 'update';
                    $entry['article_id'] = $found->id;
                    [$entry['price'], $entry['note']] = $this->priceAction($found, $data);
                    $result['update']++;
                } else {
                    $entry['price'] = $data['price'] !== null ? 'new' : null;
                    $result['ok']++;
                }
            }

            $result['rows'][] = $entry;
        }

        return $result;
    }

    /**
     * Legt neue Artikel an und aktualisiert vorhandene (alles oder nichts). Doppelte und fehlerhafte Zeilen werden übersprungen.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<string, int|null>  $mapping
     * @return array{created: int, updated: int, duplicate: int, error: int}
     */
    public function run(array $rows, array $mapping, string $mode = self::SKIP): array
    {
        $analysis = $this->analyze($rows, $mapping, $mode);

        DB::transaction(function () use ($analysis, $mapping) {
            foreach ($analysis['rows'] as $entry) {
                $d = $entry['data'];

                if ($entry['status'] === 'ok') {
                    $article = Article::create([
                        'article_number' => $d['article_number'] === '' ? null : $d['article_number'],
                        'name' => $d['name'], 'description' => $d['description'] === '' ? null : $d['description'],
                        'unit' => $d['unit'], 'tax_rate' => $d['tax_rate'], 'active' => $d['active'],
                    ]);
                } elseif ($entry['status'] === 'update') {
                    $article = Article::findOrFail($entry['article_id']);
                    $changes = ['name' => $d['name'], 'unit' => $d['unit']];
                    if (($mapping['description'] ?? null) !== null) {
                        $changes['description'] = $d['description'] === '' ? null : $d['description'];
                    }
                    if (($mapping['tax_rate'] ?? null) !== null && $d['tax_rate'] !== null) {
                        $changes['tax_rate'] = $d['tax_rate'];
                    }
                    if (($mapping['active'] ?? null) !== null && $d['active'] !== null) {
                        $changes['active'] = $d['active'];
                    }
                    $article->update($changes);
                } else {
                    continue;
                }

                if ($entry['price'] === 'new') {
                    ArticlePrice::create([
                        'article_id' => $article->id, 'customer_id' => null, 'currency' => $d['currency'],
                        'amount' => $d['price'], 'valid_from' => $d['valid_from'],
                    ]);
                }
            }
        });

        return ['created' => $analysis['ok'], 'updated' => $analysis['update'], 'duplicate' => $analysis['duplicate'], 'error' => $analysis['error']];
    }

    /**
     * Schlägt die Zuordnung Feld → Spaltenindex anhand der Überschriften vor.
     *
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    public static function guess(array $headers): array
    {
        $simple = array_map(fn ($h) => CustomerImporter::simplify($h), $headers);
        $used = [];
        $map = [];

        foreach (self::FIELDS as $field) {
            $map[$field] = null;
            foreach (self::SYNONYMS[$field] as $synonym) {
                $index = array_search(CustomerImporter::simplify($synonym), $simple, true);
                if ($index !== false && ! in_array($index, $used, true)) {
                    $map[$field] = $index;
                    $used[] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    public function label(string $field): string
    {
        return __('messages.article_import.field_'.$field);
    }

    /**
     * Bereinigt die Rohwerte und prüft sie. Leere Angaben: Steuersatz 19, aktiv ja, Währung EUR, Preis gültig ab heute.
     * Beim Aktualisieren bleiben leere Steuersatz- und Aktiv-Angaben unverändert (null).
     *
     * @param  array<string, string>  $raw
     * @param  array<string, int|null>  $mapping
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function normalize(array $raw, array $mapping, bool $isUpdate): array
    {
        $errors = [];

        $tax = str_replace(',', '.', $raw['tax_rate']);
        $tax = rtrim(str_replace('%', '', $tax));
        $active = $this->bool($raw['active']);
        $price = $this->decimal($raw['price']);

        if ($raw['active'] !== '' && $active === null) {
            $errors[] = __('messages.article_import.err_active', ['value' => $raw['active']]);
        }

        $validFrom = null;
        if ($raw['valid_from'] !== '') {
            $validFrom = $this->date($raw['valid_from']);
            if ($validFrom === null) {
                $errors[] = __('messages.article_import.err_date', ['value' => $raw['valid_from']]);
            }
        }

        $data = [
            'article_number' => $raw['article_number'],
            'name' => $raw['name'],
            'description' => $raw['description'],
            'unit' => $raw['unit'],
            'tax_rate' => $tax === '' ? ($isUpdate ? null : '19') : $tax,
            'active' => $raw['active'] === '' ? ($isUpdate ? null : true) : $active,
            'price' => $raw['price'] === '' ? null : $price,
            'currency' => $raw['currency'] === '' ? 'EUR' : strtoupper($raw['currency']),
            'valid_from' => $validFrom ?? now()->toDateString(),
        ];

        $validator = Validator::make($data, [
            'article_number' => ['nullable', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'string', 'max:20'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);
        $validator->setAttributeNames(collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => $this->label($f)])->all());

        foreach ($validator->errors()->messages() as $messages) {
            $errors[] = $messages[0];
        }

        if ($raw['price'] !== '' && $price === null && ! $validator->errors()->has('price')) {
            $errors[] = __('messages.article_import.err_price', ['value' => $raw['price']]);
        }

        return [$data, $errors];
    }

    /**
     * Was passiert mit dem Preis eines vorhandenen Artikels?
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: string}  'new' | null, Hinweis
     */
    private function priceAction(Article $article, array $data): array
    {
        if ($data['price'] === null) {
            return [null, ''];
        }

        $sameDay = $article->prices()->whereNull('customer_id')->where('currency', $data['currency'])
            ->whereDate('valid_from', $data['valid_from'])->first();
        if ($sameDay) {
            return [null, (float) $sameDay->amount === (float) $data['price']
                ? '' : __('messages.article_import.note_price_exists')];
        }

        $current = $article->priceFor($data['valid_from'], $data['currency']);
        if ($current && round((float) $current->amount, 4) === round((float) $data['price'], 4)) {
            return [null, ''];
        }

        return ['new', ''];
    }

    private function bool(string $value): ?bool
    {
        $v = mb_strtolower(trim($value));

        return match (true) {
            $v === '' => null,
            in_array($v, ['1', 'ja', 'j', 'yes', 'y', 'true', 'wahr', 'aktiv', 'x'], true) => true,
            in_array($v, ['0', 'nein', 'n', 'no', 'false', 'falsch', 'inaktiv'], true) => false,
            default => null,
        };
    }

    /** "1.234,56", "1234,56" und "1234.56" → "1234.56"; sonst null. */
    private function decimal(string $value): ?string
    {
        $v = trim(str_replace([' ', '€'], '', $value));
        if ($v === '') {
            return null;
        }
        if (str_contains($v, ',')) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }

        return is_numeric($v) ? $v : null;
    }

    /** "2026-01-31" oder "31.01.2026" → "2026-01-31"; sonst null. */
    private function date(string $value): ?string
    {
        foreach (['Y-m-d', 'd.m.Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim($value));
            } catch (\Throwable) {
                continue;
            }
            if ($date && $date->format($format) === trim($value)) {
                return $date->toDateString();
            }
        }

        return null;
    }

    /** @param  array<int, string>  $cells */
    private function cell(array $cells, ?int $index): string
    {
        return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
    }
}
