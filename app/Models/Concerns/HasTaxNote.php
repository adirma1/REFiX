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

namespace App\Models\Concerns;

/** Steuerhinweis eines Belegs (Rechnung, Angebot): leer beim Normalfall, sonst Text zum Steuerfall. */
trait HasTaxNote
{
    public function taxNoteText(): string
    {
        if ($this->tax_mode === 'standard') {
            return '';
        }

        // „Ohne Umsatzsteuer“: der beim Anlegen festgehaltene Text des Mandanten, sonst ein Standardtext.
        if ($this->tax_mode === 'exempt' && filled($this->tax_note)) {
            return (string) $this->tax_note;
        }

        return __('messages.pdf.tax_note.'.$this->tax_mode);
    }
}
