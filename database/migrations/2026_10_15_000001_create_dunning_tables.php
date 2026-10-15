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
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedSmallInteger('dunning_days_after_due')->default(7);     // Tage nach Fälligkeit bis zur Zahlungserinnerung
            $table->unsignedSmallInteger('dunning_grace_days')->default(7);         // Zahlungsfrist in jedem Schreiben
            $table->decimal('dunning_fee_1', 8, 2)->default(0);                     // Gebühr Zahlungserinnerung
            $table->decimal('dunning_fee_2', 8, 2)->default(0);                     // Gebühr 1. Mahnung
            $table->decimal('dunning_fee_3', 8, 2)->default(0);                     // Gebühr 2. Mahnung
            $table->boolean('dunning_interest_enabled')->default(false);
            $table->decimal('dunning_base_rate', 5, 2)->nullable();                 // Basiszinssatz in % (vom Admin gepflegt)
            $table->decimal('dunning_interest_margin', 5, 2)->default(9);           // Aufschlag: 9 Punkte (Geschäftskunden)
            $table->text('dunning_text_1')->nullable();
            $table->text('dunning_text_2')->nullable();
            $table->text('dunning_text_3')->nullable();
        });

        Schema::create('dunnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');                                   // 1 Erinnerung, 2 Mahnung, 3 letzte Mahnung
            $table->date('dunned_on');
            $table->date('pay_by');
            $table->decimal('open_amount', 12, 2);                                  // offener Rechnungsbetrag zum Zeitpunkt
            $table->decimal('fee', 8, 2)->default(0);
            $table->decimal('interest', 10, 2)->default(0);
            $table->decimal('interest_rate', 5, 2)->nullable();
            $table->unsignedInteger('interest_days')->default(0);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('pdf_path', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_via', 10)->nullable();                             // mail | post
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::create('dunning_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 255);
            $table->date('hold_until')->nullable();                                 // leer = bis zur Freigabe
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });

        Schema::table('mail_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('dunning_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_logs', fn (Blueprint $table) => $table->dropColumn('dunning_id'));
        Schema::dropIfExists('dunning_holds');
        Schema::dropIfExists('dunnings');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'dunning_days_after_due', 'dunning_grace_days', 'dunning_fee_1', 'dunning_fee_2', 'dunning_fee_3',
                'dunning_interest_enabled', 'dunning_base_rate', 'dunning_interest_margin',
                'dunning_text_1', 'dunning_text_2', 'dunning_text_3',
            ]);
        });
    }
};
