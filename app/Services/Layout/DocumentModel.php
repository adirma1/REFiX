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

namespace App\Services\Layout;

use App\Models\Dunning;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\InvoiceCalculator;
use App\Support\Locale;
use Carbon\Carbon;

/** Baut das Datenmodell, das Layout-Vorlagen als Platzhalter verwenden (alles fertig formatiert, deutsch). */
class DocumentModel
{
    /**
     * Platzhalter für die KI-Anweisung: Pfad => Beschreibung. Muss zu {@see self::compose()} passen
     * (ein Test vergleicht beides).
     *
     * @var array<string, string>
     */
    public const REFERENCE = [
        'doc.title' => 'Belegart: „Rechnung“, „Gutschrift“, „2. Teilrechnung“, „Schlussrechnung“ oder „Angebot“',
        'doc.number' => 'Beleg-Nummer',
        'doc.number_label' => 'Beschriftung der Nummer („Rechnungsnummer“ / „Gutschriftnummer“ / „Angebotsnummer“)',
        'doc.date' => 'Belegdatum (TT.MM.JJJJ)',
        'doc.due_date' => 'Fälligkeitsdatum (leer bei Gutschrift)',
        'doc.service_period' => 'Leistungsdatum bzw. -zeitraum als Text',
        'doc.currency' => 'Währung, z. B. EUR',
        'doc.order_ref' => 'Bestellnummer des Kunden (kann leer sein)',
        'doc.buyer_reference' => 'Leitweg-ID / Käuferreferenz (kann leer sein)',
        'doc.refers_to' => 'Nummer der Ursprungsrechnung (nur bei Gutschriften)',
        'doc.intro' => 'Einleitungstext (mehrzeilig, mit | br ausgeben)',
        'doc.footer_text' => 'Schlusstext (mehrzeilig, mit | br ausgeben)',
        'doc.tax_note' => 'Hinweis bei Reverse Charge / steuerfrei (sonst leer)',
        'doc.is_credit' => 'Wahr bei Gutschrift',
        'doc.billing_kind' => 'Abrechnungsart: normal, partial (Teilrechnung) oder final (Schlussrechnung)',
        'doc.is_partial' => 'Wahr bei einer Teilrechnung',
        'doc.is_final' => 'Wahr bei einer Schlussrechnung',
        'doc.is_quote' => 'Wahr bei einem Angebot (dann gibt es keinen Zahlungsblock und keine Fälligkeit)',
        'doc.valid_until' => 'Nur Angebote: gültig bis (TT.MM.JJJJ), sonst leer',
        'tenant.name' => 'Firmenname des Absenders',
        'tenant.street' => 'Straße',
        'tenant.zip' => 'PLZ',
        'tenant.city' => 'Ort',
        'tenant.country' => 'Land (Kürzel)',
        'tenant.vat_id' => 'USt-IdNr.',
        'tenant.tax_number' => 'Steuernummer',
        'tenant.email' => 'E-Mail (Freitext, wie eingegeben, ggf. mit „Email:“)',
        'tenant.phone' => 'Telefon (Freitext, wie eingegeben)',
        'tenant.website' => 'Web (Freitext, wie eingegeben)',
        'tenant.bank_name' => 'Bank',
        'tenant.iban' => 'IBAN, in 4er-Gruppen',
        'tenant.iban_compact' => 'IBAN ohne Leerzeichen (für schmale Fußzeilen)',
        'tenant.bic' => 'BIC',
        'tenant.court' => 'Registergericht',
        'tenant.register_number' => 'Registernummer',
        'tenant.managing_director' => 'Geschäftsführer',
        'tenant.logo' => 'Logo als Bild-Adresse (nur in <img src="{{tenant.logo}}"> verwenden; per {{#if tenant.logo}} prüfen)',
        'tenant.sender_line' => 'Kleine Absenderzeile für das Anschriftenfenster („Firma · Straße · PLZ Ort“)',
        'customer.number' => 'Kundennummer',
        'customer.company' => 'Firma des Kunden',
        'customer.contact' => 'Ansprechpartner (kann leer sein)',
        'customer.street' => 'Straße',
        'customer.zip' => 'PLZ',
        'customer.city' => 'Ort',
        'customer.country' => 'Land (Kürzel)',
        'customer.show_country' => 'Wahr, wenn das Land auf der Anschrift stehen soll (Ausland)',
        'customer.vat_id' => 'USt-IdNr. des Kunden (kann leer sein)',
        'items' => 'LISTE der Positionen – mit {{#each items}} … {{/each}} durchlaufen',
        'items.pos' => 'Positionsnummer',
        'items.description' => 'Beschreibung (mehrzeilig, mit | br ausgeben)',
        'items.title' => 'erste Zeile der Beschreibung',
        'items.details' => 'weitere Zeilen der Beschreibung (kann leer sein, mit | br ausgeben)',
        'items.quantity' => 'Menge (formatiert)',
        'items.unit' => 'Einheit (kann leer sein)',
        'items.unit_price' => 'Einzelpreis (formatiert)',
        'items.discount' => 'Positionsrabatt, z. B. „10,00 %“ (leer, wenn keiner)',
        'items.tax_rate' => 'Steuersatz, z. B. „19 %“ (bei steuerfreien Belegen „–“)',
        'items.net_amount' => 'Positionsbetrag netto (formatiert)',
        'totals.currency' => 'Währung',
        'totals.has_discount' => 'Wahr, wenn ein Gesamtrabatt besteht',
        'totals.subtotal' => 'Zwischensumme vor Gesamtrabatt',
        'totals.discount_percent' => 'Gesamtrabatt in Prozent',
        'totals.discount_total' => 'Gesamtrabatt als Betrag',
        'totals.net_total' => 'Nettosumme',
        'totals.tax_total' => 'Steuer gesamt',
        'totals.gross_total' => 'Endbetrag (brutto)',
        'totals.show_tax' => 'Wahr, wenn Steuer ausgewiesen wird (Normalfall)',
        'tax_groups' => 'LISTE der Steuersätze – {{#each tax_groups}} mit rate, net, tax',
        'tax_groups.rate' => 'Steuersatz, z. B. „19“',
        'tax_groups.net' => 'Nettosumme dieses Satzes',
        'tax_groups.tax' => 'Steuerbetrag dieses Satzes',
        'payment.show' => 'Wahr bei Rechnungen (Zahlungsblock zeigen), falsch bei Gutschriften',
        'payment.due_text' => 'Satz „Zahlbar bis … ohne Abzug.“',
        'payment.has_bank' => 'Wahr, wenn Bankdaten vorhanden sind',
        'payment.reference' => 'Verwendungszweck',
        'payment.qr' => 'GiroCode (QR) als Bild-Adresse; nur in <img src="{{payment.qr}}">; per {{#if payment.has_qr}} prüfen',
        'payment.has_qr' => 'Wahr, wenn ein GiroCode vorhanden ist',
        'series.show' => 'Wahr bei Teil- und Schlussrechnungen (Übersicht der Rechnungsserie zeigen)',
        'series.rows' => 'LISTE der bisherigen Rechnungen der Serie samt dieser – {{#each series.rows}} mit number, date, net, gross, is_this',
        'series.rows.number' => 'Rechnungsnummer',
        'series.rows.date' => 'Rechnungsdatum',
        'series.rows.net' => 'Nettobetrag (früherer Rechnungen nach Abzug von Gutschriften)',
        'series.rows.gross' => 'Bruttobetrag (früherer Rechnungen nach Abzug von Gutschriften)',
        'series.rows.is_this' => 'Wahr bei der Zeile dieses Belegs (zum Hervorheben)',
        'series.total_net' => 'Netto gesamt abgerechnet (inklusive dieses Belegs)',
        'series.total_gross' => 'Brutto gesamt abgerechnet (inklusive dieses Belegs)',
        'skonto' => 'LISTE der Skonto-Zeilen – {{#each skonto}}{{text}}{{/each}}',
        'skonto.text' => 'Text einer Skonto-Zeile',
        'labels.*' => 'Fertige Beschriftungen: pos, description, quantity, unit_price, discount, vat, amount, subtotal, net_total, gross_total, date, customer_no, customer_vat_id, service_date, order_ref, buyer_reference, refers_to, payment, bank_transfer, reference, girocode, managing_director, tax_number, page, vat_id, series_title, series_date, series_net, series_gross, series_total, valid_until',
    ];

