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

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\LayoutVersion;
use App\Models\Tenant;
use App\Services\Layout\DocumentModel;
use App\Services\Layout\LayoutService;
use App\Support\Locale;
use App\Support\LogoImage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Erzeugt das Beleg-PDF. Belege sind immer deutsch.
 * Verwendet das aktive Layout des Mandanten (siehe Layout-Verwaltung) oder das eingebaute Standard-Layout.
 */
class InvoicePdf
{
    /** Freigegebene Belege kommen unverändert aus dem Archiv, alles andere wird erzeugt. */
    public function archivedOrRender(Invoice $invoice): string
    {
        if (! $invoice->isDraft() && $invoice->pdf_path && Storage::disk('local')->exists($invoice->pdf_path)) {
            return Storage::disk('local')->get($invoice->pdf_path);
        }

        return $this->render($invoice);
    }

    /**
     * Ersetzt das archivierte PDF eines freigegebenen Belegs durch eine neu erzeugte Fassung. Die Belegdaten bleiben
     * unverändert; die bisherige Datei wird unter invoices/{Mandant}/replaced/ aufbewahrt, die Aktion im Protokoll vermerkt.
     */
    public function rebuildArchive(Invoice $invoice): void
    {
        if ($invoice->isDraft() || ! $invoice->pdf_path) {
            throw new \RuntimeException('Nur freigegebene Belege haben ein Archiv-PDF.');
        }

        $disk = Storage::disk('local');
        $new = $this->render($invoice);
        $copy = null;
        $oldHash = null;

        if ($disk->exists($invoice->pdf_path)) {
            $old = $disk->get($invoice->pdf_path);
            $oldHash = hash('sha256', $old);
            $copy = 'invoices/'.$invoice->tenant_id.'/replaced/'
                .preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number).'-'.now()->format('Ymd-His').'.pdf';
            $disk->put($copy, $old);
        }

        $disk->put($invoice->pdf_path, $new);

