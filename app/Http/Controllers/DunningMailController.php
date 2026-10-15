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

use App\Models\Dunning;
use App\Models\MailAccount;
use App\Models\Tenant;
use App\Services\DunningService;
use App\Services\Mail\DunningMailer;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Mahnung per E-Mail versenden: Dialog mit vorausgefülltem Text, Versand direkt beim Klick. */
class DunningMailController extends Controller
{
    private const MAX_RECIPIENTS = 5;

    public function __construct(private DunningService $dunnings) {}

    public function form(Dunning $dunning): View|RedirectResponse
    {
        $this->authorizeBoth();

        $tenant = Tenant::with('mailAccount')->findOrFail(Tenancy::id());

        if ($problem = $this->problem($dunning, $tenant)) {
            return redirect()->route('dunning.index', ['tab' => 'history'])->with('warning', $problem);
        }

        $dunning->loadMissing('invoice.customer');
        $invoice = $dunning->invoice;

        return view('dunning.mail', [
            'dunning' => $dunning,
            'account' => $tenant->mailAccount,
            'to' => $invoice->customer_snapshot['email'] ?? $invoice->customer?->email,
            'subject' => $this->dunnings->mailSubject($dunning, $tenant),
            'body' => $this->dunnings->mailBody($dunning, $tenant),
        ]);
    }

    public function send(Request $request, Dunning $dunning, DunningMailer $mailer): RedirectResponse
    {
        $this->authorizeBoth();

        $tenant = Tenant::with('mailAccount')->findOrFail(Tenancy::id());

        if ($problem = $this->problem($dunning, $tenant)) {
            return redirect()->route('dunning.index', ['tab' => 'history'])->with('warning', $problem);
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

        $dunning->loadMissing('invoice');

        $log = $mailer->send(
            $dunning, $tenant, $request->user(),
            MailAccount::splitAddresses($data['to']),
            trim(preg_replace('/\s+/', ' ', $data['subject'])),
            str_replace(["\r\n", "\r"], "\n", $data['body']),
        );

        if (! $log->wasSent()) {
            return redirect()->route('dunning.mail', $dunning)->withInput()
                ->with('warning', __('messages.invoices.mail_failed', ['error' => $log->error]));
        }

        return redirect()->route('dunning.index', ['tab' => 'history'])
            ->with('status', __('messages.invoices.mail_sent', ['to' => $log->recipients]));
    }

    private function authorizeBoth(): void
    {
        $this->authorize('manage-payments');
        $this->authorize('send-mail');
    }

    private function problem(Dunning $dunning, Tenant $tenant): ?string
    {
        if ($dunning->isVoided()) {
            return __('messages.dunning.err_voided');
        }

        if (! $tenant->mailAccount) {
            return __('messages.invoices.mail_no_account');
        }

        return null;
    }
}
