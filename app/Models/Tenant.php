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

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tenant extends Model
{
    use Auditable;

    /** Entspricht den Standardwerten der Datenbank, damit auch frisch angelegte Objekte vollständig sind. */
    protected $attributes = [
        'country' => 'DE',
        'default_payment_days' => 14,
        'test_mode' => false,
        'invoice_number_format' => 'R-{YYYY}-{NNNN}',
        'credit_note_number_format' => 'G-{YYYY}-{NNNN}',
        'dunning_days_after_due' => 7,
        'dunning_grace_days' => 7,
        'dunning_fee_1' => 0,
        'dunning_fee_2' => 0,
        'dunning_fee_3' => 0,
        'dunning_interest_enabled' => false,
        'dunning_interest_margin' => 9,
        'vat_free' => false,
        'show_due_text' => true,
        'show_customer_number' => true,
        'show_service_date' => true,
        'dunning_enabled' => true,
    ];

    protected $fillable = [
        'name', 'street', 'zip', 'city', 'country', 'vat_id', 'tax_number',
        'email', 'phone', 'website', 'bank_name', 'iban', 'bic', 'court',
        'register_number', 'managing_director', 'default_payment_days', 'test_mode',
        'invoice_number_format', 'credit_note_number_format', 'logo_path', 'invoice_intro', 'invoice_footer', 'layout_wishes', 'layout_wishes_dunning', 'layout_wishes_quote',
        'quote_number_format', 'quote_valid_days', 'mail_quote_subject', 'mail_quote_body',
        'mail_account_id', 'mail_send_roles', 'mail_invoice_subject', 'mail_invoice_body', 'mail_credit_subject', 'mail_credit_body',
        'dunning_days_after_due', 'dunning_grace_days', 'dunning_fee_1', 'dunning_fee_2', 'dunning_fee_3',
        'dunning_interest_enabled', 'dunning_base_rate', 'dunning_interest_margin',
        'dunning_text_1', 'dunning_text_2', 'dunning_text_3',
        'vat_free', 'vat_free_note', 'show_due_text', 'show_customer_number', 'show_service_date', 'dunning_enabled',
    ];

    protected function casts(): array
    {
        return [
            'test_mode' => 'boolean',
            'vat_free' => 'boolean',
            'show_due_text' => 'boolean',
            'show_customer_number' => 'boolean',
            'show_service_date' => 'boolean',
            'dunning_enabled' => 'boolean',
            'default_payment_days' => 'integer',
            'mail_send_roles' => 'array',
            'dunning_interest_enabled' => 'boolean',
            'dunning_base_rate' => 'decimal:2',
            'dunning_interest_margin' => 'decimal:2',
            'dunning_fee_1' => 'decimal:2',
            'dunning_fee_2' => 'decimal:2',
            'dunning_fee_3' => 'decimal:2',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class);
    }

    /** Rollen (außer Admin), die das Versandrecht überhaupt erhalten können. Der Lesezugriff nie. */
    public const MAIL_GRANTABLE_ROLES = ['accounting', 'clerk'];

    /** Rollen (außer Admin), die E-Mails versenden dürfen. Der Admin darf immer. */
    public function mailRoles(): array
    {
        // Auch bei manipulierten Daten bekommt der Lesezugriff nie das Recht.
        return array_values(array_intersect(self::MAIL_GRANTABLE_ROLES, $this->mail_send_roles ?? ['accounting']));
    }

    /** Steuerfall für neue Belege: Mandanten ohne Umsatzsteuer erzwingen „exempt“, sonst gilt die Wahl im Formular. */
    public function taxModeFor(?string $requested): string
    {
        return $this->vat_free ? 'exempt' : ($requested ?: 'standard');
    }

    /** Hinweistext für Belege ohne Umsatzsteuer (Standardtext, wenn keiner hinterlegt ist). */
    public function vatFreeNote(): string
    {
        return filled($this->vat_free_note) ? (string) $this->vat_free_note : __('messages.pdf.tax_note.exempt');
    }

    public function adminCount(): int
    {
        return $this->users()->wherePivot('role', 'admin')->where('users.active', true)->count();
    }
}
