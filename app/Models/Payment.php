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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zahlung oder Skontoabzug zu einer Rechnung. Gebuchte Zahlungen werden nie gelöscht, sondern storniert.
 */
class Payment extends Model
{
    use Auditable, BelongsToTenant;

    public const KIND_PAYMENT = 'payment';

    public const KIND_DISCOUNT = 'discount';

    protected $fillable = [
        'invoice_id', 'kind', 'amount', 'paid_on', 'reference', 'note', 'bank_transaction_id', 'created_by',
        'reversed_at', 'reversed_by', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function isDiscount(): bool
    {
        return $this->kind === self::KIND_DISCOUNT;
    }
}
