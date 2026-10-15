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

use Illuminate\Http\Request;

/** Hilfen für die Filter in den Listen: Werte aus der URL nur annehmen, wenn sie erlaubt sind. */
class ListFilter
{
    /** Liefert den Wert des Query-Parameters, wenn er in $allowed vorkommt, sonst ''. */
    public static function pick(Request $request, string $key, array $allowed): string
    {
        $value = $request->query($key);
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' && in_array($value, array_map('strval', $allowed), true) ? $value : '';
    }

    /** True, wenn mindestens ein Wert gesetzt ist. */
    public static function active(array $values): bool
    {
        return count(array_filter($values, fn ($v) => $v !== '' && $v !== null)) > 0;
    }
}
