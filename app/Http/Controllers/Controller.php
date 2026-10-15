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

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Leere Textfelder werden als NULL gespeichert. MariaDB lehnt '' in Zahlenspalten ab.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function emptyToNull(array $data): array
    {
        return array_map(
            fn ($value) => is_string($value) && trim($value) === '' ? null : $value,
            $data
        );
    }

    /**
     * Zahl aus einer Formulareingabe: akzeptiert "1.234,56", "12,5" und "12.5"; leer = null.
     */
    protected function decimal(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }

        return $value;
    }
}
