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
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('draft'); // draft | open | accepted | declined
            $table->boolean('is_test')->default(false);
            $table->string('number', 60)->nullable();
            $table->foreignId('customer_id')->constrained();
            $table->json('customer_snapshot')->nullable();

            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('tax_mode', 20)->default('standard');
            $table->decimal('discount_percent', 5, 2)->default(0);

            $table->string('buyer_reference')->nullable();
            $table->text('intro_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('internal_notes')->nullable();

            $table->decimal('net_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->json('tax_breakdown')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(19);
            $table->decimal('net_amount', 14, 2)->default(0);
            // Rechnung (Entwurf oder freigegeben), in die diese Position übernommen wurde
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_id')->nullable()->index()->after('series_no');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->string('quote_number_format', 60)->default('A-{YYYY}-{NNNN}');
            $table->unsignedSmallInteger('quote_valid_days')->default(30);
            $table->string('mail_quote_subject', 255)->nullable();
            $table->text('mail_quote_body')->nullable();
            $table->text('layout_wishes_quote')->nullable();
        });

        Schema::table('mail_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_logs', fn (Blueprint $table) => $table->dropColumn('quote_id'));

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['quote_number_format', 'quote_valid_days', 'mail_quote_subject', 'mail_quote_body', 'layout_wishes_quote']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['quote_id']);
            $table->dropColumn('quote_id');
        });

        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
    }
};