    /**
     * Platzhalter für die KI-Anweisung des Mahn-Layouts. Muss zu {@see self::composeDunning()} passen
     * (ein Test vergleicht beides).
     *
     * @var array<string, string>
     */
    public const REFERENCE_DUNNING = [
        'doc.title' => 'Art des Schreibens: „Zahlungserinnerung“, „1. Mahnung“ oder „2. Mahnung (letzte Mahnung)“',
        'doc.level' => 'Stufe als Zahl: 1 = Zahlungserinnerung, 2 = 1. Mahnung, 3 = 2. Mahnung',
        'doc.is_reminder' => 'Wahr bei der Zahlungserinnerung (Stufe 1)',
        'doc.date' => 'Datum des Schreibens (TT.MM.JJJJ)',
        'doc.text' => 'Brieftext (mehrzeilig, mit | br ausgeben); Anrede und Schlussformel sind schon enthalten',
        'doc.currency' => 'Währung, z. B. EUR',
        'doc.pay_by' => 'Zahlungsfrist (TT.MM.JJJJ)',
        'doc.pay_by_text' => 'Satz „Bitte zahlen Sie bis zum …“',
        'doc.crossed_note' => 'Hinweis „Sollte sich Ihre Zahlung mit diesem Schreiben überschnitten haben …“',
        'invoice.number' => 'Nummer der offenen Rechnung',
        'invoice.date' => 'Rechnungsdatum',
        'invoice.due_date' => 'Fälligkeitsdatum der Rechnung',
        'invoice.open_text' => 'Zeilentext „Offene Rechnung R-…“',
        'dunning.open_amount' => 'Offener Rechnungsbetrag (formatiert)',
        'dunning.has_fee' => 'Wahr, wenn eine Mahngebühr berechnet wird',
        'dunning.fee' => 'Mahngebühr (formatiert)',
        'dunning.fee_label' => 'Beschriftung der Gebührenzeile',
        'dunning.has_interest' => 'Wahr, wenn Verzugszinsen berechnet werden',
        'dunning.interest' => 'Verzugszinsen (formatiert)',
        'dunning.interest_label' => 'Beschriftung der Zinszeile, z. B. „Verzugszinsen (14,62 % für 12 Tage)“',
        'dunning.total' => 'Gesamtforderung (formatiert)',
        'tenant.name' => 'Firmenname des Absenders',
        'tenant.street' => 'Straße',
        'tenant.zip' => 'PLZ',
        'tenant.city' => 'Ort',
        'tenant.country' => 'Land (Kürzel)',
        'tenant.vat_id' => 'USt-IdNr.',
        'tenant.tax_number' => 'Steuernummer',
        'tenant.email' => 'E-Mail (Freitext, wie eingegeben, ggf. mit „Email:“)',
        'tenant.phone' => 'Telefon (Freitext, wie eingegeben)',
        'tenant.website' => 'Web (Freitext, wie eingegeben)',
        'tenant.bank_name' => 'Bank',
        'tenant.iban' => 'IBAN, in 4er-Gruppen',
        'tenant.iban_compact' => 'IBAN ohne Leerzeichen (für schmale Fußzeilen)',
        'tenant.bic' => 'BIC',
        'tenant.court' => 'Registergericht',
        'tenant.register_number' => 'Registernummer',
        'tenant.managing_director' => 'Geschäftsführer',
        'tenant.logo' => 'Logo als Bild-Adresse (nur in <img src="{{tenant.logo}}"> verwenden; per {{#if tenant.logo}} prüfen)',
        'tenant.sender_line' => 'Kleine Absenderzeile für das Anschriftenfenster („Firma · Straße · PLZ Ort“)',
        'customer.number' => 'Kundennummer (kann leer sein)',
        'customer.company' => 'Firma des Kunden',
        'customer.contact' => 'Ansprechpartner (kann leer sein)',
        'customer.street' => 'Straße',
        'customer.zip' => 'PLZ',
        'customer.city' => 'Ort',
        'customer.country' => 'Land (Kürzel)',
        'customer.show_country' => 'Wahr, wenn das Land auf der Anschrift stehen soll (Ausland)',
        'customer.vat_id' => 'USt-IdNr. des Kunden (kann leer sein)',
        'payment.has_bank' => 'Wahr, wenn Bankdaten vorhanden sind',
        'payment.reference' => 'Verwendungszweck (Rechnungsnummer)',
        'payment.qr' => 'GiroCode (QR) über die Gesamtforderung als Bild-Adresse; nur in <img src="{{payment.qr}}">; per {{#if payment.has_qr}} prüfen',
        'payment.has_qr' => 'Wahr, wenn ein GiroCode vorhanden ist',
        'labels.*' => 'Fertige Beschriftungen: date, customer_no, invoice_no, position, invoice_date, due, amount, total, bank_transfer, reference, girocode, managing_director, tax_number, page, vat_id',
    ];

