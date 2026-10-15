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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('customer_since')->nullable()->after('customer_number');
            $table->date('customer_until')->nullable()->after('customer_since');
            $table->boolean('active')->default(true)->after('customer_until');
            $table->string('tags', 30)->nullable()->after('active');
        });

        // Bestehende Kunden (auch gelöschte) ohne Datum gelten ab dem 01.01.2026 als Kunde.
        DB::table('customers')->whereNull('customer_since')->update(['customer_since' => '2026-01-01']);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['customer_since', 'customer_until', 'active', 'tags']);
        });
    }
};
