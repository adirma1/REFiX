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

class BankTransaction extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const NEW = 'new';

    public const BOOKED = 'booked';

    public const IGNORED = 'ignored';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'value_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankImport::class, 'bank_import_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function cents(): int
    {
        return (int) round(((float) $this->amount) * 100);
    }
}
