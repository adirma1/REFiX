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
use Illuminate\Support\Str;

class UserResetPassword extends Command
{
    protected $signature = 'user:reset-password {email : E-Mail-Adresse oder Benutzername des Benutzers}
                            {--random : Zufallspasswort erzeugen und einmalig anzeigen}';

    protected $description = 'Setzt das Passwort eines Benutzers zurück (nur auf dem Server möglich)';

    public function handle(): int
    {
        $user = User::findByLogin($this->argument('email'));

        if (! $user) {
            $this->error('Kein Benutzer mit dieser E-Mail-Adresse bzw. diesem Benutzernamen gefunden.');

            return self::FAILURE;
        }

        if ($this->option('random')) {
            $password = Str::password(20, symbols: false);
            $shown = true;
        } else {
            $password = (string) $this->secret('Neues Passwort (mindestens 12 Zeichen)');
            $confirm = (string) $this->secret('Passwort wiederholen');

            if ($password !== $confirm) {
                $this->error('Die Passwörter stimmen nicht überein.');

                return self::FAILURE;
            }

            if (mb_strlen($password) < 12) {
                $this->error('Das Passwort muss mindestens 12 Zeichen lang sein.');

                return self::FAILURE;
            }

            $shown = false;
        }

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'active' => true,
            'remember_token' => Str::random(60),
        ])->save();

        // Alle offenen Sitzungen beenden und Login-Sperren aufheben.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        AuditLog::record('user.password_reset_cli', $user);

        $this->info("Passwort für {$user->email} wurde zurückgesetzt. Es muss beim nächsten Login geändert werden.");

        if ($shown) {
            $this->line("Neues Passwort (wird nur jetzt angezeigt): {$password}");
        }

        return self::SUCCESS;
    }
}
