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
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\Mail\MailTemplates;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** E-Mail-Einstellungen des aktuellen Mandanten: Zugang, Rechte-Matrix und Standardtexte (nur Admin). */
class MailSettingsController extends Controller
{
    /** Rollen, die zusätzlich zum Admin freigegeben werden können. */
    public const GRANTABLE_ROLES = Tenant::MAIL_GRANTABLE_ROLES;

    private const TEXT_FIELDS = [
        'mail_invoice_subject' => [Invoice::TYPE_INVOICE, 'subject'],
        'mail_invoice_body' => [Invoice::TYPE_INVOICE, 'body'],
        'mail_credit_subject' => [Invoice::TYPE_CREDIT_NOTE, 'subject'],
        'mail_credit_body' => [Invoice::TYPE_CREDIT_NOTE, 'body'],
        'mail_quote_subject' => [Quote::TYPE, 'subject'],
        'mail_quote_body' => [Quote::TYPE, 'body'],
    ];

    public function edit(): View
    {
        $this->authorize('manage-users');

        $tenant = Tenant::findOrFail(Tenancy::id());

        return view('mail.settings', [
            'tenant' => $tenant,
            'accounts' => MailAccount::query()->orderBy('name')->get(),
            'grantable' => self::GRANTABLE_ROLES,
            'texts' => collect(self::TEXT_FIELDS)->map(fn ($def) => MailTemplates::template($tenant, ...$def))->all(),
            'placeholders' => MailTemplates::PLACEHOLDERS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $rules = [
            'mail_account_id' => ['nullable', 'integer', 'exists:mail_accounts,id'],
            'mail_send_roles' => ['nullable', 'array'],
            'mail_send_roles.*' => ['string', 'in:'.implode(',', self::GRANTABLE_ROLES)],
        ];
        foreach (self::TEXT_FIELDS as $field => [, $part]) {
            $rules[$field] = ['nullable', 'string', 'max:'.($part === 'subject' ? 255 : 5000), $this->placeholderRule()];
        }

        $data = $request->validate($rules);

        $update = [
            'mail_account_id' => $data['mail_account_id'] ?? null,
            // Eine leere Auswahl heißt „nur Admin“ (nicht „Standard“), deshalb immer ein Array speichern.
            'mail_send_roles' => array_values(array_intersect(self::GRANTABLE_ROLES, (array) ($data['mail_send_roles'] ?? []))),
        ];

        foreach (self::TEXT_FIELDS as $field => [$type, $part]) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data[$field] ?? '')));
            // Leer oder identisch mit dem Standard: nichts speichern, damit spätere Verbesserungen des Standards wirken.
            $update[$field] = ($text === '' || $text === MailTemplates::default($type, $part)) ? null : $text;
        }

        Tenant::findOrFail(Tenancy::id())->update($update);

        return redirect()->route('mail.edit')->with('status', __('messages.saved'));
    }

    private function placeholderRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($unknown = MailTemplates::unknown((string) $value)) {
                $fail(__('messages.mail.unknown_placeholder', ['names' => '{'.implode('}, {', $unknown).'}']));
            }
        };
    }
}
