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

use App\Models\User;

/**
 * Muss dieser Benutzer die Zwei-Faktor-Anmeldung verwenden? Jeder Benutzer entscheidet das für sich selbst (Mein Profil).
 * Hat er nie entschieden, gilt der Standard: an (Vorgabe aus der Umgebung, REQUIRE_2FA).
 */
class TwoFactorPolicy
{
    public static function requiredFor(?User $user): bool
    {
        $preference = $user?->two_factor_preference;

        return $preference === null ? (bool) config('invoice.require_2fa') : (bool) $preference;
    }
}
