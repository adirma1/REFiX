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
        // SMTP-Zugänge gehören keinem einzelnen Mandanten; Mandanten verweisen auf einen Zugang.
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('host', 255);
            $table->unsignedSmallInteger('port')->default(587);
            $table->string('encryption', 10)->default('starttls'); // starttls | tls | none
            $table->string('username', 255)->nullable();
            $table->text('smtp_password')->nullable(); // verschlüsselt (Laravel-Crypt mit APP_KEY)
            $table->string('from_address', 255);
            $table->string('from_name', 255)->nullable();
            $table->string('reply_to', 255)->nullable();
            $table->string('bcc', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $table->json('mail_send_roles')->nullable(); // null = Standard (Buchhaltung); Admin darf immer
            $table->string('mail_invoice_subject', 255)->nullable();
            $table->text('mail_invoice_body')->nullable();
            $table->string('mail_credit_subject', 255)->nullable();
            $table->text('mail_credit_body')->nullable();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $table->string('account_name', 120)->nullable();
            $table->string('recipients', 1000);
            $table->string('bcc', 500)->nullable();
            $table->string('subject', 255);
            $table->string('status', 10); // sent | failed
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_logs');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_account_id');
            $table->dropColumn(['mail_send_roles', 'mail_invoice_subject', 'mail_invoice_body', 'mail_credit_subject', 'mail_credit_body']);
        });
        Schema::dropIfExists('mail_accounts');
    }
};
