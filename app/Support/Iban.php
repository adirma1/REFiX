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

/** IBAN-Prüfung: Länge je Land und Prüfziffer (Modulo 97, ISO 13616). */
class Iban
{
    /** Länge der IBAN je Land (IBAN-Register). */
    private const LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20, 'BE' => 16, 'BG' => 22, 'BH' => 22,
        'BR' => 29, 'BY' => 28, 'CH' => 21, 'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27, 'GB' => 22, 'GE' => 22, 'GI' => 23,
        'GL' => 18, 'GR' => 27, 'GT' => 28, 'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32, 'LI' => 21, 'LT' => 20, 'LU' => 20,
        'LV' => 21, 'MC' => 27, 'MD' => 24, 'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18,
        'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29, 'RO' => 24, 'RS' => 22, 'SA' => 24,
        'SC' => 31, 'SE' => 24, 'SI' => 19, 'SK' => 24, 'SM' => 27, 'ST' => 25, 'SV' => 28, 'TL' => 23, 'TN' => 24,
        'TR' => 26, 'UA' => 29, 'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    /** Entfernt Leerzeichen und macht Großbuchstaben. */
    public static function normalize(?string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', (string) $iban));
    }

    /** Gibt true zurück, wenn Länge und Prüfziffer stimmen. */
    public static function isValid(?string $iban): bool
    {
        return self::problem($iban) === null;
    }

    /**
     * Grund, warum die IBAN ungültig ist: 'format', 'length' oder 'checksum'; null bei gültiger IBAN.
     */
    public static function problem(?string $iban): ?string
    {
        $iban = self::normalize($iban);

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return 'format';
        }

        $expected = self::LENGTHS[substr($iban, 0, 2)] ?? null;

        if ($expected !== null && strlen($iban) !== $expected) {
            return 'length';
        }

        // Prüfziffer: die ersten vier Zeichen ans Ende, Buchstaben zu Zahlen (A=10 … Z=35), Rest durch 97 muss 1 sein.
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_digit($char) ? $char : (string) (ord($char) - 55);
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1 ? null : 'checksum';
    }
}
