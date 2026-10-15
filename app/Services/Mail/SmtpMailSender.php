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
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

/** Echter Versand per SMTP mit den Daten des Zugangs (Benutzer/Passwort, kein OAuth). */
class SmtpMailSender implements MailSender
{
    private const TIMEOUT_SECONDS = 15.0;

    public function send(MailAccount $account, Email $email): void
    {
        $transport = $this->transport($account);

        try {
            $transport->send($email);
        } finally {
            $transport->stop();
        }
    }

    public function check(MailAccount $account): void
    {
        $transport = $this->transport($account);

        try {
            $transport->start();
        } finally {
            $transport->stop();
        }
    }

    private function transport(MailAccount $account): EsmtpTransport
    {
        // tls = verschlüsselt ab Verbindungsaufbau (meist Port 465); starttls = Umschalten nach dem Aufbau (meist 587).
        $transport = new EsmtpTransport($account->host, (int) $account->port, $account->encryption === 'tls');

        // „Keine“: auch kein automatisches STARTTLS, wenn der Server es anbietet, wäre unerwartet – hier bewusst aus.
        $transport->setAutoTls($account->encryption === 'starttls');

        if (filled($account->username)) {
            $transport->setUsername((string) $account->username);
            $transport->setPassword((string) $account->smtp_password);
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(self::TIMEOUT_SECONDS);
        }

        return $transport;
    }
}
