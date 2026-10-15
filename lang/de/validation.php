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

return [
    'alpha' => ':Attribute darf nur Buchstaben enthalten.',
    'boolean' => ':Attribute muss Ja oder Nein sein.',
    'confirmed' => 'Die Wiederholung von :attribute stimmt nicht überein.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => ':Attribute ist kein gültiges Datum.',
    'different' => ':Attribute und :other müssen sich unterscheiden.',
    'email' => ':Attribute muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Der gewählte Wert für :attribute ist ungültig.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => ':Attribute muss eine ganze Zahl sein.',
    'numeric' => ':Attribute muss eine Zahl sein.',
    'regex' => ':Attribute hat ein ungültiges Format.',
    'required' => ':Attribute ist erforderlich.',
    'required_without_all' => ':Attribute ist erforderlich, wenn weder Vorname noch Nachname angegeben ist.',
    'string' => ':Attribute muss ein Text sein.',
    'unique' => ':Attribute ist bereits vergeben.',

    'max' => [
        'numeric' => ':Attribute darf höchstens :max sein.',
        'string' => ':Attribute darf höchstens :max Zeichen lang sein.',
    ],
    'min' => [
        'numeric' => ':Attribute muss mindestens :min sein.',
        'string' => ':Attribute muss mindestens :min Zeichen lang sein.',
    ],
    'size' => [
        'numeric' => ':Attribute muss :size sein.',
        'string' => ':Attribute muss genau :size Zeichen lang sein.',
    ],

    'custom' => [],

    'attributes' => [
        'email' => 'E-Mail-Adresse',
        'email_password' => 'Aktuelles Passwort',
        'username' => 'Benutzername',
        'login' => 'Benutzername oder E-Mail-Adresse',
        'password' => 'Passwort',
        'current_password' => 'Aktuelles Passwort',
        'name' => 'Name',
        'company' => 'Firma',
        'code' => 'Code',
        'role' => 'Rolle',
        'customer_number' => 'Kundennummer',
        'salutation' => 'Anrede',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'street' => 'Straße',
        'zip' => 'PLZ',
        'city' => 'Ort',
        'country' => 'Land',
        'phone' => 'Telefon',
        'vat_id' => 'USt-IdNr.',
        'currency' => 'Währung',
        'payment_term_days' => 'Zahlungsziel',
        'discount_percent' => 'Skonto',
        'discount_days' => 'Skonto-Frist',
        'buyer_reference' => 'Leitweg-ID',
        'notes' => 'Notizen',
        'article_number' => 'Artikelnummer',
        'description' => 'Beschreibung',
        'unit' => 'Einheit',
        'tax_rate' => 'Steuersatz',
        'amount' => 'Preis',
        'valid_from' => 'Gültig ab',
        'customer_id' => 'Kunde',
        'tax_number' => 'Steuernummer',
        'website' => 'Website',
        'bank_name' => 'Bank',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'court' => 'Registergericht',
        'register_number' => 'Handelsregisternummer',
        'managing_director' => 'Geschäftsführer',
        'default_payment_days' => 'Standard-Zahlungsziel',
        'locale' => 'Sprache',
    ],
];
