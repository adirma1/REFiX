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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** SMTP-Zugang für den Belegversand. Das Passwort wird verschlüsselt gespeichert und nie ausgegeben. */
class MailAccount extends Model
{
    use Auditable;

    public const ENCRYPTIONS = ['starttls', 'tls', 'none'];

    protected $fillable = [
        'name', 'host', 'port', 'encryption', 'username', 'smtp_password',
        'from_address', 'from_name', 'reply_to', 'bcc',
    ];

    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'smtp_password' => 'encrypted',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function hasPassword(): bool
    {
        return $this->getRawOriginal('smtp_password') !== null && $this->getRawOriginal('smtp_password') !== '';
    }

    /** @return array<int, string> */
    public function bccList(): array
    {
        return self::splitAddresses($this->bcc);
    }

    /** @return array<int, string> */
    public static function splitAddresses(?string $list): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $list) ?: []), fn ($a) => $a !== ''));
    }
}
