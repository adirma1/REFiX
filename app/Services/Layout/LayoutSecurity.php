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

namespace App\Services\Layout;

/**
 * Prüft das von einer externen KI gelieferte HTML/CSS, bevor es in ein PDF gerendert wird.
 * Erlaubt sind nur Gestaltung (HTML, CSS, eingebettete Bilder). Alles, was etwas nachlädt,
 * ausführt oder Dateien lesen könnte, wird abgelehnt.
 */
class LayoutSecurity
{
    public const MAX_BYTES = 300_000;

    private const FORBIDDEN_TAGS = [
        'script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'meta', 'base',
        'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'audio', 'video', 'source',
    ];

    /** @return array<int, string> Fehlermeldungen (leer = unbedenklich) */
    public static function check(string $html, string $label): array
    {
        $errors = [];

        if (strlen($html) > self::MAX_BYTES) {
            return ["{$label}: Der Text ist zu groß (höchstens ".(self::MAX_BYTES / 1000).' KB).'];
        }

        foreach (self::FORBIDDEN_TAGS as $tag) {
            if (preg_match('/<\s*'.$tag.'\b/i', $html)) {
                $errors[] = "{$label}: Das Element <{$tag}> ist nicht erlaubt.";
            }
        }

        if (preg_match('/\son[a-z]+\s*=/i', $html)) {
            $errors[] = "{$label}: Ereignis-Attribute (onclick …) sind nicht erlaubt.";
        }

        if (preg_match('/\b(javascript|vbscript|file|phar|php)\s*:|data:(text|application)/i', $html)) {
            $errors[] = "{$label}: Adressen mit javascript:, file:, php: oder ähnlichen Schemata sind nicht erlaubt.";
        }

        if (preg_match('/@import|expression\s*\(|behavior\s*:|-moz-binding|@font-face/i', $html)) {
            $errors[] = "{$label}: @import, @font-face und ähnliche CSS-Funktionen sind nicht erlaubt (nur die Schrift DejaVu Sans verwenden).";
        }

        // Bild- und Linkadressen
        if (preg_match_all('/\b(src|background|poster|xlink:href|action|formaction)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = trim($match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : ($match[4] ?? '')));

                if (! self::isAllowedImageSource($value)) {
                    $errors[] = "{$label}: {$match[1]}=\"".mb_substr($value, 0, 50).'" ist nicht erlaubt. Bilder nur über Platzhalter ({{tenant.logo}}, {{payment.qr}}) oder eingebettete data:image-Daten.';
                }
            }
        }

        if (preg_match_all('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = trim($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? '')));

                if (! preg_match('/^(https?:\/\/|mailto:|tel:|#|\{\{[^}]+\}\}$)/i', $value)) {
                    $errors[] = "{$label}: href=\"".mb_substr($value, 0, 50).'" ist nicht erlaubt.';
                }
            }
        }

        // CSS url(...)
        if (preg_match_all('/url\s*\(\s*([^)]*)\)/i', $html, $matches)) {
            foreach ($matches[1] as $value) {
                $value = trim($value, " \t\n\r\"'");

                if (! self::isAllowedImageSource($value) && ! str_starts_with($value, '#')) {
                    $errors[] = "{$label}: url(".mb_substr($value, 0, 40).') ist nicht erlaubt.';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private static function isAllowedImageSource(string $value): bool
    {
        return (bool) preg_match('/^\{\{\s*[a-z_][a-z0-9_.]*\s*\}\}$/i', $value)
            || (bool) preg_match('/^data:image\/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+\/=\s]+$/i', $value);
    }
}
