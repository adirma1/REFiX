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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Auditable;

    protected $fillable = ['name', 'username', 'email', 'password', 'locale', 'per_page', 'active', 'must_change_password'];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /** @var array<int, string>|null  Rolle je Mandant, einmal pro Anfrage geladen */
    protected ?array $roleCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Der Name, mit dem der erste Administrator angelegt wird. */
    public const DEFAULT_ADMIN_USERNAME = 'Admin';

    /**
     * Sucht einen Benutzer anhand von E-Mail-Adresse oder Benutzername (Groß-/Kleinschreibung egal).
     * Ein Benutzername enthält nie ein "@", daher ist die Zuordnung eindeutig.
     */
    public static function findByLogin(?string $login): ?self
    {
        $login = trim((string) $login);

        if ($login === '') {
            return null;
        }

        $column = str_contains($login, '@') ? 'email' : 'username';

        return static::query()->whereRaw("lower({$column}) = ?", [mb_strtolower($login)])->first();
    }

    /** Regeln für einen Benutzernamen: 3-50 Zeichen, Buchstaben/Ziffern/Punkt/Unterstrich/Bindestrich, kein "@", eindeutig. */
    public static function usernameRules(?int $ignoreId = null): array
    {
        return [
            'nullable', 'string', 'min:3', 'max:50', 'regex:/^[\p{L}\p{N}._-]+$/u',
            function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId) {
                $taken = static::query()
                    ->whereRaw('lower(username) = ?', [mb_strtolower((string) $value)])
                    ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                    ->exists();

                if ($taken) {
                    $fail(__('messages.users.username_taken'));
                }
            },
        ];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('role')->withTimestamps();
    }

    public function roleIn(?int $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }

        $this->roleCache ??= DB::table('tenant_user')
            ->where('user_id', $this->id)
            ->pluck('role', 'tenant_id')
            ->all();

        return $this->roleCache[$tenantId] ?? null;
    }

    public function forgetRoleCache(): void
    {
        $this->roleCache = null;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