    /** Platzhalter-Beschreibungen je Layout-Art. @return array<string, string> */
    public static function referenceFor(string $kind): array
    {
        // Angebote verwenden dasselbe Datenmodell wie Rechnungen (mit doc.is_quote und doc.valid_until).
        return $kind === 'dunning' ? self::REFERENCE_DUNNING : self::REFERENCE;
    }

    /** Musterdaten je Layout-Art (für Vorschau und Prüfung). @return array<string, mixed> */
    public static function sampleFor(string $kind, Tenant $tenant, ?string $logo, ?string $qr = null): array
    {
        return match ($kind) {
            'dunning' => self::dunningSample($tenant, $logo, $qr),
            'quote' => self::quoteSample($tenant, $logo),
            default => self::sample($tenant, $logo, $qr),
        };
    }

    /** @return array<string, mixed> */
    public static function fromDunning(Dunning $dunning, Invoice $invoice, Tenant $tenant, string $text, ?string $logo, ?string $qr): array
    {
        return self::composeDunning([
            'level' => (int) $dunning->level,
            'label' => $dunning->label(),
            'date' => $dunning->dunned_on,
            'text' => $text,
            'currency' => $dunning->currency,
            'pay_by' => $dunning->pay_by,
            'invoice_number' => (string) $invoice->number,
            'invoice_date' => $invoice->issue_date,
            'invoice_due' => $invoice->due_date,
            'open_amount' => (float) $dunning->open_amount,
            'fee' => (float) $dunning->fee,
            'interest' => (float) $dunning->interest,
            'interest_rate' => (float) $dunning->interest_rate,
            'interest_days' => (int) $dunning->interest_days,
            'total' => (float) $dunning->total,
            'customer' => $invoice->customer_snapshot ?? [],
            'logo' => $logo,
            'qr' => $qr,
        ], $tenant);
    }

