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

use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Tenant;
use App\Support\Locale;

/** Standardtexte der Belegmails pro Mandant mit Platzhaltern. */
class MailTemplates
{
    public const PLACEHOLDERS = ['anrede', 'belegnummer', 'betrag', 'faelligkeit', 'datum', 'kunde', 'firma'];

    /** Eingebauter Standardtext (Belege und Mails sind immer deutsch). */
    public static function default(string $type, string $part): string
    {
        $key = self::kindKey($type).'_'.$part;

        return Locale::with('de', fn () => __('messages.mail.default_'.$key));
    }

    /** Schlüssel der Belegart in Spaltennamen und Sprachdateien: invoice, credit oder quote. */
    private static function kindKey(string $type): string
    {
        return match ($type) {
            Invoice::TYPE_CREDIT_NOTE => 'credit',
            Quote::TYPE => 'quote',
            default => 'invoice',
        };
    }

    /** Text des Mandanten oder, wenn leer, der eingebaute Standard. */
    public static function template(Tenant $tenant, string $type, string $part): string
    {
        $column = 'mail_'.self::kindKey($type).'_'.$part;
        $value = $tenant->{$column};

        return filled($value) ? (string) $value : self::default($type, $part);
    }

    /** Ersetzt {platzhalter} in einem Durchgang; Unbekanntes bleibt stehen. Ersetzte Werte werden nicht erneut ausgewertet. */
    public static function render(string $template, array $values): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $template);
    }

    /** @return array<int, string> unbekannte Platzhalter in einem Text (Tippfehler abfangen) */
    public static function unknown(string $text): array
    {
        preg_match_all('/\{([a-zA-Z_]+)\}/', $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($name) => ! in_array($name, self::PLACEHOLDERS, true))));
    }

    /** @return array<string, string> */
    public static function values(Invoice|Quote $invoice, Tenant $tenant): array
    {
        $customer = $invoice->customer;
        $last = trim((string) $customer?->last_name);
        $salutation = strtolower(trim((string) $customer?->salutation));

        $anrede = 'Sehr geehrte Damen und Herren,';
        if ($last !== '') {
            if (str_starts_with($salutation, 'herr')) {
                $anrede = 'Sehr geehrter Herr '.$last.',';
            } elseif (str_starts_with($salutation, 'frau')) {
                $anrede = 'Sehr geehrte Frau '.$last.',';
            }
        }

        $date = fn ($d) => $d?->format('d.m.Y');

        return [
            'anrede' => $anrede,
            'belegnummer' => (string) $invoice->number,
            'betrag' => number_format((float) $invoice->gross_total, 2, ',', '.').' '.$invoice->currency,
            // Bei Angeboten steht hier das Gültig-bis-Datum.
            'faelligkeit' => $date($invoice instanceof Quote ? $invoice->valid_until : $invoice->due_date) ?? $date($invoice->issue_date),
            'datum' => $date($invoice->issue_date),
            'kunde' => (string) ($invoice->customer_snapshot['company'] ?? $customer?->company),
            'firma' => $tenant->name,
        ];
    }

    /** Betreff und Text für einen Beleg, fertig ausgefüllt (Zeilenumbrüche im Betreff entfernt). */
    public static function compose(Invoice|Quote $invoice, Tenant $tenant): array
    {
        $type = $invoice instanceof Quote ? Quote::TYPE : $invoice->type;
        $values = self::values($invoice, $tenant);
        $subject = self::render(self::template($tenant, $type, 'subject'), $values);

        return [
            trim(preg_replace('/\s+/', ' ', $subject)),
            self::render(self::template($tenant, $type, 'body'), $values),
        ];
    }
}
