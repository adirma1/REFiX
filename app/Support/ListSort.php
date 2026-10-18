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

use Closure;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sortierung der Listen per Klick auf den Spaltenkopf (aufsteigend/absteigend im Wechsel).
 *
 * Die Spalten einer Liste werden fest vorgegeben; nur diese Schlüssel werden aus der URL
 * (?sort=...&dir=asc|desc) angenommen. Die zuletzt gewählte Sortierung wird je Benutzer und
 * Liste gemerkt und gilt, solange die URL keine eigene Angabe enthält.
 */
class ListSort
{
    public const ASC = 'asc';

    public const DESC = 'desc';

    /**
     * @param  string  $list  Kennung der Liste (z. B. "invoices"), unter der die Sortierung gemerkt wird
     * @param  array<string, string|Closure>  $columns  Schlüssel => Spaltenname oder Closure.
     *                                                    Abfrage: Closure(Builder, dir); Sammlung: Closure(Element) => Wert.
     * @param  array<int, array{0:string,1:string}>  $then  Weitere Sortierungen als Auffangregel bei gleichen Werten
     */
    public function __construct(
        public readonly string $list,
        public readonly array $columns,
        public readonly string $key,
        public readonly string $dir,
        public readonly array $then = [],
        private readonly ?Request $request = null,
    ) {}

    /**
     * Ermittelt die gültige Sortierung: URL vor gemerktem Wert vor Standard. Eine neue Wahl aus der URL wird gemerkt.
     *
     * @param  array{0:string,1:string}  $default  [Schlüssel, Richtung] ohne Klick
     * @param  array<int, array{0:string,1:string}>  $then  Auffangregel bei gleichen Werten: [Spaltenname, Richtung]
     */
    public static function make(Request $request, string $list, array $columns, array $default, array $then = []): self
    {
        [$defaultKey, $defaultDir] = $default;
        $user = $request->user();
        $stored = self::stored($user)[$list] ?? [];

        $key = $defaultKey;
        $dir = $defaultDir;

        $askedKey = $request->query('sort');
        $askedDir = $request->query('dir');

        if (is_string($askedKey) && array_key_exists($askedKey, $columns)) {
            $key = $askedKey;
            $dir = $askedDir === self::DESC ? self::DESC : self::ASC;

            if (($stored['sort'] ?? null) !== $key || ($stored['dir'] ?? null) !== $dir) {
                self::remember($user, $list, $key, $dir);
            }
        } elseif (isset($stored['sort']) && array_key_exists($stored['sort'], $columns)) {
            $key = $stored['sort'];
            $dir = ($stored['dir'] ?? null) === self::DESC ? self::DESC : self::ASC;
        }

        return new self($list, $columns, $key, $dir, $then, $request);
    }

    /** Sortiert eine Datenbankabfrage; bei gleichen Werten greift die Auffangregel. */
    public function apply(BuilderContract $query): BuilderContract
    {
        $column = $this->columns[$this->key];

        if ($column instanceof Closure) {
            $column($query, $this->dir);
        } else {
            $query->orderBy($column, $this->dir);
        }

        foreach ($this->then as [$name, $direction]) {
            $query->orderBy($name, $direction);
        }

        return $query;
    }

    /** Sortiert eine bereits geladene Sammlung (Dateilisten, berechnete Werte). Texte ohne Rücksicht auf Groß-/Kleinschreibung. */
    public function sortCollection(Collection $items): Collection
    {
        $column = $this->columns[$this->key];
        $value = static function ($item) use ($column) {
            $v = $column instanceof Closure ? $column($item) : data_get($item, $column);

            return is_string($v) ? mb_strtolower($v) : $v;
        };

        return $items->sortBy($value, SORT_NATURAL, $this->dir === self::DESC)->values();
    }

    /** Spaltenregel „nach Kundenname“ für Listen mit customer_id (Firma, sonst Nachname). */
    public static function byCustomer(string $table, string $foreignKey = 'customer_id'): Closure
    {
        return fn ($query, string $dir) => $query->orderByRaw(
            "(select coalesce(nullif(customers.company, ''), customers.last_name, '') from customers where customers.id = {$table}.{$foreignKey}) {$dir}"
        );
    }

    public function isActive(string $key): bool
    {
        return $this->key === $key;
    }

    /** Link für den Spaltenkopf: aktive Spalte wechselt die Richtung, andere Spalten starten aufsteigend. Filter bleiben, die Seite beginnt neu. */
    public function url(string $key): string
    {
        $dir = $this->isActive($key) && $this->dir === self::ASC ? self::DESC : self::ASC;
        $request = $this->request ?? request();
        $query = collect($request->query())->except(['page', 'sort', 'dir'])->all();

        return $request->url().'?'.http_build_query(array_merge($query, ['sort' => $key, 'dir' => $dir]));
    }

    /** Wert für aria-sort. */
    public function ariaSort(string $key): string
    {
        if (! $this->isActive($key)) {
            return 'none';
        }

        return $this->dir === self::ASC ? 'ascending' : 'descending';
    }

    /** @return array<string, array{sort?:string,dir?:string}> */
    private static function stored(?object $user): array
    {
        $raw = $user?->list_sorts ?? null;
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : [];
    }

    /** Schreibt direkt in die Tabelle: eine Listenansicht soll keinen Audit-Eintrag am Benutzer erzeugen. */
    private static function remember(?object $user, string $list, string $key, string $dir): void
    {
        if (! $user || ! isset($user->id)) {
            return;
        }

        $all = self::stored($user);
        $all[$list] = ['sort' => $key, 'dir' => $dir];
        $json = json_encode($all);

        DB::table('users')->where('id', $user->id)->update(['list_sorts' => $json]);
        $user->setAttribute('list_sorts', $json);
        $user->syncOriginalAttribute('list_sorts');
    }
}
