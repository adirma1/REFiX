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

use App\Models\Dunning;
use App\Models\LayoutVersion;
use App\Models\Tenant;
use App\Services\Layout\DocumentModel;
use App\Services\Layout\LayoutService;
use App\Support\Locale;

/** Erzeugt das Mahnschreiben (immer deutsch) im Briefkopf-Stil der Belege. */
class DunningPdf
{
    public function __construct(private InvoicePdf $pdf) {}

    public function render(Dunning $dunning, Tenant $tenant): string
    {
        return Locale::with('de', function () use ($dunning, $tenant) {
            $invoice = $dunning->invoice;
            $service = app(DunningService::class);

            $qr = null;
            if ($dunning->currency === 'EUR' && $tenant->iban && (float) $dunning->total > 0) {
                $qr = EpcQr::svgDataUri(EpcQr::payload($tenant->name, $tenant->iban, $tenant->bic, (float) $dunning->total,
                    __('messages.invoices.invoice').' '.$invoice->number));
            }

            $logo = $this->pdf->logoDataUri($tenant);
            $text = $service->letterText($dunning, $tenant);
            $title = $dunning->label().' '.$invoice->number;
            $layout = LayoutVersion::activeFor($tenant->id, LayoutVersion::KIND_DUNNING);

            // Eigenes, aktives Mahn-Layout aus der Layoutverwaltung; sonst das eingebaute Layout.
            if ($layout) {
                $model = DocumentModel::fromDunning($dunning, $invoice, $tenant, $text, $logo, $qr);
                [$html, $footer] = app(LayoutService::class)->render($layout, $model);

                return $this->pdf->renderLetter($html, $footer, $title, $tenant, $layout->margins());
            }

            $html = view('pdf.dunning', [
                'dunning' => $dunning,
                'invoice' => $invoice,
                'tenant' => $tenant,
                'customer' => $invoice->customer_snapshot ?? [],
                'logo' => $logo,
                'qr' => $qr,
                'text' => $text,
            ])->render();

            return $this->pdf->renderLetter($html, view('pdf.footer', ['tenant' => $tenant])->render(), $title, $tenant);
        });
    }
}
