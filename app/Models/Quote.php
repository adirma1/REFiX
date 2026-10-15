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
use LogicException;

/**
 * Angebot. Entwurf → offen (mit Nummer) → angenommen / abgelehnt. „Abgelaufen“ ergibt sich aus dem Datum.
 * Angebote sind keine Rechnungsbelege: sie zählen weder bei Zahlungen noch im Dashboard.
 */
class Quote extends Model
{
    use Auditable, BelongsToTenant;

    /** Belegart für Nummernkreis und Layout. */
    public const TYPE = 'quote';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_ACCEPTED, self::STATUS_DECLINED];

    protected $fillable = [
        'status', 'is_test', 'number', 'customer_id', 'customer_snapshot',
        'issue_date', 'valid_until', 'currency', 'tax_mode', 'discount_percent',
        'buyer_reference', 'intro_text', 'footer_text', 'internal_notes',
        'net_total', 'discount_total', 'tax_total', 'gross_total', 'tax_breakdown',
        'issued_at', 'issued_by', 'status_changed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'customer_snapshot' => 'array',
            'tax_breakdown' => 'array',
            'issue_date' => 'date',
            'valid_until' => 'date',
            'issued_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'discount_percent' => 'decimal:2',
            'net_total' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (Quote $quote) {
            if ($quote->status !== self::STATUS_DRAFT) {
                throw new LogicException('Nur Angebots-Entwürfe können gelöscht werden.');
            }
        });

        static::deleted(function (Quote $quote) {
            QuoteItem::withoutGlobalScopes()->where('quote_id', $quote->id)->delete();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** Rechnungen (Entwürfe und freigegebene), die aus diesem Angebot entstanden sind. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('id');
    }

    public function mailLogs(): HasMany
    {
        return $this->hasMany(MailLog::class)->latest('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isDeclined(): bool
    {
        return $this->status === self::STATUS_DECLINED;
    }

    /** Offen, aber das Gültig-bis-Datum ist überschritten. */
    public function isExpired(): bool
    {
        return $this->isOpen() && $this->valid_until !== null && $this->valid_until->endOfDay()->isPast();
    }

    /** Angezeigter Status: draft, open, expired, accepted oder declined. */
    public function displayStatus(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    /** Inhalt ändern ist nur im Entwurf und solange das Angebot offen ist möglich. */
    public function isEditable(): bool
    {
        return $this->isDraft() || $this->isOpen();
    }

    /** In Rechnungen umwandeln darf man offene und angenommene Angebote. */
    public function isConvertible(): bool
    {
        return $this->isOpen() || $this->isAccepted();
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('messages.invoices.no_number');
    }
}
