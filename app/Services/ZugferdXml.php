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

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Erzeugt die ZUGFeRD-/Factur-X-XML (Profil EN 16931, "COMFORT") aus einem Beleg
 * und prüft sie gegen das offizielle XML-Schema.
 *
 * Hinweis: Das Schema prüft nur Aufbau und Datentypen. Die fachlichen Regeln der EN 16931
 * (Schematron) sind hier nicht ausführbar; die wichtigsten Pflichtangaben prüft {@see self::preflight()}.
 */
class ZugferdXml
{
    public const GUIDELINE = 'urn:cen.eu:en16931:2017';

    private const NS_RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

    private const NS_RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

    private const NS_UDT = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /** Freitext-Einheit → UN/ECE-Rec.-20-Code. Unbekanntes wird zu "C62" (Stück). */
    private const UNITS = [
        'stk' => 'C62', 'stk.' => 'C62', 'st' => 'C62', 'st.' => 'C62', 'stück' => 'C62', 'stueck' => 'C62', 'x' => 'C62',
        'pauschal' => 'C62', 'psch' => 'C62', 'psch.' => 'C62', 'pcs' => 'C62', 'einheit' => 'C62',
        'h' => 'HUR', 'std' => 'HUR', 'std.' => 'HUR', 'stunde' => 'HUR', 'stunden' => 'HUR',
        'min' => 'MIN', 'tag' => 'DAY', 'tage' => 'DAY', 'monat' => 'MON', 'monate' => 'MON', 'jahr' => 'ANN',
        'kg' => 'KGM', 'g' => 'GRM', 't' => 'TNE', 'm' => 'MTR', 'km' => 'KMT', 'cm' => 'CMT', 'mm' => 'MMT',
        'l' => 'LTR', 'm²' => 'MTK', 'qm' => 'MTK', 'm2' => 'MTK', 'm³' => 'MTQ', 'cbm' => 'MTQ',
        'kwh' => 'KWH', 'woche' => 'WEE', 'wochen' => 'WEE',
    ];

    private DOMDocument $dom;

    /** Fachliche Mindestprüfung vor dem Erzeugen; liefert deutsche Fehlertexte. @return array<int, string> */
    public static function preflight(Invoice $invoice, Tenant $tenant): array
    {
        $errors = [];
        $buyer = $invoice->customer_snapshot ?? [];

        foreach (['name' => 'Firmenname', 'street' => 'Straße', 'zip' => 'PLZ', 'city' => 'Ort', 'country' => 'Land'] as $field => $label) {
            if (blank($tenant->{$field})) {
                $errors[] = "Mandant: {$label} fehlt.";
            }
        }

        if (blank($tenant->vat_id) && blank($tenant->tax_number)) {
            $errors[] = 'Mandant: USt-IdNr. oder Steuernummer fehlt.';
        }

        if ($invoice->tax_mode !== 'standard' && blank($tenant->vat_id)) {
            $errors[] = 'Mandant: USt-IdNr. ist für diese Steuerart erforderlich.';
        }

        if (blank($buyer['company'] ?? null)) {
            $errors[] = 'Kunde: Firmenname fehlt.';
        }

        foreach (['street' => 'Straße', 'zip' => 'PLZ', 'city' => 'Ort', 'country' => 'Land'] as $field => $label) {
            if (blank($buyer[$field] ?? null)) {
                $errors[] = "Kunde: {$label} fehlt.";
            }
        }

        if (in_array($invoice->tax_mode, ['reverse_charge', 'intra_community'], true) && blank($buyer['vat_id'] ?? null)) {
            $errors[] = 'Kunde: USt-IdNr. ist für diese Steuerart erforderlich.';
        }

        if ($invoice->currency !== null && ! preg_match('/^[A-Z]{3}$/', (string) $invoice->currency)) {
            $errors[] = 'Ungültige Währung.';
        }

        return $errors;
    }

    /** Reine E-Mail-Adresse aus einem Freitext wie "Email: info@firma.de" (oder null). */
    public static function extractEmail(?string $text): ?string
    {
        if ($text !== null && preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $m)) {
            return $m[0];
        }

