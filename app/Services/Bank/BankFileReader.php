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

namespace App\Services\Bank;

/** Erkennt das Format einer Bankdatei am Inhalt (nicht am Dateinamen). */
class BankFileReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @return 'camt'|'csv' */
    public static function detect(string $raw): string
    {
        if (CamtParser::looks($raw)) {
            return 'camt';
        }

        return 'csv';
    }

    /**
     * Liest CAMT direkt; CSV braucht eine Spaltenzuordnung (siehe BankCsvParser).
     *
     * @throws BankFileInvalid
     */
    public static function read(string $raw, string $format): ParsedStatement
    {
        return match ($format) {
            'camt' => CamtParser::parse($raw),
            default => throw new BankFileInvalid(__('messages.bank.err_format')),
        };
    }
}
