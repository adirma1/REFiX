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

namespace App\Services\Mail;

use App\Models\MailAccount;
use Symfony\Component\Mime\Email;

/** Schnittstelle zum Mailserver. In Tests wird sie durch eine Attrappe ersetzt. */
interface MailSender
{
    /** Sendet die Nachricht über den Zugang. Wirft bei jedem Fehler eine Exception mit lesbarer Meldung. */
    public function send(MailAccount $account, Email $email): void;

    /** Prüft nur Verbindung und Anmeldung (ohne etwas zu senden). Wirft bei Fehlern. */
    public function check(MailAccount $account): void;
}
