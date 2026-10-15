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

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'invoice_id', 'position', 'article_id', 'description', 'quantity', 'unit',
        'unit_price', 'discount_percent', 'tax_rate', 'net_amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'net_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (InvoiceItem $item) {
            $status = Invoice::withoutGlobalScopes()->whereKey($item->invoice_id)->value('status');

            if ($status !== null && $status !== Invoice::STATUS_DRAFT) {
                throw new LogicException('Positionen freigegebener Belege können nicht geändert werden.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
