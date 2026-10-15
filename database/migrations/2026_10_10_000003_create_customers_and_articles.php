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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('customer_number', 30);
            $table->string('company');
            $table->string('salutation', 30)->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('street');
            $table->string('zip', 20);
            $table->string('city');
            $table->string('country', 2)->default('DE');
            $table->string('email');
            $table->string('phone', 40)->nullable();
            $table->string('vat_id', 20)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('payment_term_days')->nullable();
            $table->decimal('discount_percent', 5, 2)->nullable();
            $table->unsignedSmallInteger('discount_days')->nullable();
            $table->string('buyer_reference')->nullable(); // Leitweg-ID
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'customer_number']);
            $table->index(['tenant_id', 'company']);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('article_number', 40)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 20);
            $table->decimal('tax_rate', 5, 2)->default(19);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'article_number']);
        });

        Schema::create('article_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('amount', 12, 4);
            $table->date('valid_from');
            $table->timestamps();

            $table->index(['article_id', 'currency', 'valid_from']);
        });
    }

    public function down(): void
    {
        foreach (['article_prices', 'articles', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
