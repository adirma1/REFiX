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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Vorlage für wiederkehrende Rechnungen. Zum Stichtag entsteht automatisch ein Rechnungs-ENTWURF
 * (nie eine freigegebene Rechnung). Der Zeitraum wird aus Stichtag, Rhythmus und Abrechnungsart berechnet.
 */
class RecurringInvoice extends Model
{
    use Auditable, BelongsToTenant;

    public const INTERVALS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    public const ADVANCE = 'advance';

    public const ARREARS = 'arrears';

    public const BILLINGS = [self::ADVANCE, self::ARREARS];

    protected $fillable = [
        'name', 'customer_id', 'active', 'interval', 'billing', 'anchor_date', 'occurrence', 'next_date', 'end_date',
        'currency', 'tax_mode', 'discount_percent', 'payment_term_days', 'buyer_reference',
        'intro_text', 'footer_text', 'internal_notes', 'last_generated_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'anchor_date' => 'date',
            'next_date' => 'date',
            'end_date' => 'date',
            'last_generated_at' => 'datetime',
            'discount_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (RecurringInvoice $recurring) {
            // Bereits erzeugte Rechnungen bleiben bestehen und verlieren nur den Verweis.
            Invoice::withoutGlobalScopes()->where('recurring_invoice_id', $recurring->id)->update(['recurring_invoice_id' => null]);
            RecurringInvoiceItem::withoutGlobalScopes()->where('recurring_invoice_id', $recurring->id)->delete();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecurringInvoiceItem::class)->orderBy('position');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function months(): int
    {
        return self::INTERVALS[$this->interval] ?? 1;
    }

    /** Stichtag Nummer $k (0 = Ankerdatum), immer vom Anker aus gerechnet. */
    public function occurrenceDate(int $k): Carbon
    {
        return Carbon::parse($this->anchor_date)->startOfDay()->addMonthsNoOverflow($this->months() * $k);
    }

    /**
     * Abrechnungszeitraum zum Stichtag Nummer $k.
     * Im Voraus: ab dem Stichtag. Nachträglich: der Zeitraum, der am Vortag des Stichtags endet.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodFor(int $k): array
    {
        if ($this->billing === self::ARREARS) {
            return [$this->occurrenceDate($k - 1), $this->occurrenceDate($k)->subDay()];
        }

        return [$this->occurrenceDate($k), $this->occurrenceDate($k + 1)->subDay()];
    }

    /** Läuft nicht mehr, weil der nächste Stichtag nach dem Enddatum liegt. */
    public function isFinished(): bool
    {
        return $this->end_date !== null && $this->next_date->gt($this->end_date);
    }

    /** active | paused | finished */
    public function state(): string
    {
        if ($this->isFinished()) {
            return 'finished';
        }

        return $this->active ? 'active' : 'paused';
    }

    public function isDue(?Carbon $today = null): bool
    {
        $today ??= now()->startOfDay();

        return $this->active && ! $this->isFinished() && $this->next_date->lte($today);
    }
}
