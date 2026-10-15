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

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\MailAccount;
use App\Models\Tenant;
use App\Services\Mail\InvoiceMailer;
use App\Services\Mail\MailTemplates;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Freigegebene Belege per E-Mail versenden: Dialog mit vorausgefülltem Text, dann Versand direkt beim Klick. */
class InvoiceMailController extends Controller
{
    private const MAX_RECIPIENTS = 5;

    public function form(Invoice $invoice): View|RedirectResponse
    {
        $this->authorize('send-mail');

        $tenant = Tenant::with('mailAccount')->findOrFail(Tenancy::id());

        if ($problem = $this->problem($invoice, $tenant)) {
            return redirect()->route('invoices.show', $invoice)->with('warning', $problem);
        }

        $invoice->loadMissing('customer');
        [$subject, $body] = MailTemplates::compose($invoice, $tenant);

        return view('invoices.mail', [
            'invoice' => $invoice,
            'account' => $tenant->mailAccount,
            'to' => $invoice->customer_snapshot['email'] ?? $invoice->customer?->email,
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    public function send(Request $request, Invoice $invoice, InvoiceMailer $mailer): RedirectResponse
    {
        $this->authorize('send-mail');

        $tenant = Tenant::with('mailAccount')->findOrFail(Tenancy::id());

        if ($problem = $this->problem($invoice, $tenant)) {
            return redirect()->route('invoices.show', $invoice)->with('warning', $problem);
        }

        $data = $request->validate([
            'to' => ['required', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                $list = MailAccount::splitAddresses($value);

                if ($list === [] || count($list) > self::MAX_RECIPIENTS) {
                    $fail(__('messages.invoices.mail_to_count', ['max' => self::MAX_RECIPIENTS]));
                }

                foreach ($list as $address) {
                    if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                        $fail(__('messages.mail_accounts.bcc_invalid', ['address' => $address]));
                    }
                }
            }],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $log = $mailer->send(
            $invoice, $tenant, $request->user(),
            MailAccount::splitAddresses($data['to']),
            trim(preg_replace('/\s+/', ' ', $data['subject'])),
            str_replace(["\r\n", "\r"], "\n", $data['body']),
        );

        if (! $log->wasSent()) {
            return redirect()->route('invoices.mail', $invoice)->withInput()
                ->with('warning', __('messages.invoices.mail_failed', ['error' => $log->error]));
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', __('messages.invoices.mail_sent', ['to' => $log->recipients]));
    }

    /** Warum der Beleg gerade nicht versendet werden kann (oder null). */
    private function problem(Invoice $invoice, Tenant $tenant): ?string
    {
        if ($invoice->isDraft()) {
            return __('messages.invoices.mail_only_issued');
        }

        if (! $tenant->mailAccount) {
            return __('messages.invoices.mail_no_account');
        }

        return null;
    }
}
