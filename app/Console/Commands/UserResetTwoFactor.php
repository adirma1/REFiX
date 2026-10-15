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

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserResetTwoFactor extends Command
{
    protected $signature = 'user:reset-2fa {email : E-Mail-Adresse oder Benutzername des Benutzers}';

    protected $description = 'Schaltet die Zwei-Faktor-Anmeldung eines Benutzers ab, damit sie neu eingerichtet werden kann';

    public function handle(): int
    {
        $user = User::findByLogin($this->argument('email'));

        if (! $user) {
            $this->error('Kein Benutzer mit dieser E-Mail-Adresse bzw. diesem Benutzernamen gefunden.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_ts' => null,
        ])->save();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        AuditLog::record('user.two_factor_reset_cli', $user);

        $this->info("Zwei-Faktor-Anmeldung für {$user->email} wurde zurückgesetzt. Beim nächsten Login wird die Einrichtung verlangt.");

        return self::SUCCESS;
    }
}
