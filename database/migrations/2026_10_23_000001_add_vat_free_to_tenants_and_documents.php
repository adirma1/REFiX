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
        // Mandant ohne Umsatzsteuer (z. B. Verein, Kleinunternehmer): Schalter und Hinweistext für Belege.
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('vat_free')->default(false);
            $table->string('vat_free_note', 500)->nullable();
        });

        // Der Hinweistext wird beim Beleg festgehalten, damit freigegebene Belege unverändert bleiben.
        foreach (['invoices', 'quotes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('tax_note', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['vat_free', 'vat_free_note']);
        });

        foreach (['invoices', 'quotes'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('tax_note');
            });
        }
    }
};
