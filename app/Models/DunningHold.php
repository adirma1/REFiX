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

/** Mahnsperre: eine Rechnung wird (z. B. wegen Reklamation) bis auf Weiteres nicht gemahnt. */
class DunningHold extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hold_until' => 'date', 'released_at' => 'datetime'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Aktiv: nicht freigegeben und (falls befristet) nicht abgelaufen. */
    public function scopeActive($query)
    {
        return $query->whereNull('released_at')
            ->where(fn ($q) => $q->whereNull('hold_until')->orWhere('hold_until', '>=', today()->toDateString()));
    }
}