    /** Muster-Mahnung (1. Mahnung mit Gebühr und Zinsen, damit alle Blöcke sichtbar sind). @return array<string, mixed> */
    public static function dunningSample(Tenant $tenant, ?string $logo, ?string $qr = null): array
    {
        return self::composeDunning([
            'level' => 2,
            'label' => Locale::with('de', fn () => __('messages.dunning.level_2')),
            'date' => Carbon::parse('2026-11-12'),
            'text' => "Sehr geehrte Damen und Herren,\n\nbei der Durchsicht unserer Buchhaltung ist uns aufgefallen, dass die Rechnung R-2026-0001 trotz unserer Zahlungserinnerung noch nicht beglichen wurde. Wir bitten Sie, den offenen Betrag bis zum genannten Termin zu überweisen.\n\nMit freundlichen Grüßen",
            'currency' => 'EUR',
            'pay_by' => Carbon::parse('2026-11-19'),
            'invoice_number' => 'R-2026-0001',
            'invoice_date' => Carbon::parse('2026-10-11'),
            'invoice_due' => Carbon::parse('2026-10-25'),
            'open_amount' => 297.5,
            'fee' => 5.0,
            'interest' => 1.23,
            'interest_rate' => 14.62,
            'interest_days' => 12,
            'total' => 303.73,
            'customer' => ['customer_number' => '10007', 'company' => 'Beispiel Kunde GmbH', 'contact' => 'Frau Erika Mustermann', 'street' => 'Hauptstraße 5', 'zip' => '33154', 'city' => 'Salzkotten', 'country' => 'DE', 'vat_id' => 'DE987654321'],
            'logo' => $logo,
            'qr' => $qr,
        ], $tenant);
    }

