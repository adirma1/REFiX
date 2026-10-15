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
        Schema::create('bank_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 255);
            $table->string('format', 10); // camt | csv
            $table->string('account_iban', 34)->nullable();
            $table->unsignedInteger('tx_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_import_id')->constrained('bank_imports')->cascadeOnDelete();
            $table->date('booking_date');
            $table->date('value_date')->nullable();
            $table->decimal('amount', 12, 2); // mit Vorzeichen: Eingang +, Ausgang −
            $table->string('currency', 3)->default('EUR');
            $table->string('counterparty_name', 255)->nullable();
            $table->string('counterparty_iban', 34)->nullable();
            $table->text('purpose')->nullable();
            $table->string('reference', 255)->nullable();
            $table->string('dedupe_hash', 40);
            $table->string('status', 10)->default('new'); // new | booked | ignored
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'dedupe_hash']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10)->default('payment'); // payment | discount (Skonto)
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('reference', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('bank_transaction_id')->nullable()->constrained('bank_transactions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reverse_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_imports');
    }
};