        return null;
    }

    public function build(Invoice $invoice, Tenant $tenant): string
    {
        $invoice->loadMissing('items', 'originalInvoice');

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::NS_RSM, 'rsm:CrossIndustryInvoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', self::NS_RAM);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', self::NS_UDT);
        $this->dom->appendChild($root);

        $context = $this->add($root, 'rsm:ExchangedDocumentContext');
        $this->add($this->add($context, 'ram:GuidelineSpecifiedDocumentContextParameter'), 'ram:ID', self::GUIDELINE);

        $doc = $this->add($root, 'rsm:ExchangedDocument');
        $this->add($doc, 'ram:ID', $invoice->number);
        $this->add($doc, 'ram:TypeCode', $invoice->isCreditNote() ? '381' : '380');
        $this->date($this->add($doc, 'ram:IssueDateTime'), $invoice->issue_date, 'udt:DateTimeString');

        $transaction = $this->add($root, 'rsm:SupplyChainTradeTransaction');

        foreach ($invoice->items as $index => $item) {
            $this->line($transaction, $invoice, $item, $index + 1);
        }

        $this->agreement($transaction, $invoice, $tenant);
        $this->add($transaction, 'ram:ApplicableHeaderTradeDelivery');
        $this->settlement($transaction, $invoice, $tenant);

        return $this->dom->saveXML();
    }

    /** Prüft gegen das offizielle Schema. @return array<int, string> Fehlermeldungen (leer = gültig) */
    public static function validate(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument;
        $errors = [];

        if (! $dom->loadXML($xml)) {
            $errors[] = 'Die XML-Datei ist nicht wohlgeformt.';
        } elseif (! $dom->schemaValidate(resource_path('zugferd/xsd/FACTUR-X_EN16931.xsd'))) {
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message).' (Zeile '.$error->line.')';
            }
        } else {
            $errors = self::checkCalculation($dom);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $errors;
    }

    /**
     * Rechenregeln der EN 16931 (BR-CO-10, -11, -13, -14, -15, -17 sowie Steuergruppen-Basis),
     * unabhängig von der Berechnung im Programm aus der fertigen XML nachgerechnet.
     *
     * @return array<int, string>
     */
    public static function checkCalculation(DOMDocument $dom): array
    {
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('rsm', self::NS_RSM);
        $xp->registerNamespace('ram', self::NS_RAM);

        $num = fn (string $path, ?\DOMNode $ctx = null): float => (float) $xp->evaluate("number({$path})", $ctx);
        $sum = fn (string $path, ?\DOMNode $ctx = null): float => (float) $xp->evaluate("sum({$path})", $ctx);
        $same = fn (float $a, float $b): bool => abs($a - $b) < 0.005;

        $h = '//ram:ApplicableHeaderTradeSettlement';
        $m = $h.'/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
        $errors = [];

        $lines = $sum('//ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount');
        $lineTotal = $num($m.'/ram:LineTotalAmount');
        $allowances = $sum($h.'/ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="false"]/ram:ActualAmount');
        $charges = $sum($h.'/ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="true"]/ram:ActualAmount');
        $basis = $num($m.'/ram:TaxBasisTotalAmount');
        $tax = $num($m.'/ram:TaxTotalAmount');
        $grand = $num($m.'/ram:GrandTotalAmount');
        $due = $num($m.'/ram:DuePayableAmount');
        $allowanceTotal = $xp->query($m.'/ram:AllowanceTotalAmount')->length ? $num($m.'/ram:AllowanceTotalAmount') : 0.0;

        if (! $same($lines, $lineTotal)) {
            $errors[] = 'BR-CO-10: Summe der Positionen stimmt nicht mit der Gesamtsumme der Positionen überein.';
        }
        if (! $same($allowances, $allowanceTotal)) {
            $errors[] = 'BR-CO-11: Summe der Rabatte stimmt nicht.';
        }
        if (! $same($basis, $lineTotal - $allowanceTotal + $charges)) {
            $errors[] = 'BR-CO-13: Gesamtbetrag ohne Steuer stimmt nicht.';
        }
        if (! $same($tax, $sum($h.'/ram:ApplicableTradeTax/ram:CalculatedAmount'))) {
            $errors[] = 'BR-CO-14: Summe der Steuerbeträge stimmt nicht.';
        }
        if (! $same($grand, $basis + $tax)) {
            $errors[] = 'BR-CO-15: Gesamtbetrag mit Steuer stimmt nicht.';
        }
        if (! $same($due, $grand)) {
            $errors[] = 'BR-CO-16: Zahlbetrag stimmt nicht.';
        }

        foreach ($xp->query($h.'/ram:ApplicableTradeTax') as $group) {
            $groupBasis = $num('ram:BasisAmount', $group);
            $rate = $num('ram:RateApplicablePercent', $group);
            $category = (string) $xp->evaluate('string(ram:CategoryCode)', $group);

            if (! $same($num('ram:CalculatedAmount', $group), round($groupBasis * $rate / 100, 2))) {
                $errors[] = "BR-CO-17: Steuerbetrag der Gruppe {$category} {$rate} % stimmt nicht.";
            }

            $match = "[ram:ApplicableTradeTax/ram:CategoryCode=\"{$category}\"][ram:ApplicableTradeTax/ram:RateApplicablePercent={$rate}]";
            $expected = $sum('//ram:SpecifiedLineTradeSettlement'.$match.'/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount')
                - $sum($h.'/ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="false"][ram:CategoryTradeTax/ram:CategoryCode="'.$category.'"][ram:CategoryTradeTax/ram:RateApplicablePercent='.$rate.']/ram:ActualAmount')
                + $sum($h.'/ram:SpecifiedTradeAllowanceCharge[ram:ChargeIndicator/udt:Indicator="true"][ram:CategoryTradeTax/ram:CategoryCode="'.$category.'"][ram:CategoryTradeTax/ram:RateApplicablePercent='.$rate.']/ram:ActualAmount');

            if (! $same($groupBasis, $expected)) {
                $errors[] = "BR-S-08/BR-CO-18: Bemessungsgrundlage der Gruppe {$category} {$rate} % stimmt nicht mit den Positionen überein.";
            }

            if ($category === 'S' && $rate <= 0) {
                $errors[] = 'BR-S-05: Kategorie S verlangt einen Steuersatz größer 0.';
            }
            if (in_array($category, ['AE', 'K', 'G', 'Z'], true) && $rate != 0.0) {
                $errors[] = "Kategorie {$category} verlangt den Steuersatz 0.";
            }
        }

        return $errors;
    }

    /** Erzeugt und prüft; wirft eine RuntimeException mit verständlichem Text. */
    public function buildValidated(Invoice $invoice, Tenant $tenant): string
    {
        $problems = self::preflight($invoice, $tenant);

        if ($problems !== []) {
            throw new RuntimeException('E-Rechnung (ZUGFeRD) nicht möglich: '.implode(' ', $problems));
        }

        $xml = $this->build($invoice, $tenant);
        $errors = self::validate($xml);

        if ($errors !== []) {
            throw new RuntimeException('Die erzeugte ZUGFeRD-XML ist nicht schemakonform: '.implode(' | ', array_slice($errors, 0, 3)));
        }

        return $xml;
    }

    // --- Positionen ---------------------------------------------------------------------------

    private function line(DOMElement $parent, Invoice $invoice, $item, int $number): void
    {
        $line = $this->add($parent, 'ram:IncludedSupplyChainTradeLineItem');
        $this->add($this->add($line, 'ram:AssociatedDocumentLineDocument'), 'ram:LineID', (string) $number);

        $text = preg_split('/\R/', trim((string) $item->description), 2);
        $product = $this->add($line, 'ram:SpecifiedTradeProduct');
        $this->add($product, 'ram:Name', mb_substr(trim($text[0]) !== '' ? trim($text[0]) : '-', 0, 250));
        if (isset($text[1]) && trim($text[1]) !== '') {
            $this->add($product, 'ram:Description', trim($text[1]));
        }

        $price = (float) $item->unit_price;
        $discount = (float) $item->discount_percent;
        $agreement = $this->add($line, 'ram:SpecifiedLineTradeAgreement');

        if ($discount > 0) {
            $perUnit = round($price * $discount / 100, 4);
            $gross = $this->add($agreement, 'ram:GrossPriceProductTradePrice');
            $this->add($gross, 'ram:ChargeAmount', $this->price($price));
            $allowance = $this->add($gross, 'ram:AppliedTradeAllowanceCharge');
            $this->add($this->add($allowance, 'ram:ChargeIndicator'), 'udt:Indicator', 'false');
            $this->add($allowance, 'ram:ActualAmount', $this->price($perUnit));
            $net = $price - $perUnit;
        } else {
            $net = $price;
        }
        $this->add($this->add($agreement, 'ram:NetPriceProductTradePrice'), 'ram:ChargeAmount', $this->price($net));

        $delivery = $this->add($line, 'ram:SpecifiedLineTradeDelivery');
        $quantity = $this->add($delivery, 'ram:BilledQuantity', $this->quantity((float) $item->quantity));
        $quantity->setAttribute('unitCode', $this->unitCode($item->unit));

        $settlement = $this->add($line, 'ram:SpecifiedLineTradeSettlement');
        $rate = $invoice->tax_mode === 'standard' ? (float) $item->tax_rate : 0.0;
        $tax = $this->add($settlement, 'ram:ApplicableTradeTax');
        $this->add($tax, 'ram:TypeCode', 'VAT');
        $this->add($tax, 'ram:CategoryCode', $this->category($invoice->tax_mode, $rate));
        $this->add($tax, 'ram:RateApplicablePercent', $this->money($rate));
        $this->add($this->add($settlement, 'ram:SpecifiedTradeSettlementLineMonetarySummation'), 'ram:LineTotalAmount', $this->money((float) $item->net_amount));
    }

    // --- Vertragsdaten ------------------------------------------------------------------------

    private function agreement(DOMElement $parent, Invoice $invoice, Tenant $tenant): void
    {
        $agreement = $this->add($parent, 'ram:ApplicableHeaderTradeAgreement');

        if (filled($invoice->buyer_reference)) {
            $this->add($agreement, 'ram:BuyerReference', $invoice->buyer_reference);
        }

        $seller = $this->add($agreement, 'ram:SellerTradeParty');
        $this->add($seller, 'ram:Name', $tenant->name);
        $this->address($seller, $tenant->street, $tenant->zip, $tenant->city, $tenant->country);

        if ($email = self::extractEmail($tenant->email)) {
            $this->uri($seller, $email);
        }

        if (filled($tenant->vat_id)) {
            $this->taxRegistration($seller, 'VA', $tenant->vat_id);
        }
        if (filled($tenant->tax_number)) {
            $this->taxRegistration($seller, 'FC', $tenant->tax_number);
        }

        $buyerData = $invoice->customer_snapshot ?? [];
        $buyer = $this->add($agreement, 'ram:BuyerTradeParty');
        $this->add($buyer, 'ram:Name', $buyerData['company'] ?? '');
        $this->address($buyer, $buyerData['street'] ?? null, $buyerData['zip'] ?? null, $buyerData['city'] ?? null, $buyerData['country'] ?? null);

        if ($email = self::extractEmail($buyerData['email'] ?? null)) {
            $this->uri($buyer, $email);
        }
        if (filled($buyerData['vat_id'] ?? null)) {
            $this->taxRegistration($buyer, 'VA', $buyerData['vat_id']);
        }

        if (filled($invoice->customer_order_ref)) {
            $this->add($this->add($agreement, 'ram:BuyerOrderReferencedDocument'), 'ram:IssuerAssignedID', $invoice->customer_order_ref);
        }
    }

    // --- Zahlung und Summen -------------------------------------------------------------------

    private function settlement(DOMElement $parent, Invoice $invoice, Tenant $tenant): void
    {
        $settlement = $this->add($parent, 'ram:ApplicableHeaderTradeSettlement');
        $this->add($settlement, 'ram:InvoiceCurrencyCode', $invoice->currency ?: 'EUR');

        if (! $invoice->isCreditNote() && filled($tenant->iban)) {
            $means = $this->add($settlement, 'ram:SpecifiedTradeSettlementPaymentMeans');
            $this->add($means, 'ram:TypeCode', '58'); // SEPA-Überweisung
            $this->add($this->add($means, 'ram:PayeePartyCreditorFinancialAccount'), 'ram:IBANID', preg_replace('/\s+/', '', $tenant->iban));
            if (filled($tenant->bic)) {
                $this->add($this->add($means, 'ram:PayeeSpecifiedCreditorFinancialInstitution'), 'ram:BICID', preg_replace('/\s+/', '', $tenant->bic));
            }
        }

        // Steueraufschlüsselung (nach Gesamtrabatt) und Rabatt je Steuergruppe
        $before = [];
        foreach ($invoice->items as $item) {
            $rate = $invoice->tax_mode === 'standard' ? round((float) $item->tax_rate, 2) : 0.0;
            $key = number_format($rate, 2, '.', '');
            $before[$key] = ($before[$key] ?? 0.0) + (float) $item->net_amount;
        }

        foreach ($invoice->tax_breakdown ?? [] as $group) {
            $rate = (float) $group['rate'];
            $category = $this->category($invoice->tax_mode, $rate);
            $tax = $this->add($settlement, 'ram:ApplicableTradeTax');
            $this->add($tax, 'ram:CalculatedAmount', $this->money((float) $group['tax']));
            $this->add($tax, 'ram:TypeCode', 'VAT');

            if ($reason = $this->exemption($category)) {
                $this->add($tax, 'ram:ExemptionReason', $reason[1]);
            }

            $this->add($tax, 'ram:BasisAmount', $this->money((float) $group['net']));
            $this->add($tax, 'ram:CategoryCode', $category);

            if ($reason) {
                $this->add($tax, 'ram:ExemptionReasonCode', $reason[0]);
            }

            $this->add($tax, 'ram:RateApplicablePercent', $this->money($rate));
        }

        foreach ($invoice->tax_breakdown ?? [] as $group) {
            $key = number_format((float) $group['rate'], 2, '.', '');
            $amount = round(($before[$key] ?? 0.0) - (float) $group['net'], 2);

            if ($amount <= 0) {
                continue;
            }

            $allowance = $this->add($settlement, 'ram:SpecifiedTradeAllowanceCharge');
            $this->add($this->add($allowance, 'ram:ChargeIndicator'), 'udt:Indicator', 'false');
            $this->add($allowance, 'ram:ActualAmount', $this->money($amount));
            $this->add($allowance, 'ram:ReasonCode', '95');
            $this->add($allowance, 'ram:Reason', 'Rabatt');
            $tax = $this->add($allowance, 'ram:CategoryTradeTax');
            $this->add($tax, 'ram:TypeCode', 'VAT');
            $this->add($tax, 'ram:CategoryCode', $this->category($invoice->tax_mode, (float) $group['rate']));
            $this->add($tax, 'ram:RateApplicablePercent', $this->money((float) $group['rate']));
        }

        if (! $invoice->isCreditNote()) {
            $terms = $this->add($settlement, 'ram:SpecifiedTradePaymentTerms');
            $this->add($terms, 'ram:Description', $this->paymentText($invoice));
            if ($invoice->due_date) {
                $this->date($this->add($terms, 'ram:DueDateDateTime'), $invoice->due_date, 'udt:DateTimeString');
            }
        }

        $sum = $this->add($settlement, 'ram:SpecifiedTradeSettlementHeaderMonetarySummation');
        $net = (float) $invoice->net_total;
        $discount = (float) $invoice->discount_total;
        $tax = (float) $invoice->tax_total;
        $gross = (float) $invoice->gross_total;
        $currency = $invoice->currency ?: 'EUR';

        $this->add($sum, 'ram:LineTotalAmount', $this->money(round($net + $discount, 2)));
        if ($discount > 0) {
            $this->add($sum, 'ram:AllowanceTotalAmount', $this->money($discount));
        }
        $this->add($sum, 'ram:TaxBasisTotalAmount', $this->money($net));
        $this->add($sum, 'ram:TaxTotalAmount', $this->money($tax))->setAttribute('currencyID', $currency);
        $this->add($sum, 'ram:GrandTotalAmount', $this->money($gross));
        $this->add($sum, 'ram:DuePayableAmount', $this->money($gross));

        if ($invoice->isCreditNote() && $invoice->originalInvoice) {
            $reference = $this->add($settlement, 'ram:InvoiceReferencedDocument');
            $this->add($reference, 'ram:IssuerAssignedID', $invoice->originalInvoice->number);
            $this->date($this->add($reference, 'ram:FormattedIssueDateTime'), $invoice->originalInvoice->issue_date, 'qdt:DateTimeString');
        }
    }

    /** Zahlungsbedingungen; Skonto zusätzlich maschinenlesbar (#SKONTO#TAGE=…#PROZENT=…#). */
    private function paymentText(Invoice $invoice): string
    {
        $days = (int) ($invoice->payment_term_days ?? 0);
        $lines = [$days > 0 ? "Zahlbar innerhalb von {$days} Tagen ohne Abzug." : 'Zahlbar sofort ohne Abzug.'];

        foreach ([1, 2] as $level) {
            $percent = $invoice->{"skonto{$level}_percent"};
            $skontoDays = $invoice->{"skonto{$level}_days"};

            if ($percent !== null && (float) $percent > 0 && $skontoDays !== null) {
                $lines[] = '#SKONTO#TAGE='.(int) $skontoDays.'#PROZENT='.number_format((float) $percent, 2, '.', '').'#';
            }
        }

        return implode("\n", $lines);
    }

    // --- Hilfsfunktionen ----------------------------------------------------------------------

    private function category(string $taxMode, float $rate): string
    {
        return match ($taxMode) {
            'reverse_charge' => 'AE',
            'intra_community' => 'K',
            'export' => 'G',
            default => $rate > 0 ? 'S' : 'Z',
        };
    }

    /** @return array{0: string, 1: string}|null  Code und Text der Steuerbefreiung */
    private function exemption(string $category): ?array
    {
        return match ($category) {
            'AE' => ['VATEX-EU-AE', 'Steuerschuldnerschaft des Leistungsempfängers (Reverse Charge)'],
            'K' => ['VATEX-EU-IC', 'Steuerfreie innergemeinschaftliche Lieferung'],
            'G' => ['VATEX-EU-G', 'Steuerfreie Ausfuhrlieferung'],
            default => null,
        };
    }

    private function unitCode(?string $unit): string
    {
        $key = mb_strtolower(trim((string) $unit));

        if (preg_match('/^[A-Z0-9]{2,3}$/', trim((string) $unit)) && ! isset(self::UNITS[$key])) {
            return trim((string) $unit); // bereits ein UN/ECE-Code
        }

        return self::UNITS[$key] ?? 'C62';
    }

    private function address(DOMElement $party, ?string $street, ?string $zip, ?string $city, ?string $country): void
    {
        $address = $this->add($party, 'ram:PostalTradeAddress');
        $this->add($address, 'ram:PostcodeCode', $zip);
        $this->add($address, 'ram:LineOne', $street);
        $this->add($address, 'ram:CityName', $city);
        $this->add($address, 'ram:CountryID', strtoupper((string) $country));
    }

    private function uri(DOMElement $party, string $email): void
    {
        $uri = $this->add($this->add($party, 'ram:URIUniversalCommunication'), 'ram:URIID', $email);
        $uri->setAttribute('schemeID', 'EM');
    }

    private function taxRegistration(DOMElement $party, string $scheme, string $id): void
    {
        $this->add($this->add($party, 'ram:SpecifiedTaxRegistration'), 'ram:ID', preg_replace('/\s+/', '', $id))->setAttribute('schemeID', $scheme);
    }

    private function date(DOMElement $parent, $date, string $element): void
    {
        $this->add($parent, $element, $date->format('Ymd'))->setAttribute('format', '102');
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }

    private function price(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return str_contains($text, '.') ? str_pad($text, strpos($text, '.') + 3, '0') : $text.'.00';
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function add(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $namespaces = ['rsm' => self::NS_RSM, 'ram' => self::NS_RAM, 'udt' => self::NS_UDT,
            'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100'];
        [$prefix] = explode(':', $name);

        $element = $value === null
            ? $this->dom->createElementNS($namespaces[$prefix], $name)
            : $this->dom->createElementNS($namespaces[$prefix], $name, htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        $parent->appendChild($element);

        return $element;
    }
}
