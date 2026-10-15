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

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class VersionTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_version_has_major_minor_patch_format(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', config('app.version'));
    }

    public function test_login_page_shows_the_version(): void
    {
        // Ohne Benutzer leitet die Anwendung zur Einrichtung um.
        $this->makeUser($this->makeTenant());

        $this->get('/login')->assertOk()->assertSee(config('app.version'));
    }

    public function test_sidebar_and_about_page_show_the_version(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin');

        $this->actingAs($user)->get(route('dashboard'))->assertSee(config('app.brand').' '.config('app.version'));
        $this->actingAs($user)->get(route('about'))->assertOk()->assertSee('GPL-2.0-only')->assertSee('martin.adirsch@gmx.de');
    }

    public function test_about_page_requires_login(): void
    {
        $this->get(route('about'))->assertRedirect(route('login'));
    }
}
