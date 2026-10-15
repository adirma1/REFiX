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

/*
 * Test-Bootstrap: setzt die Testumgebung hart, BEVOR Laravel startet.
 * Im Docker-Container sind DB_* etc. bereits als echte Umgebungsvariablen gesetzt;
 * diese dürfen die Tests niemals erreichen (sonst würde die Produktivdatenbank getroffen).
 */

$forced = [
    'APP_ENV' => 'testing',
    'APP_KEY' => 'base64:a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=',
    'APP_URL' => 'http://localhost',
    'APP_LOCALE' => 'de',
    'BCRYPT_ROUNDS' => '4',
    'CACHE_STORE' => 'array',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'DATABASE_URL' => '',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'LOG_STACK' => 'daily',
    'REQUIRE_2FA' => 'false',
    'ADMIN_EMAIL' => '',
];

foreach ($forced as $name => $value) {
    putenv("$name=$value");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
