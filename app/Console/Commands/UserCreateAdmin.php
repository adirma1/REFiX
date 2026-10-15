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
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class UserCreateAdmin extends Command
{
    protected $signature = 'user:create-admin
                            {--email= : E-Mail-Adresse}
                            {--name= : Anzeigename}
                            {--username= : Benutzername (Standard: Admin, sofern frei)}
                            {--password= : Passwort (sonst wird danach gefragt)}
                            {--company= : Name der Firma, falls noch kein Mandant existiert}
                            {--random : Zufallspasswort erzeugen und einmalig anzeigen}
                            {--from-env : ADMIN_EMAIL, ADMIN_NAME, ADMIN_PASSWORD, ADMIN_COMPANY aus der Umgebung lesen}
                            {--if-none : Nur anlegen, wenn noch kein Benutzer existiert}';

    protected $description = 'Legt einen Admin an (z. B. wenn alle Admins gesperrt oder gelöscht sind)';

    public function handle(): int
    {
        if ($this->option('if-none') && User::query()->exists()) {
            return self::SUCCESS;
        }

        $email = $this->option('email');
        $name = $this->option('name');
        $password = $this->option('password');
        $company = $this->option('company');

        if ($this->option('from-env')) {
            $email = $email ?: config('invoice.admin.email');
            $name = $name ?: config('invoice.admin.name');
            $password = $password ?: config('invoice.admin.password');
            $company = $company ?: config('invoice.admin.company');

            if (! $email) {
                return self::SUCCESS; // Nichts vorgegeben: die Einrichtungsseite übernimmt.
            }
        }

        $email = $email ?: $this->ask('E-Mail-Adresse');
        $name = $name ?: $this->ask('Name', 'Administrator');
        $shown = false;

        if ($this->option('random') || ($this->option('from-env') && ! $password)) {
            $password = Str::password(20, symbols: false);
            $shown = true;
        } elseif (! $password) {
            $password = (string) $this->secret('Passwort (mindestens 12 Zeichen)');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Ungültige E-Mail-Adresse.');

            return self::FAILURE;
        }

        if (mb_strlen((string) $password) < 12) {
            $this->error('Das Passwort muss mindestens 12 Zeichen lang sein.');

            return self::FAILURE;
        }

        $username = $this->option('username');

        if ($username === null) {
            // Standardname für den ersten Admin; ist er vergeben, bleibt das Feld leer (der Admin kann ihn später vergeben).
            $username = User::findByLogin(User::DEFAULT_ADMIN_USERNAME) ? null : User::DEFAULT_ADMIN_USERNAME;
        } elseif (validator(['username' => $username], ['username' => User::usernameRules()])->fails()) {
            $this->error('Ungültiger oder bereits vergebener Benutzername (3-50 Zeichen: Buchstaben, Ziffern, . _ -).');

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error('Ein Benutzer mit dieser E-Mail-Adresse existiert bereits. Nutze user:reset-password.');

            return self::FAILURE;
        }

        if (Tenant::query()->doesntExist()) {
            Tenant::create(['name' => $company ?: 'Meine Firma']);
        }

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'must_change_password' => true,
        ]);

        // Admin in allen vorhandenen Mandanten.
        foreach (Tenant::all() as $tenant) {
            $tenant->users()->attach($user->id, ['role' => 'admin']);
        }

        AuditLog::record('user.admin_created_cli', $user);

        $this->info("Admin {$email}".($username ? " (Benutzername {$username})" : '')." wurde angelegt. Das Passwort muss beim ersten Login geändert werden.");

        if ($shown) {
            $this->line("Passwort (wird nur jetzt angezeigt): {$password}");
        }

        return self::SUCCESS;
    }
}
