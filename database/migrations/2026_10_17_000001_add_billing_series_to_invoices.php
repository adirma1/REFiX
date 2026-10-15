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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // normal = gewöhnliche Rechnung, partial = Teilrechnung, final = Schlussrechnung
            $table->string('billing_kind', 10)->default('normal')->after('type');
            // Wurzel der Serie: die erste Teilrechnung (bei ihr selbst leer), alle weiteren zeigen auf sie
            $table->unsignedBigInteger('series_invoice_id')->nullable()->index()->after('original_invoice_id');
            // laufende Nummer in der Serie, wird bei der Freigabe vergeben
            $table->unsignedSmallInteger('series_no')->nullable()->after('series_invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['series_invoice_id']);
            $table->dropColumn(['billing_kind', 'series_invoice_id', 'series_no']);
        });
    }
};
