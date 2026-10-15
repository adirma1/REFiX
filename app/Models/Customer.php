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

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'customer_number', 'company', 'salutation', 'first_name', 'last_name',
        'street', 'zip', 'city', 'country', 'email', 'phone', 'vat_id', 'currency',
        'payment_term_days', 'discount_percent', 'discount_days', 'buyer_reference', 'notes',
        'customer_since', 'customer_until', 'active', 'tags', 'birthday', 'website',
    ];

    protected function casts(): array
    {
        return [
            'payment_term_days' => 'integer',
            'discount_days' => 'integer',
            'discount_percent' => 'decimal:2',
            'customer_since' => 'date',
            'customer_until' => 'date',
            'birthday' => 'date',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Privatperson: Ohne Firma gilt der Name (Vor- und Nachname) als Anschrift. So funktionieren Listen,
        // Belege, PDF und E-Rechnung unverändert.
        static::saving(function (Customer $customer) {
            if (trim((string) $customer->company) === '') {
                $customer->company = trim($customer->first_name.' '.$customer->last_name);
            }

            // Kunde seit: ohne Angabe gilt heute.
            if ($customer->customer_since === null) {
                $customer->customer_since = now()->startOfDay();
            }

            // Ist das „Kunde bis“-Datum vorbei, ist der Kunde nicht mehr aktiv.
            if ($customer->active !== false && $customer->customer_until !== null && $customer->customer_until->lt(now()->startOfDay())) {
                $customer->active = false;
            }
        });

        static::creating(function (Customer $customer) {
            if ($customer->active === null) {
                $customer->active = true;
            }
        });
    }

    /** Nur Kunden, für die noch Rechnungen angelegt werden dürfen (aktiv, „Kunde bis“ nicht überschritten). */
    public function scopeSelectable($query, ?int $alsoId = null)
    {
        $today = now()->toDateString();

        return $query->where(function ($q) use ($today, $alsoId) {
            $q->where(function ($open) use ($today) {
                $open->where('active', true)
                    ->where(fn ($u) => $u->whereNull('customer_until')->orWhereDate('customer_until', '>=', $today));
            });
            if ($alsoId) {
                $q->orWhere('id', $alsoId);
            }
        });
    }

    /**
     * Gesperrt für neue Rechnungen zum Belegdatum? Inaktiv = immer; mit „Kunde bis“ = Belegdatum danach.
     * Das Datum selbst ist noch erlaubt.
     */
    public function isBlockedFor(\DateTimeInterface|string|null $date = null): bool
    {
        if (! $this->active) {
            return true;
        }

        if ($this->customer_until === null) {
            return false;
        }

        $day = $date === null ? now() : \Illuminate\Support\Carbon::parse($date);

        return $day->copy()->startOfDay()->gt($this->customer_until->copy()->startOfDay());
    }

    /** Fehlermeldung für gesperrte Kunden (wird von den Diensten als RuntimeException geworfen). */
    public function blockedMessage(): string
    {
        return __('messages.customers.blocked', ['name' => $this->company]);
    }

    /** Tags als Liste („#m #w“ → ['#m', '#w']). */
    public function tagList(): array
    {
        return preg_split('/\s+/', trim((string) $this->tags), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** SQL-Ausdruck „Vorname Nachname“ (portabel für SQLite in den Tests und MariaDB). */
    private static function nameSql(): string
    {
        return \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
            ? "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))"
            : "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
    }

    /** Nur Privatpersonen (Firma == Name). */
    public function scopePrivatePersons($query)
    {
        $name = self::nameSql();

        return $query->whereRaw("{$name} <> '' AND company = {$name}");
    }

    /** Nur Firmen (alles außer Privatpersonen). */
    public function scopeBusinesses($query)
    {
        $name = self::nameSql();

        return $query->whereRaw("NOT ({$name} <> '' AND company = {$name})");
    }

    /** Privatperson: die „Firma“ ist nichts anderes als der Name der Kontaktperson. */
    public function isPrivate(): bool
    {
        $name = trim($this->first_name.' '.$this->last_name);

        return $name !== '' && $this->company === $name;
    }

    /** Kontaktzeile unter der Firma; bei Privatpersonen entfällt sie (der Name steht schon als Anschrift da). */
    public function contactLine(): string
    {
        return $this->isPrivate() ? '' : $this->contactName();
    }

    public function contactName(): string
    {
        return trim(($this->salutation ? $this->salutation.' ' : '').$this->first_name.' '.$this->last_name);
    }
}
