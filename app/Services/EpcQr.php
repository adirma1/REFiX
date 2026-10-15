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

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** EPC-QR-Code ("GiroCode") für SEPA-Überweisungen. */
class EpcQr
{
    public static function payload(string $name, string $iban, ?string $bic, float $amount, string $remittance): string
    {
        $lines = [
            'BCD',
            '002',                                         // Version
            '1',                                           // Zeichensatz UTF-8
            'SCT',                                         // SEPA Credit Transfer
            strtoupper(str_replace(' ', '', (string) $bic)),
            mb_substr($name, 0, 70),
            strtoupper(str_replace(' ', '', $iban)),
            'EUR'.number_format($amount, 2, '.', ''),
            '',                                            // Zweck
            '',                                            // strukturierte Referenz
            mb_substr($remittance, 0, 140),                // Verwendungszweck
        ];

        return implode("\n", $lines);
    }

    /** Als SVG-Data-URI, direkt in <img src> verwendbar (auch von mPDF). */
    public static function svgDataUri(string $payload): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd));
        $svg = $writer->writeString($payload, 'UTF-8', ErrorCorrectionLevel::M());

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
