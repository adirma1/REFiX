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

use App\Models\MailAccount;
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\Mail\MailTemplates;
use App\Services\Mail\QuoteMailer;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Freigegebene Angebote per E-Mail versenden (Dialog mit vorausgefülltem Text, Versand direkt beim Klick). */
class QuoteMailController extends Controller
{
    private const MAX_RECIPIENTS = 5;

    public function form(Quote $quote): View|RedirectResponse
    {
        $this->authorize('send-mail');

        $tenant = Tenant::with('mailAccount')->findOrFail(Tenancy::id());

        if ($problem = $this->problem($quote, $tenant)) {
            return redirect()->route('quotes.show', $quote)->with('warning', $problem);
        }

        $quote->loadMissing('customer');
        [$subject, $body] = MailTemplates::compose($quote, $tenant);

        return view('quotes.mail', [
            'quote' => $quote,
            'account' => $tenant->mailAccount,
            'to' => $quote->customer_snapshot['email'] ?? $quote->customer?->email,
            'subject' => $subject,
            'body' => $body,
        ]);
    }

    public function send(Request $request, Quote $quote, QuoteMailer $mailer): RedirectResponse
    {
        $this->authorize('send-mail');

        $tenant = Tenant::with('mailAccount')->findOrFail(Tenancy::id());

        if ($problem = $this->problem($quote, $tenant)) {
            return redirect()->route('quotes.show', $quote)->with('warning', $problem);
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
            $quote, $tenant, $request->user(),
            MailAccount::splitAddresses($data['to']),
            trim(preg_replace('/\s+/', ' ', $data['subject'])),
            str_replace(["\r\n", "\r"], "\n", $data['body']),
        );

        if (! $log->wasSent()) {
            return redirect()->route('quotes.mail', $quote)->withInput()
                ->with('warning', __('messages.invoices.mail_failed', ['error' => $log->error]));
        }

        return redirect()->route('quotes.show', $quote)
            ->with('status', __('messages.invoices.mail_sent', ['to' => $log->recipients]));
    }

    /** Warum das Angebot gerade nicht versendet werden kann (oder null). */
    private function problem(Quote $quote, Tenant $tenant): ?string
    {
        if ($quote->isDraft()) {
            return __('messages.quotes.mail_only_issued');
        }

        if (! $tenant->mailAccount) {
            return __('messages.invoices.mail_no_account');
        }

        return null;
    }
}
