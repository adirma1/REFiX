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

use Illuminate\Support\Facades\Schedule;

// Zeitgesteuerte Aufgaben. Der Worker-Container startet `schedule:work`.
// Wiederkehrende Rechnungen: täglich früh Entwürfe zu den fälligen Stichtagen anlegen (holt Versäumtes nach).
Schedule::command('recurring:generate')->dailyAt('05:00')->withoutOverlapping();

// Kunden, deren „Kunde bis“-Datum vorbei ist, auf inaktiv setzen (vor den wiederkehrenden Rechnungen).
Schedule::command('customers:deactivate-expired')->dailyAt('04:30')->withoutOverlapping();
