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

/**
 * Hält den aktiven Mandanten der aktuellen Anfrage.
 * Ohne aktiven Mandanten liefern alle mandantenbezogenen Abfragen nichts zurück (fail closed).
 */
class Tenancy
{
    protected static ?int $id = null;

    public static function set(?int $id): void
    {
        static::$id = $id;
    }

    public static function id(): ?int
    {
        return static::$id;
    }

    public static function clear(): void
    {
        static::$id = null;
    }
}
