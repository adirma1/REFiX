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
            $table->string('invoice_number_format', 60)->default('R-{YYYY}-{NNNN}');
            $table->string('credit_note_number_format', 60)->default('G-{YYYY}-{NNNN}');
            $table->string('logo_path')->nullable();
            $table->text('invoice_intro')->nullable();
            $table->text('invoice_footer')->nullable();
        });

        // Laufende Nummern je Mandant, Belegart, Test/Echt und Zeitraum (z. B. Jahr).
        Schema::create('number_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12);
            $table->boolean('is_test')->default(false);
            $table->string('period', 10)->default('');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'type', 'is_test', 'period']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 12)->default('invoice');      // invoice | credit_note
            $table->string('status', 12)->default('draft');     // draft | issued
            $table->boolean('is_test')->default(false);
            $table->string('number', 60)->nullable();
            $table->foreignId('customer_id')->constrained();
            $table->json('customer_snapshot')->nullable();
            $table->unsignedBigInteger('original_invoice_id')->nullable()->index();

            $table->date('issue_date');
            $table->date('service_date_from')->nullable();
            $table->date('service_date_to')->nullable();
            $table->date('due_date')->nullable();

            $table->string('currency', 3)->default('EUR');
            $table->string('tax_mode', 20)->default('standard'); // standard | reverse_charge | intra_community | export
            $table->unsignedSmallInteger('payment_term_days')->nullable();
            $table->decimal('skonto1_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('skonto1_days')->nullable();
            $table->decimal('skonto2_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('skonto2_days')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);

            $table->string('buyer_reference')->nullable();
            $table->string('customer_order_ref')->nullable();
            $table->text('intro_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('internal_notes')->nullable();

            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->json('tax_breakdown')->nullable();

            $table->string('pdf_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'type']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(19);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('number_counters');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['invoice_number_format', 'credit_note_number_format', 'logo_path', 'invoice_intro', 'invoice_footer']);
        });
    }
};
