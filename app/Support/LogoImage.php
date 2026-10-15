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
 * Bereitet das Logo für das PDF auf. PDF/A (E-Rechnung) erlaubt keine Transparenz; ein PNG mit
 * Alphakanal würde sonst an den transparenten Stellen schwarz erscheinen. Darum wird es auf Weiß gerechnet.
 */
class LogoImage
{
    public static function dataUri(string $bytes, string $mime): string
    {
        if ($mime === 'image/png' && str_starts_with($bytes, "\x89PNG") && function_exists('imagecreatefromstring')) {
            $flat = self::flattenPng($bytes);

            if ($flat !== null) {
                return 'data:image/png;base64,'.base64_encode($flat);
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private static function flattenPng(string $bytes): ?string
    {
        // GD meldet defekte Bilder per Warnung; hier soll nur auf das Original zurückgefallen werden.
        set_error_handler(fn () => true);
        try {
            $source = imagecreatefromstring($bytes);
        } finally {
            restore_error_handler();
        }

        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);

        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagepng($canvas, null, 6);
        $png = ob_get_clean();

        return $png === false || $png === '' ? null : $png;
    }
}
