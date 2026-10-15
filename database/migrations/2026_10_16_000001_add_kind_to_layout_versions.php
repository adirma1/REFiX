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
        Schema::table('layout_versions', function (Blueprint $table) {
            // Art des Layouts: invoice (Rechnungen/Gutschriften) oder dunning (Zahlungserinnerung/Mahnung)
            $table->string('kind', 20)->default('invoice')->after('tenant_id');
        });

        // Erst den neuen Index anlegen (er beginnt mit tenant_id und stützt den Fremdschlüssel), dann den alten entfernen.
        Schema::table('layout_versions', function (Blueprint $table) {
            $table->unique(['tenant_id', 'kind', 'version'], 'layout_versions_tenant_kind_version_unique');
        });

        Schema::table('layout_versions', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'version']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->text('layout_wishes_dunning')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('layout_wishes_dunning');
        });

        Schema::table('layout_versions', function (Blueprint $table) {
            $table->unique(['tenant_id', 'version']);
        });

        Schema::table('layout_versions', function (Blueprint $table) {
            $table->dropUnique('layout_versions_tenant_kind_version_unique');
        });

        Schema::table('layout_versions', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
