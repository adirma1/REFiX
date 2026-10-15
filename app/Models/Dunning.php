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

/** Zahlungserinnerung oder Mahnung zu einer Rechnung. Das PDF wird beim Erstellen archiviert und nie verändert. */
class Dunning extends Model
{
    use Auditable, BelongsToTenant;

    public const MAX_LEVEL = 3;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dunned_on' => 'date',
            'pay_by' => 'date',
            'open_amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'interest' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'total' => 'decimal:2',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /** Erstellt, aber noch nicht versendet oder als versendet markiert. */
    public function isPending(): bool
    {
        return ! $this->isVoided() && ! $this->isSent();
    }

    public function scopeActive($query)
    {
        return $query->whereNull('voided_at');
    }

    public function label(): string
    {
        return __('messages.dunning.level_'.$this->level);
    }
}