    /** @param  array<string, mixed>  $in */
    private static function composeDunning(array $in, Tenant $tenant): array
    {
        $date = fn ($d) => $d ? $d->format('d.m.Y') : '';

        return [
            'doc' => [
                'title' => (string) $in['label'],
                'level' => (string) $in['level'],
                'is_reminder' => (int) $in['level'] === 1,
                'date' => $date($in['date']),
                'text' => (string) $in['text'],
                'currency' => (string) $in['currency'],
                'pay_by' => $date($in['pay_by']),
                'pay_by_text' => __('messages.dunning.pdf_pay_by', ['date' => $date($in['pay_by'])]),
                'crossed_note' => __('messages.dunning.pdf_crossed'),
            ],
            'invoice' => [
                'number' => (string) $in['invoice_number'],
                'date' => $date($in['invoice_date']),
                'due_date' => $date($in['invoice_due']),
                'open_text' => __('messages.dunning.pdf_open_invoice', ['number' => $in['invoice_number']]),
            ],
            'dunning' => [
                'open_amount' => self::money($in['open_amount']),
                'has_fee' => $in['fee'] > 0,
                'fee' => self::money($in['fee']),
                'fee_label' => __('messages.dunning.pdf_fee'),
                'has_interest' => $in['interest'] > 0,
                'interest' => self::money($in['interest']),
                'interest_label' => __('messages.dunning.pdf_interest', ['rate' => self::money($in['interest_rate']), 'days' => $in['interest_days']]),
                'total' => self::money($in['total']),
            ],
            'tenant' => self::tenantBlock($tenant, $in['logo'] ?? null),
            'customer' => self::customerBlock($in['customer'], $tenant),
            'payment' => [
                'has_bank' => (bool) $tenant->iban,
                'reference' => (string) $in['invoice_number'],
                'qr' => (string) ($in['qr'] ?? ''),
                'has_qr' => ! empty($in['qr']),
            ],
            'labels' => [
                'date' => __('messages.dunning.pdf_date'),
                'customer_no' => __('messages.pdf.customer_no'),
                'invoice_no' => __('messages.pdf.invoice_no'),
                'position' => __('messages.dunning.pdf_position'),
                'invoice_date' => __('messages.dunning.pdf_invoice_date'),
                'due' => __('messages.dunning.pdf_due'),
                'amount' => __('messages.dunning.pdf_amount'),
                'total' => __('messages.dunning.pdf_total'),
                'bank_transfer' => __('messages.pdf.bank_transfer'),
                'reference' => __('messages.pdf.reference'),
                'girocode' => __('messages.pdf.girocode'),
                'managing_director' => __('messages.pdf.managing_director'),
                'tax_number' => __('messages.pdf.tax_number'),
                'page' => __('messages.pdf.page'),
                'vat_id' => 'USt-IdNr.',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function fromInvoice(Invoice $invoice, Tenant $tenant, ?string $logo, ?string $qr, array $skontoLines, ?array $series = null): array
    {
        $invoice->loadMissing('items', 'originalInvoice');

        return self::compose([
            'is_credit' => $invoice->isCreditNote(),
            'billing_kind' => $invoice->isCreditNote() ? Invoice::BILLING_NORMAL : ($invoice->billing_kind ?? Invoice::BILLING_NORMAL),
            'title' => $invoice->documentTitle(),
            'series' => $series,
            'number' => $invoice->displayNumber(),
            'issue_date' => $invoice->issue_date,
            'due_date' => $invoice->due_date,
            'service_from' => $invoice->service_date_from,
            'service_to' => $invoice->service_date_to,
            'currency' => $invoice->currency,
            'tax_mode' => $invoice->tax_mode,
            'tax_note' => $invoice->taxNoteText(),
            'order_ref' => $invoice->customer_order_ref,
            'buyer_reference' => $invoice->buyer_reference,
            'refers_to' => $invoice->originalInvoice?->number,
            'intro' => $invoice->intro_text ?: $tenant->invoice_intro,
            'footer_text' => $invoice->footer_text ?: $tenant->invoice_footer,
            'discount_percent' => (float) $invoice->discount_percent,
            'discount_total' => (float) $invoice->discount_total,
            'net_total' => (float) $invoice->net_total,
            'tax_total' => (float) $invoice->tax_total,
            'gross_total' => (float) $invoice->gross_total,
            'tax_breakdown' => $invoice->tax_breakdown ?? [],
            'items' => $invoice->items->map(fn ($i) => $i->only(['position', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'tax_rate', 'net_amount']))->all(),
            'customer' => $invoice->customer_snapshot ?? [],
            'skonto' => $skontoLines,
            'logo' => $logo,
            'qr' => $qr,
        ], $tenant);
    }

    /** Datenmodell eines Angebots (gleiche Platzhalter wie Rechnungen; kein Zahlungsblock). @return array<string, mixed> */
    public static function fromQuote(Quote $quote, Tenant $tenant, ?string $logo): array
    {
        $quote->loadMissing('items');

        return self::compose([
            'is_credit' => false,
            'is_quote' => true,
            'billing_kind' => Invoice::BILLING_NORMAL,
            'title' => __('messages.quotes.title_single'),
            'series' => null,
            'number' => $quote->displayNumber(),
            'issue_date' => $quote->issue_date,
            'due_date' => null,
            'valid_until' => $quote->valid_until,
            'service_from' => null,
            'service_to' => null,
            'currency' => $quote->currency,
            'tax_mode' => $quote->tax_mode,
            'tax_note' => $quote->taxNoteText(),
            'order_ref' => null,
            'buyer_reference' => $quote->buyer_reference,
            'refers_to' => null,
            'intro' => $quote->intro_text ?: __('messages.quotes.default_intro'),
            'footer_text' => $quote->footer_text ?: __('messages.quotes.default_footer'),
            'discount_percent' => (float) $quote->discount_percent,
            'discount_total' => (float) $quote->discount_total,
            'net_total' => (float) $quote->net_total,
            'tax_total' => (float) $quote->tax_total,
            'gross_total' => (float) $quote->gross_total,
            'tax_breakdown' => $quote->tax_breakdown ?? [],
            'items' => $quote->items->map(fn ($i) => $i->only(['position', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'tax_rate', 'net_amount']))->all(),
            'customer' => $quote->customer_snapshot ?? [],
            'skonto' => [],
            'logo' => $logo,
            'qr' => null,
        ], $tenant);
    }

    /** Musterangebot für Vorschau und Prüfung. @return array<string, mixed> */
    public static function quoteSample(Tenant $tenant, ?string $logo): array
    {
        $model = self::sample($tenant, $logo, null);

        $model['doc']['title'] = Locale::with('de', fn () => __('messages.quotes.title_single'));
        $model['doc']['number'] = 'A-2026-0003';
        $model['doc']['number_label'] = Locale::with('de', fn () => __('messages.pdf.quote_no'));
        $model['doc']['due_date'] = '';
        $model['doc']['valid_until'] = '10.11.2026';
        $model['doc']['is_quote'] = true;
        $model['doc']['is_final'] = false;
        $model['doc']['billing_kind'] = Invoice::BILLING_NORMAL;
        $model['doc']['intro'] = Locale::with('de', fn () => __('messages.quotes.default_intro'));
        $model['doc']['footer_text'] = Locale::with('de', fn () => __('messages.quotes.default_footer'));
        $model['payment'] = ['show' => false, 'due_text' => '', 'has_bank' => (bool) $tenant->iban, 'reference' => 'A-2026-0003', 'qr' => '', 'has_qr' => false];
        $model['series'] = ['show' => false, 'rows' => [], 'total_net' => '0,00', 'total_gross' => '0,00'];
        $model['skonto'] = [];

        return $model;
    }

    /** Musterbeleg für Vorschau und Prüfung; Absenderdaten stammen vom Mandanten. */
    public static function sample(Tenant $tenant, ?string $logo, ?string $qr = null): array
    {
        $items = [
            ['position' => 1, 'description' => "IT-Beratung\nLaufzeit Oktober 2026", 'quantity' => 12.5, 'unit' => 'Stunde', 'unit_price' => 95, 'discount_percent' => 0, 'tax_rate' => 19],
            ['position' => 2, 'description' => 'Fachbuch', 'quantity' => 2, 'unit' => 'Stück', 'unit_price' => 30, 'discount_percent' => 10, 'tax_rate' => 7],
            ['position' => 3, 'description' => 'Einrichtung vor Ort', 'quantity' => 1, 'unit' => 'Pauschale', 'unit_price' => 250, 'discount_percent' => 0, 'tax_rate' => 19],
        ];
        $calc = InvoiceCalculator::calculate($items, 5.0);

        foreach ($items as $i => &$item) {
            $item['net_amount'] = $calc['lines'][$i];
        }
        unset($item);

        return self::compose([
            'is_credit' => false,
            'billing_kind' => Invoice::BILLING_FINAL,
            'title' => Locale::with('de', fn () => __('messages.invoices.final_title')),
            'series' => [
                'rows' => [
                    ['number' => 'R-2026-0001', 'date' => Carbon::parse('2026-08-14'), 'net' => 2000.0, 'gross' => 2380.0, 'is_this' => false],
                    ['number' => 'R-2026-0002', 'date' => Carbon::parse('2026-09-12'), 'net' => 1500.0, 'gross' => 1785.0, 'is_this' => false],
                    ['number' => 'R-2026-0003', 'date' => Carbon::parse('2026-10-11'), 'net' => $calc['net_total'], 'gross' => $calc['gross_total'], 'is_this' => true],
                ],
                'total_net' => round(3500.0 + $calc['net_total'], 2),
                'total_gross' => round(4165.0 + $calc['gross_total'], 2),
            ],
            'number' => 'R-2026-0003',
            'issue_date' => Carbon::parse('2026-10-11'),
            'due_date' => Carbon::parse('2026-10-25'),
            'service_from' => Carbon::parse('2026-10-01'),
            'service_to' => Carbon::parse('2026-10-31'),
            'currency' => 'EUR',
            'tax_mode' => 'standard',
            'order_ref' => 'PO-4711',
            'buyer_reference' => '04011000-12345-34',
            'refers_to' => null,
            'intro' => "Sehr geehrte Damen und Herren,\nvielen Dank für Ihren Auftrag. Wir berechnen Ihnen folgende Leistungen:",
            'footer_text' => 'Vielen Dank für die gute Zusammenarbeit.',
            'discount_percent' => 5.0,
            'discount_total' => $calc['discount_total'],
            'net_total' => $calc['net_total'],
            'tax_total' => $calc['tax_total'],
            'gross_total' => $calc['gross_total'],
            'tax_breakdown' => $calc['groups'],
            'items' => $items,
            'customer' => ['customer_number' => '10007', 'company' => 'Beispiel Kunde GmbH', 'contact' => 'Frau Erika Mustermann', 'street' => 'Hauptstraße 5', 'zip' => '33154', 'city' => 'Salzkotten', 'country' => 'DE', 'vat_id' => 'DE987654321'],
            'skonto' => [self::skontoText(8, '19.10.2026', 2.0, $calc['gross_total'])],
            'logo' => $logo,
            'qr' => $qr,
        ], $tenant);
    }

    private static function skontoText(int $days, string $date, float $percent, float $gross): string
    {
        return __('messages.pdf.skonto_line', [
            'days' => $days, 'date' => $date, 'percent' => self::money($percent),
            'amount' => self::money(round($gross * (1 - $percent / 100), 2)).' EUR',
        ]);
    }

    /** @param  array<string, mixed>  $in */
    private static function compose(array $in, Tenant $tenant): array
    {
        $isCredit = (bool) $in['is_credit'];
        $isQuote = (bool) ($in['is_quote'] ?? false);
        $standard = $in['tax_mode'] === 'standard';
        $customer = $in['customer'];
        $from = $in['service_from'];
        $to = $in['service_to'];

        if ($from && $to && ! $from->isSameDay($to)) {
            $period = $from->format('d.m.Y').' – '.$to->format('d.m.Y');
        } elseif ($from) {
            $period = $from->format('d.m.Y');
        } else {
            $period = __('messages.pdf.service_date_equals');
        }

        $items = [];
        foreach ($in['items'] as $item) {
            $lines = preg_split('/\R/', trim((string) $item['description']), 2);
            $items[] = [
                'pos' => (string) $item['position'],
                'description' => (string) $item['description'],
                'title' => trim($lines[0]),
                'details' => trim($lines[1] ?? ''),
                'quantity' => self::quantity($item['quantity']),
                'unit' => (string) ($item['unit'] ?? ''),
                'unit_price' => self::money($item['unit_price']),
                'discount' => (float) $item['discount_percent'] > 0 ? self::money($item['discount_percent']).' %' : '',
                'tax_rate' => $standard ? self::percent($item['tax_rate']).' %' : '–',
                'net_amount' => self::money($item['net_amount']),
            ];
        }

        return [
            'doc' => [
                'title' => (string) ($in['title'] ?? ($isCredit ? __('messages.invoices.credit_note') : __('messages.invoices.invoice'))),
                'number' => (string) $in['number'],
                'number_label' => $isQuote ? __('messages.pdf.quote_no') : ($isCredit ? __('messages.pdf.credit_note_no') : __('messages.pdf.invoice_no')),
                'date' => $in['issue_date']->format('d.m.Y'),
                'due_date' => $in['due_date'] ? $in['due_date']->format('d.m.Y') : '',
                'service_period' => $period,
                'currency' => (string) $in['currency'],
                'order_ref' => (string) ($in['order_ref'] ?? ''),
                'buyer_reference' => (string) ($in['buyer_reference'] ?? ''),
                'refers_to' => (string) ($in['refers_to'] ?? ''),
                'intro' => (string) ($in['intro'] ?? ''),
                'footer_text' => (string) ($in['footer_text'] ?? ''),
                'tax_note' => $standard ? '' : (($in['tax_note'] ?? '') ?: __('messages.pdf.tax_note.'.$in['tax_mode'])),
                'is_credit' => $isCredit,
                'is_quote' => $isQuote,
                'valid_until' => ! empty($in['valid_until']) ? $in['valid_until']->format('d.m.Y') : '',
                'billing_kind' => (string) ($in['billing_kind'] ?? Invoice::BILLING_NORMAL),
                'is_partial' => ($in['billing_kind'] ?? null) === Invoice::BILLING_PARTIAL,
                'is_final' => ($in['billing_kind'] ?? null) === Invoice::BILLING_FINAL,
            ],
            'tenant' => self::tenantBlock($tenant, $in['logo'] ?? null),
            'customer' => self::customerBlock($customer, $tenant),
            'items' => $items,
            'totals' => [
                'currency' => (string) $in['currency'],
                'has_discount' => $in['discount_total'] > 0,
                'subtotal' => self::money($in['net_total'] + $in['discount_total']),
                'discount_percent' => self::money($in['discount_percent']),
                'discount_total' => self::money($in['discount_total']),
                'net_total' => self::money($in['net_total']),
                'tax_total' => self::money($in['tax_total']),
                'gross_total' => self::money($in['gross_total']),
                'show_tax' => $standard,
            ],
            'tax_groups' => $standard ? array_map(fn ($g) => [
                'rate' => self::percent($g['rate']),
                'net' => self::money($g['net']),
                'tax' => self::money($g['tax']),
            ], $in['tax_breakdown']) : [],
            'payment' => [
                'show' => ! $isCredit && ! $isQuote,
                'due_text' => $in['due_date'] ? __('messages.pdf.due_on', ['date' => $in['due_date']->format('d.m.Y')]) : '',
                'has_bank' => (bool) $tenant->iban,
                'reference' => (string) $in['number'],
                'qr' => (string) ($in['qr'] ?? ''),
                'has_qr' => ! empty($in['qr']),
            ],
            'series' => [
                'show' => ! empty($in['series']),
                'rows' => array_map(fn ($row) => [
                    'number' => (string) $row['number'],
                    'date' => $row['date'] ? $row['date']->format('d.m.Y') : '',
                    'net' => self::money($row['net']),
                    'gross' => self::money($row['gross']),
                    'is_this' => (bool) $row['is_this'],
                ], $in['series']['rows'] ?? []),
                'total_net' => self::money($in['series']['total_net'] ?? 0),
                'total_gross' => self::money($in['series']['total_gross'] ?? 0),
            ],
            'skonto' => array_map(fn ($line) => ['text' => $line], $in['skonto']),
            'labels' => [
                'pos' => __('messages.pdf.pos'),
                'description' => __('messages.pdf.description'),
                'quantity' => __('messages.pdf.quantity'),
                'unit_price' => __('messages.pdf.unit_price'),
                'discount' => __('messages.pdf.discount'),
                'vat' => __('messages.pdf.vat'),
                'amount' => __('messages.pdf.amount'),
                'subtotal' => __('messages.pdf.subtotal'),
                'net_total' => __('messages.pdf.net_total'),
                'gross_total' => $isCredit ? __('messages.pdf.credit_total') : __('messages.pdf.gross_total'),
                'date' => __('messages.pdf.date'),
                'customer_no' => __('messages.pdf.customer_no'),
                'customer_vat_id' => __('messages.pdf.customer_vat_id'),
                'service_date' => __('messages.pdf.service_date'),
                'order_ref' => __('messages.pdf.order_ref'),
                'buyer_reference' => __('messages.pdf.buyer_reference'),
                'refers_to' => __('messages.pdf.refers_to'),
                'payment' => __('messages.pdf.payment'),
                'bank_transfer' => __('messages.pdf.bank_transfer'),
                'reference' => __('messages.pdf.reference'),
                'girocode' => __('messages.pdf.girocode'),
                'managing_director' => __('messages.pdf.managing_director'),
                'tax_number' => __('messages.pdf.tax_number'),
                'page' => __('messages.pdf.page'),
                'vat_id' => 'USt-IdNr.',
                'series_title' => __('messages.pdf.series_title'),
                'series_date' => __('messages.pdf.series_date'),
                'series_net' => __('messages.pdf.series_net'),
                'series_gross' => __('messages.pdf.series_gross'),
                'series_total' => __('messages.pdf.series_total'),
                'valid_until' => __('messages.pdf.valid_until'),
            ],
        ];
    }

    /** Absenderdaten des Mandanten (für alle Belegarten gleich). @return array<string, mixed> */
    private static function tenantBlock(Tenant $tenant, ?string $logo): array
    {
        $iban = $tenant->iban ? trim(chunk_split(strtoupper(str_replace(' ', '', $tenant->iban)), 4, ' ')) : '';

        return [
            'name' => (string) $tenant->name,
            'street' => (string) $tenant->street,
            'zip' => (string) $tenant->zip,
            'city' => (string) $tenant->city,
            'country' => (string) $tenant->country,
            'vat_id' => (string) $tenant->vat_id,
            'tax_number' => (string) $tenant->tax_number,
            'email' => (string) $tenant->email,
            'phone' => (string) $tenant->phone,
            'website' => (string) $tenant->website,
            'bank_name' => (string) $tenant->bank_name,
            'iban' => $iban,
            'iban_compact' => strtoupper(str_replace(' ', '', (string) $tenant->iban)),
            'bic' => (string) $tenant->bic,
            'court' => (string) $tenant->court,
            'register_number' => (string) $tenant->register_number,
            'managing_director' => (string) $tenant->managing_director,
            'logo' => (string) ($logo ?? ''),
            'sender_line' => trim($tenant->name.' · '.$tenant->street.' · '.trim($tenant->zip.' '.$tenant->city), ' ·'),
        ];
    }

    /** Anschrift des Kunden aus dem eingefrorenen Stand. @param  array<string, mixed>  $customer @return array<string, mixed> */
    private static function customerBlock(array $customer, Tenant $tenant): array
    {
        return [
            'number' => (string) ($customer['customer_number'] ?? ''),
            'company' => (string) ($customer['company'] ?? ''),
            'contact' => (string) ($customer['contact'] ?? ''),
            'street' => (string) ($customer['street'] ?? ''),
            'zip' => (string) ($customer['zip'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'country' => (string) ($customer['country'] ?? ''),
            'show_country' => ($customer['country'] ?? 'DE') !== ($tenant->country ?: 'DE'),
            'vat_id' => (string) ($customer['vat_id'] ?? ''),
        ];
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }

    private static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, ',', '.'), '0'), ',');
    }

    private static function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}
