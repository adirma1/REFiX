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

namespace App\Support;

use App\Models\User;

/** Zeilen pro Seite in allen Listen; je Benutzer einstellbar, nur erlaubte Werte. */
class PerPage
{
    public const OPTIONS = [10, 25, 50, 100];

    public const DEFAULT = 25;

    public static function for(?User $user): int
    {
        $value = (int) ($user?->per_page ?? self::DEFAULT);

        return in_array($value, self::OPTIONS, true) ? $value : self::DEFAULT;
    }
}
