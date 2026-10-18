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

return [
    'name' => env('APP_NAME', 'RE-FiX'),

    // Anzeigename in Menü und Anmeldeseite (unabhängig von APP_NAME in der .env)
    'brand' => 'RE-FiX',

    // Programmversion (Haupt.Neben.Korrektur) – wird in Seitenleiste, Anmeldeseite und Info-Seite angezeigt.
    'version' => '1.5.0',
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Berlin'),
    'locale' => env('APP_LOCALE', 'de'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'de'),
    'faker_locale' => 'de_DE',
    'cipher' => 'AES-256-CBC',
    // Ohne APP_KEY in der Umgebung gilt der beim ersten Start erzeugte Schlüssel aus dem Storage.
    'key' => env('APP_KEY') ?: (is_file($keyFile = dirname(__DIR__).'/storage/app/.app_key') ? trim((string) file_get_contents($keyFile)) : null),
    'previous_keys' => [
        ...array_filter(explode(',', (string) env('APP_PREVIOUS_KEYS', ''))),
    ],
    'maintenance' => [
        'driver' => 'file',
    ],
];