        AuditLog::record('invoice.pdf_rebuilt', $invoice, [
            'number' => $invoice->number,
            'old_sha256' => $oldHash,
            'new_sha256' => hash('sha256', $new),
            'old_copy' => $copy,
        ]);
    }

    public function render(Invoice $invoice): string
    {
        return Locale::with('de', function () use ($invoice) {
            $invoice->loadMissing('items', 'originalInvoice');
            $tenant = Tenant::findOrFail($invoice->tenant_id);
            $data = $this->data($invoice, $tenant);

            // Freigegebene echte Belege werden als PDF/A-3 mit eingebetteter ZUGFeRD-XML (EN 16931) erzeugt.
            $xml = $this->wantsXml($invoice) ? (new ZugferdXml)->buildValidated($invoice, $tenant) : null;

            $layout = LayoutVersion::activeFor($tenant->id);

            if ($layout) {
                $model = DocumentModel::fromInvoice($invoice, $tenant, $data['logo'], $data['qr'], $data['skonto'], $data['series']);
                [$html, $footer] = app(LayoutService::class)->render($layout, $model);
                $margins = $layout->margins();
            } else {
                $html = view('pdf.invoice', $data)->render();
                $footer = view('pdf.footer', ['tenant' => $tenant])->render();
                $margins = ['top' => 20, 'right' => 20, 'bottom' => 32, 'left' => 20];
            }

            $watermark = $invoice->is_test ? 'TEST' : ($invoice->isDraft() ? 'ENTWURF' : null);

            return $this->build($html, $footer, $margins, $xml, $watermark,
                $invoice->documentTitle().' '.$invoice->displayNumber(), $tenant);
        });
    }

    /**
     * Einfaches Schreiben (z. B. Mahnung) im Briefkopf-Stil der Belege, ohne E-Rechnungsdaten.
     *
     * @param  array{top: int, right: int, bottom: int, left: int}|null  $margins
     */
    public function renderLetter(string $html, string $footer, string $title, Tenant $tenant, ?array $margins = null, ?string $watermark = null): string
    {
        return $this->build($html, $footer, $margins ?? LayoutService::DEFAULT_MARGINS, null, $watermark, $title, $tenant);
    }

    /** Vorschau eines Layouts (Rechnung oder Mahnung) mit einem Musterbeleg (Wasserzeichen „MUSTER“). */
    public function renderSample(array $parts, Tenant $tenant, string $kind = LayoutVersion::KIND_INVOICE): string
    {
        return Locale::with('de', function () use ($parts, $tenant, $kind) {
            $logo = $this->logoDataUri($tenant);
            $qr = $tenant->iban
                ? EpcQr::svgDataUri(EpcQr::payload($tenant->name, $tenant->iban, $tenant->bic, 1, 'Rechnung R-2026-0001'))
                : null;
            $model = DocumentModel::sampleFor($kind, $tenant, $logo, $qr);
            [$html, $footer] = app(LayoutService::class)->renderParts($parts['body_html'], $parts['footer_html'] ?? null, $model, $tenant);

            return $this->build($html, $footer, $parts['margins'], null, 'MUSTER', 'Layout-Vorschau', $tenant);
        });
    }

    /** @param  array{top: int, right: int, bottom: int, left: int}  $margins */
    private function build(string $html, string $footer, array $margins, ?string $xml, ?string $watermark, string $title, Tenant $tenant): string
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => $margins['left'],
            'margin_right' => $margins['right'],
            'margin_top' => $margins['top'],
            'margin_bottom' => $margins['bottom'],
            'margin_footer' => 8,
            'default_font' => 'dejavusans',
            'default_font_size' => 9,
            'tempDir' => $tempDir,
        ] + ($xml !== null ? ['PDFA' => true, 'PDFAauto' => true, 'PDFAversion' => '3-B'] : []));

        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($tenant->name);
        $mpdf->SetCreator(config('app.name'));

        if ($watermark !== null) {
            $mpdf->SetWatermarkText($watermark, 0.08);
            $mpdf->showWatermarkText = true;
        }

        if ($xml !== null) {
            $mpdf->SetAssociatedFiles([[
                'name' => 'factur-x.xml',
                'mime' => 'text/xml',
                'description' => 'ZUGFeRD/Factur-X Rechnungsdaten (EN 16931)',
                'AFRelationship' => 'Alternative',
                'content' => $xml,
            ]]);
            $mpdf->SetAdditionalXmpRdf(self::facturXmpRdf());
        }

        $mpdf->SetHTMLFooter($footer);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /** E-Rechnungsdaten (XML) nur für freigegebene, echte Belege. */
    private function wantsXml(Invoice $invoice): bool
    {
        return ! $invoice->isDraft() && ! $invoice->is_test;
    }

    /** XMP-Erweiterung, die das PDF als Factur-X/ZUGFeRD (Profil EN 16931) kennzeichnet. */
    public static function facturXmpRdf(): string
    {
        $property = fn (string $name, string $description) => '<rdf:li rdf:parseType="Resource">'
            .'<pdfaProperty:name>'.$name.'</pdfaProperty:name>'
            .'<pdfaProperty:valueType>Text</pdfaProperty:valueType>'
            .'<pdfaProperty:category>external</pdfaProperty:category>'
            .'<pdfaProperty:description>'.$description.'</pdfaProperty:description></rdf:li>';

        return '   <rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/" xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">'."\n"
            .'    <pdfaExtension:schemas><rdf:Bag><rdf:li rdf:parseType="Resource">'
            .'<pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>'
            .'<pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>'
            .'<pdfaSchema:prefix>fx</pdfaSchema:prefix>'
            .'<pdfaSchema:property><rdf:Seq>'
            .$property('DocumentFileName', 'name of the embedded XML invoice file')
            .$property('DocumentType', 'INVOICE')
            .$property('Version', 'The actual version of the Factur-X XML schema')
            .$property('ConformanceLevel', 'The conformance level of the embedded Factur-X data')
            .'</rdf:Seq></pdfaSchema:property></rdf:li></rdf:Bag></pdfaExtension:schemas>'."\n"
            .'   </rdf:Description>'."\n"
            .'   <rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">'."\n"
            .'    <fx:DocumentType>INVOICE</fx:DocumentType>'."\n"
            .'    <fx:DocumentFileName>factur-x.xml</fx:DocumentFileName>'."\n"
            .'    <fx:Version>1.0</fx:Version>'."\n"
            .'    <fx:ConformanceLevel>EN 16931</fx:ConformanceLevel>'."\n"
            .'   </rdf:Description>'."\n";
    }

    /** @return array<string, mixed> */
    private function data(Invoice $invoice, Tenant $tenant): array
    {
        $snapshot = $invoice->customer_snapshot ?? [];

        return [
            'invoice' => $invoice,
            'tenant' => $tenant,
            'customer' => $snapshot,
            'items' => $invoice->items,
            'logo' => $this->logoDataUri($tenant),
            'qr' => $this->qr($invoice, $tenant),
            'skonto' => $this->skontoLines($invoice),
            'series' => InvoiceSeries::summary($invoice),
        ];
    }

    public function logoDataUri(Tenant $tenant): ?string
    {
        if (! $tenant->logo_path || ! Storage::disk('local')->exists($tenant->logo_path)) {
            return null;
        }

        $mime = str_ends_with(strtolower($tenant->logo_path), '.png') ? 'image/png' : 'image/jpeg';

        return LogoImage::dataUri(Storage::disk('local')->get($tenant->logo_path), $mime);
    }

    /** GiroCode nur für Rechnungen in EUR mit hinterlegter IBAN. */
    private function qr(Invoice $invoice, Tenant $tenant): ?string
    {
        if ($invoice->isCreditNote() || $invoice->currency !== 'EUR' || ! $tenant->iban || (float) $invoice->gross_total <= 0) {
            return null;
        }

        $payload = EpcQr::payload($tenant->name, $tenant->iban, $tenant->bic, (float) $invoice->gross_total,
            __('messages.invoices.invoice').' '.$invoice->displayNumber());

        return EpcQr::svgDataUri($payload);
    }

    /** @return array<int, string> */
    private function skontoLines(Invoice $invoice): array
    {
        if ($invoice->isCreditNote()) {
            return [];
        }

        $lines = [];
        $currency = $invoice->currency;

        foreach ([1, 2] as $level) {
            $percent = $invoice->{"skonto{$level}_percent"};
            $days = $invoice->{"skonto{$level}_days"};

            if ($percent === null || (float) $percent <= 0 || $days === null) {
                continue;
            }

            $date = $invoice->issue_date->copy()->addDays($days);
            $amount = round((float) $invoice->gross_total * (1 - (float) $percent / 100), 2);

            $lines[] = __('messages.pdf.skonto_line', [
                'days' => $days,
                'date' => $date->format('d.m.Y'),
                'percent' => number_format((float) $percent, 2, ',', '.'),
                'amount' => number_format($amount, 2, ',', '.').' '.$currency,
            ]);
        }

        return $lines;
    }
}
