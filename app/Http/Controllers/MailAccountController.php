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

use App\Models\AuditLog;
use App\Models\MailAccount;
use App\Services\Mail\MailSender;
use App\Support\ListSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/** SMTP-Zugänge verwalten (nur Admin). Die Zugänge gelten mandantenübergreifend; Mandanten wählen einen aus. */
class MailAccountController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        $sort = ListSort::make($request, 'mail-accounts', [
            'name' => 'name', 'server' => 'host', 'from' => 'from_address', 'bcc' => 'bcc', 'used_by' => 'tenants_count',
        ], ['name', 'asc'], [['id', 'asc']]);

        return view('mail-accounts.index', [
            'sort' => $sort,
            'accounts' => MailAccount::query()->withCount('tenants')->tap(fn ($q) => $sort->apply($q))->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('manage-users');

        return view('mail-accounts.form', ['account' => new MailAccount(['port' => 587, 'encryption' => 'starttls'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $account = MailAccount::create($this->validated($request, null));

        return redirect()->route('mail-accounts.edit', $account)
            ->with('status', __('messages.mail_accounts.created_test', ['name' => $account->name]));
    }

    public function edit(MailAccount $account): View
    {
        $this->authorize('manage-users');

        return view('mail-accounts.form', ['account' => $account]);
    }

    public function update(Request $request, MailAccount $account): RedirectResponse
    {
        $this->authorize('manage-users');

        $account->update($this->validated($request, $account));

        return redirect()->route('mail-accounts.index')
            ->with('status', __('messages.mail_accounts.updated', ['name' => $account->name]));
    }

    public function destroy(MailAccount $account): RedirectResponse
    {
        $this->authorize('manage-users');

        $used = $account->tenants()->orderBy('name')->pluck('name');
        if ($used->isNotEmpty()) {
            return redirect()->route('mail-accounts.index')
                ->with('warning', __('messages.mail_accounts.in_use', ['tenants' => $used->implode(', ')]));
        }

        $account->delete();

        return redirect()->route('mail-accounts.index')->with('status', __('messages.mail_accounts.deleted'));
    }

    /** Ohne Empfänger: nur Verbindung und Anmeldung prüfen. Mit Empfänger: zusätzlich eine Test-E-Mail senden. */
    public function test(Request $request, MailAccount $account, MailSender $sender): RedirectResponse
    {
        $this->authorize('manage-users');

        $to = $request->validate(['to' => ['nullable', 'email', 'max:255']])['to'] ?? null;

        try {
            if ($to) {
                $sender->send($account, (new Email)
                    ->from(new Address($account->from_address, (string) $account->from_name))
                    ->to(new Address($to))
                    ->subject(__('messages.mail_accounts.test_subject'))
                    ->text(__('messages.mail_accounts.test_body', ['name' => $account->name])));
            } else {
                $sender->check($account);
            }
        } catch (Throwable $e) {
            AuditLog::record('mailaccount.test_failed', $account, ['to' => $to, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return redirect()->route('mail-accounts.edit', $account)
                ->with('warning', __('messages.mail_accounts.test_failed', ['error' => mb_substr(trim($e->getMessage()) ?: $e::class, 0, 500)]));
        }

        AuditLog::record('mailaccount.tested', $account, ['to' => $to]);

        return redirect()->route('mail-accounts.edit', $account)->with('status',
            $to ? __('messages.mail_accounts.test_mail_ok', ['to' => $to]) : __('messages.mail_accounts.test_ok'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MailAccount $account): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(MailAccount::ENCRYPTIONS)],
            'username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:500'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'bcc' => ['nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                foreach (MailAccount::splitAddresses($value) as $address) {
                    if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                        $fail(__('messages.mail_accounts.bcc_invalid', ['address' => $address]));
                    }
                }
            }],
        ]);

        $data = $this->emptyToNull($data);

        $data['bcc'] = ($list = MailAccount::splitAddresses($data['bcc'] ?? null)) ? implode(', ', $list) : null;

        // Leeres Passwortfeld beim Bearbeiten = Passwort unverändert lassen. Ohne Benutzername gibt es kein Passwort.
        if ($data['smtp_password'] === null) {
            unset($data['smtp_password']);
        }
        if ($data['username'] === null) {
            $data['smtp_password'] = null;
        }

        return $data;
    }
}
