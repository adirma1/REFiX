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
use App\Models\Dunning;
use App\Models\MailLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DunningService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** Versendet eine Mahnung samt archiviertem PDF und protokolliert jeden Versuch. */
class DunningMailer
{
    public function __construct(private MailSender $sender, private DunningService $dunnings) {}

    /**
     * @param  array<int, string>  $to
     *
     * @throws RuntimeException wenn die Mahnung nicht versendbar ist oder kein Zugang zugeordnet ist
     */
    public function send(Dunning $dunning, Tenant $tenant, User $user, array $to, string $subject, string $body): MailLog
    {
        if ($dunning->isVoided() || ! $dunning->pdf_path || ! Storage::disk('local')->exists($dunning->pdf_path)) {
            throw new RuntimeException('Diese Mahnung kann nicht versendet werden.');
        }

        $account = $tenant->mailAccount;
        if (! $account) {
            throw new RuntimeException('Dem Mandanten ist kein E-Mail-Zugang zugeordnet.');
        }

        $invoice = $dunning->invoice;
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', ($dunning->level === 1 ? 'Zahlungserinnerung-' : 'Mahnung-').$invoice->number).'.pdf';

        $email = (new Email)
            ->from(new Address($account->from_address, (string) $account->from_name))
            ->to(...array_map(fn ($a) => new Address($a), $to))
            ->subject($subject)
            ->text($body)
            ->attach(Storage::disk('local')->get($dunning->pdf_path), $filename, 'application/pdf');

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
            'invoice_id' => $invoice->id,
            'dunning_id' => $dunning->id,
            'user_id' => $user->id,
            'mail_account_id' => $account->id,
            'account_name' => $account->name,
            'recipients' => implode(', ', $to),
            'bcc' => $account->bcc,
            'subject' => $subject,
            'status' => $error === null ? 'sent' : 'failed',
            'error' => $error,
        ]);

        AuditLog::record($error === null ? 'dunning.mailed' : 'dunning.mail_failed', $dunning, array_filter([
            'to' => $log->recipients, 'subject' => $subject, 'account' => $account->name, 'error' => $error,
        ]));

        if ($error === null) {
            $this->dunnings->markSent($dunning, $user, 'mail');
        }

        return $log;
    }
}
