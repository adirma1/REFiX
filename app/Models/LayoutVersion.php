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

/** Eine unveränderliche Version des Beleg-Layouts eines Mandanten. */
class LayoutVersion extends Model
{
    use BelongsToTenant;

    public const KIND_INVOICE = 'invoice';

    public const KIND_DUNNING = 'dunning';

    public const KIND_QUOTE = 'quote';

    public const KINDS = [self::KIND_INVOICE, self::KIND_DUNNING, self::KIND_QUOTE];

    protected $fillable = [
        'kind', 'version', 'name', 'body_html', 'footer_html',
        'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Inhalt und Ränder bleiben stehen; nur der Aktiv-Schalter darf sich ändern.
        static::updating(function (LayoutVersion $layout) {
            $illegal = array_diff(array_keys($layout->getDirty()), ['is_active', 'updated_at']);

            if ($illegal !== []) {
                throw new LogicException('Layout-Versionen sind unveränderlich. Bitte eine neue Version anlegen.');
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Das aktive Layout einer Art (Rechnung, Mahnung oder Angebot) eines Mandanten, unabhängig vom gesetzten Mandantenkontext. */
    public static function activeFor(int $tenantId, string $kind = self::KIND_INVOICE): ?self
    {
        return static::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('kind', $kind)->where('is_active', true)->first();
    }

    /** @return array{top: int, right: int, bottom: int, left: int} */
    public function margins(): array
    {
        return ['top' => $this->margin_top, 'right' => $this->margin_right, 'bottom' => $this->margin_bottom, 'left' => $this->margin_left];
    }
}
