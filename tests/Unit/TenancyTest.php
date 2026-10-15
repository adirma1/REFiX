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

namespace Tests\Unit;

use App\Support\Tenancy;
use PHPUnit\Framework\TestCase;

class TenancyTest extends TestCase
{
    protected function tearDown(): void
    {
        Tenancy::clear();
    }

    public function test_tenant_can_be_set_and_cleared(): void
    {
        $this->assertNull(Tenancy::id());

        Tenancy::set(7);
        $this->assertSame(7, Tenancy::id());

        Tenancy::clear();
        $this->assertNull(Tenancy::id());
    }
}
