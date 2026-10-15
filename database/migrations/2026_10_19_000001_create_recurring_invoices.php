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
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('customer_id')->constrained();
            $table->boolean('active')->default(false);
            $table->string('interval', 12)->default('monthly');  // monthly | quarterly | yearly
            $table->string('billing', 12)->default('advance');   // advance (im Voraus) | arrears (nachträglich)
            // Stichtage werden immer vom Ankerdatum aus berechnet (so driftet der 31. nicht auf den 28.).
            $table->date('anchor_date');
            $table->unsignedInteger('occurrence')->default(0);
            $table->date('next_date');
            $table->date('end_date')->nullable();

            $table->string('currency', 3)->default('EUR');
            $table->string('tax_mode', 20)->default('standard');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->unsignedSmallInteger('payment_term_days')->nullable();
            $table->string('buyer_reference')->nullable();
            $table->text('intro_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('internal_notes')->nullable();

            $table->timestamp('last_generated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'active', 'next_date']);
        });

        Schema::create('recurring_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recurring_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(19);
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('recurring_invoice_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['recurring_invoice_id']);
            $table->dropColumn('recurring_invoice_id');
        });
        Schema::dropIfExists('recurring_invoice_items');
        Schema::dropIfExists('recurring_invoices');
    }
};
