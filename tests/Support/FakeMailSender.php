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

namespace Tests\Support;

use App\Models\MailAccount;
use App\Services\Mail\MailSender;
use Symfony\Component\Mime\Email;
use Throwable;

/** Mailserver-Attrappe: merkt sich alles, was gesendet würde, oder wirft einen vorgegebenen Fehler. */
class FakeMailSender implements MailSender
{
    /** @var array<int, array{account: MailAccount, email: Email}> */
    public array $sent = [];

    public int $checks = 0;

    public ?Throwable $fail = null;

    public function send(MailAccount $account, Email $email): void
    {
        if ($this->fail) {
            throw $this->fail;
        }

        $this->sent[] = ['account' => $account, 'email' => $email];
    }

    public function check(MailAccount $account): void
    {
        $this->checks++;

        if ($this->fail) {
            throw $this->fail;
        }
    }
}
