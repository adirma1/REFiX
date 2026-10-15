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
    // Zwei-Faktor-Anmeldung für alle Nutzer verpflichtend (empfohlen, bei externem Zugriff Pflicht).
    'require_2fa' => (bool) env('REQUIRE_2FA', true),

    // Optionaler Admin aus der .env (nur wenn noch kein Benutzer existiert). Alternative: Einrichtungsseite.
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'name' => env('ADMIN_NAME'),
        'password' => env('ADMIN_PASSWORD'),
        'company' => env('ADMIN_COMPANY'),
    ],

    // Rollen pro Mandant.
    'roles' => ['admin', 'accounting', 'clerk', 'viewer'],

    // Auswahllisten
    'units' => ['Stück', 'Stunde', 'Tag', 'Monat', 'Jahr', 'Pauschale', 'km', 'Lizenz'],
    'tax_rates' => [19, 7, 0],
    'currencies' => ['EUR', 'USD', 'CHF', 'GBP'],
    'locales' => ['de', 'en'],

    // Steuerfall der Rechnung (bei Nicht-Standard fällt keine Umsatzsteuer an).
    'tax_modes' => ['standard', 'reverse_charge', 'intra_community', 'export'],
];
