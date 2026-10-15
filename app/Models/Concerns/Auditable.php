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

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            AuditLog::record(static::auditName($model).'.created', $model);
        });

        static::updated(function ($model) {
            // Geheimnisse gehören nie ins Protokoll.
            $changes = Arr::except($model->getChanges(), [
                'updated_at', 'password', 'remember_token',
                'smtp_password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_ts',
            ]);

            if ($changes !== []) {
                AuditLog::record(static::auditName($model).'.updated', $model, ['changes' => $changes]);
            }
        });

        static::deleted(function ($model) {
            AuditLog::record(static::auditName($model).'.deleted', $model);
        });
    }

    protected static function auditName($model): string
    {
        return strtolower(class_basename($model));
    }
}
