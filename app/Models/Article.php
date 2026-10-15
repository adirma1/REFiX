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
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    protected $fillable = ['article_number', 'name', 'description', 'unit', 'tax_rate', 'active'];

    protected function casts(): array
    {
        return [
            'tax_rate' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ArticlePrice::class);
    }

    /**
     * Gültiger Preis zum Stichtag: ein kundenspezifischer Preis hat Vorrang,
     * sonst gilt der allgemeine Preis mit dem jüngsten Startdatum bis zum Stichtag.
     */
    public function priceFor(CarbonInterface|string|null $date = null, string $currency = 'EUR', ?int $customerId = null): ?ArticlePrice
    {
        $date = $date ? \Illuminate\Support\Carbon::parse($date)->toDateString() : now()->toDateString();

        $base = $this->prices()
            ->where('currency', $currency)
            ->whereDate('valid_from', '<=', $date);

        if ($customerId !== null) {
            $special = (clone $base)->where('customer_id', $customerId)
                ->orderByDesc('valid_from')->orderByDesc('id')->first();

            if ($special) {
                return $special;
            }
        }

        return $base->whereNull('customer_id')
            ->orderByDesc('valid_from')->orderByDesc('id')->first();
    }
}
