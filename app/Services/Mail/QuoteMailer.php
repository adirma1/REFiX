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

use App\Models\AuditLog;
use App\Models\MailLog;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QuotePdf;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** Versendet ein freigegebenes Angebot samt PDF und protokolliert jeden Versuch. */
class QuoteMailer
{
    public function __construct(private MailSender $sender, private QuotePdf $pdf) {}

    /**
     * @param  array<int, string>  $to
     *
     * @throws RuntimeException bei Entwürfen oder wenn kein Zugang zugeordnet ist
     */
    public function send(Quote $quote, Tenant $tenant, User $user, array $to, string $subject, string $body): MailLog
    {
        if ($quote->isDraft()) {
            throw new RuntimeException('Entwürfe können nicht versendet werden.');
        }

        $account = $tenant->mailAccount;
        if (! $account) {
            throw new RuntimeException('Dem Mandanten ist kein E-Mail-Zugang zugeordnet.');
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $quote->number ?: 'Angebot-'.$quote->id).'.pdf';

        $email = (new Email)
            ->from(new Address($account->from_address, (string) $account->from_name))
            ->to(...array_map(fn ($a) => new Address($a), $to))
            ->subject($subject)
            ->text($body)
            ->attach($this->pdf->render($quote), $filename, 'application/pdf');

        if (filled($account->reply_to)) {
            $email->replyTo(new Address($account->reply_to));
        }
        if ($account->bccList() !== []) {
            $email->bcc(...array_map(fn ($a) => new Address($a), $account->bccList()));
        }

        $error = null;
        try {
            $this->sender->send($account, $email);
        } catch (Throwable $e) {
            $error = mb_substr(trim($e->getMessage()) ?: $e::class, 0, 500);
        }

        $log = MailLog::create([
            'tenant_id' => $tenant->id,
            'quote_id' => $quote->id,
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'account_name' => $account->name,
            'recipients' => implode(', ', $to),
            'bcc' => $account->bcc,
            'subject' => $subject,
            'status' => $error === null ? 'sent' : 'failed',
            'error' => $error,
        ]);

        AuditLog::record($error === null ? 'quote.mailed' : 'quote.mail_failed', $quote, array_filter([
            'to' => $log->recipients, 'subject' => $subject, 'account' => $account->name, 'error' => $error,
        ]));

        return $log;
    }
}
