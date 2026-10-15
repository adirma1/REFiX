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

namespace Tests;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sicherheitssperre: Tests laufen nur gegen SQLite im Arbeitsspeicher,
     * niemals gegen die echte MariaDB.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException('Abbruch: Tests dürfen nur mit SQLite im Arbeitsspeicher laufen.');
        }
    }

    protected function tearDown(): void
    {
        Tenancy::clear();

        parent::tearDown();
    }
}
