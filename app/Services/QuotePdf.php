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

use App\Models\LayoutVersion;
use App\Models\Quote;
use App\Models\Tenant;
use App\Services\Layout\DocumentModel;
use App\Services\Layout\LayoutService;
use App\Support\Locale;

/**
 * Erzeugt das Angebots-PDF (immer deutsch). Es wird bei jedem Abruf frisch aus den aktuellen Angebotsdaten erzeugt
 * (kein Archiv wie bei Rechnungen). Verwendet das aktive Angebots-Layout oder die eingebaute Vorlage.
 */
class QuotePdf
{
    public function __construct(private InvoicePdf $pdf) {}

    public function render(Quote $quote): string
    {
        return Locale::with('de', function () use ($quote) {
            $tenant = Tenant::findOrFail($quote->tenant_id);
            $model = DocumentModel::fromQuote($quote, $tenant, $this->pdf->logoDataUri($tenant));
            $layout = LayoutVersion::activeFor($tenant->id, LayoutVersion::KIND_QUOTE);

            if ($layout) {
                [$html, $footer] = app(LayoutService::class)->render($layout, $model);
                $margins = $layout->margins();
            } else {
                [$body, $footerTemplate] = LayoutService::standardTemplate(LayoutVersion::KIND_QUOTE);
                [$html, $footer] = app(LayoutService::class)->renderParts($body, $footerTemplate, $model, $tenant);
                $margins = LayoutService::DEFAULT_MARGINS;
            }

            $watermark = $quote->is_test ? 'TEST' : ($quote->isDraft() ? 'ENTWURF' : null);

            return $this->pdf->renderLetter($html, $footer, __('messages.quotes.title_single').' '.$quote->displayNumber(), $tenant, $margins, $watermark);
        });
    }
}
