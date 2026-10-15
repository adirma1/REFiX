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

namespace App\Providers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\MailSender;
use App\Services\Mail\SmtpMailSender;
use App\Support\Tenancy;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MailSender::class, SmtpMailSender::class);
    }

    public function boot(): void
    {
        // Einheitliche, schlichte Seitenschaltung (Zurück / Seite x von y / Weiter).
        Paginator::defaultView('pagination.nav');

        // Rollen gelten immer für den aktuell gewählten Mandanten.
        Gate::define('manage-users', fn (User $user) => $user->roleIn(Tenancy::id()) === 'admin');

        Gate::define('edit-master-data', fn (User $user) => in_array(
            $user->roleIn(Tenancy::id()), ['admin', 'accounting', 'clerk'], true
        ));

        Gate::define('delete-master-data', fn (User $user) => in_array(
            $user->roleIn(Tenancy::id()), ['admin', 'accounting'], true
        ));

        // Belege: Entwürfe schreiben darf, wer Stammdaten pflegt; freigeben (Nummer vergeben) nur Buchhaltung/Admin.
        Gate::define('edit-invoices', fn (User $user) => in_array(
            $user->roleIn(Tenancy::id()), ['admin', 'accounting', 'clerk'], true
        ));

        Gate::define('issue-invoices', fn (User $user) => in_array(
            $user->roleIn(Tenancy::id()), ['admin', 'accounting'], true
        ));

        // Zahlungen buchen und Bankdateien einlesen: Buchhaltung und Admin.
        Gate::define('manage-payments', fn (User $user) => in_array(
            $user->roleIn(Tenancy::id()), ['admin', 'accounting'], true
        ));

        // E-Mail-Versand: Admin immer; andere Rollen nur, wenn der Mandant sie in der Rechte-Matrix freigibt.
        Gate::define('send-mail', function (User $user) {
            $role = $user->roleIn(Tenancy::id());

            if ($role === null) {
                return false;
            }

            return $role === 'admin'
                || in_array($role, Tenant::find(Tenancy::id())?->mailRoles() ?? [], true);
        });
    }
}
