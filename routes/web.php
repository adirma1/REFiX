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

use App\Http\Controllers\AboutController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\ArticleImportController;
use App\Http\Controllers\ArticlePriceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerExportController;
use App\Http\Controllers\CustomerImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DunningController;
use App\Http\Controllers\DunningMailController;
use App\Http\Controllers\DunningSettingsController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceMailController;
use App\Http\Controllers\LayoutController;
use App\Http\Controllers\MailAccountController;
use App\Http\Controllers\MailSettingsController;
use App\Http\Controllers\NumberingController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\QuoteMailController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Ersteinrichtung: nur solange noch kein Benutzer existiert.
Route::middleware('setup.open')->group(function () {
    Route::get('/setup', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');
});

// Anmeldung
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.store');
    Route::get('/two-factor', [AuthController::class, 'showChallenge'])->name('two-factor.challenge');
    Route::post('/two-factor', [AuthController::class, 'challenge'])->name('two-factor.challenge.store');

    // Passwort vergessen
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'form'])->middleware('throttle:20,1')->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:10,1')->name('password.update');
});

Route::get('/locale/{locale}', [AuthController::class, 'locale'])->name('locale');

// Abmelden muss immer möglich sein, auch ohne zugeordneten Mandanten.
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'tenant'])->group(function () {
    // Profil, Passwort und 2FA sind auch erreichbar, wenn die Einrichtung noch verlangt wird.
    Route::get('/about', [AboutController::class, 'show'])->name('about');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('/profile/email', [ProfileController::class, 'updateEmail'])->name('profile.email');
    Route::post('/profile/per-page', [ProfileController::class, 'updatePerPage'])->name('profile.per-page');
    Route::post('/profile/locale', [ProfileController::class, 'updateLocale'])->name('profile.locale');
    Route::get('/profile/two-factor', [TwoFactorController::class, 'show'])->name('profile.two-factor');
    Route::post('/profile/two-factor', [TwoFactorController::class, 'confirm'])->name('profile.two-factor.confirm');
    Route::post('/profile/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('profile.two-factor.regenerate');
    Route::post('/profile/two-factor/preference', [TwoFactorController::class, 'preference'])->name('profile.two-factor.preference');

    Route::middleware(['password.fresh', 'two-factor'])->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // Import vor der Ressource, damit "import" nicht als Kunden-ID gelesen wird.
        Route::get('/customers/import', [CustomerImportController::class, 'form'])->name('customers.import');
        Route::get('/customers/import/template', [CustomerImportController::class, 'template'])->name('customers.import.template');
        Route::post('/customers/import', [CustomerImportController::class, 'upload'])->name('customers.import.upload');
        Route::get('/customers/import/{token}', [CustomerImportController::class, 'map'])->name('customers.import.map');
        Route::post('/customers/import/{token}/preview', [CustomerImportController::class, 'preview'])->name('customers.import.preview');
        Route::post('/customers/import/{token}/run', [CustomerImportController::class, 'run'])->name('customers.import.run');
        Route::get('/customers/export', [CustomerExportController::class, 'form'])->name('customers.export');
        Route::post('/customers/export', [CustomerExportController::class, 'download'])->name('customers.export.download');
        Route::resource('customers', CustomerController::class)->except('show');

        // Import vor der Ressource, damit "import" nicht als Artikel-ID gelesen wird.
        Route::get('/articles/import', [ArticleImportController::class, 'form'])->name('articles.import');
        Route::get('/articles/import/template', [ArticleImportController::class, 'template'])->name('articles.import.template');
        Route::post('/articles/import', [ArticleImportController::class, 'upload'])->name('articles.import.upload');
        Route::get('/articles/import/{token}', [ArticleImportController::class, 'map'])->name('articles.import.map');
        Route::post('/articles/import/{token}/preview', [ArticleImportController::class, 'preview'])->name('articles.import.preview');
        Route::post('/articles/import/{token}/run', [ArticleImportController::class, 'run'])->name('articles.import.run');

        Route::resource('articles', ArticleController::class);
        Route::post('/articles/{article}/prices', [ArticlePriceController::class, 'store'])->name('articles.prices.store');
        Route::delete('/articles/{article}/prices/{price}', [ArticlePriceController::class, 'destroy'])->name('articles.prices.destroy');

        Route::post('/recurring/run', [RecurringInvoiceController::class, 'run'])->name('recurring.run');
        Route::resource('recurring', RecurringInvoiceController::class)->except(['show']);
        Route::post('/invoices/{invoice}/recurring', [RecurringInvoiceController::class, 'fromInvoice'])->name('invoices.recurring');
        Route::resource('quotes', QuoteController::class);
        Route::post('/quotes/{quote}/issue', [QuoteController::class, 'issue'])->name('quotes.issue');
        Route::post('/quotes/{quote}/status', [QuoteController::class, 'status'])->name('quotes.status');
        Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convert'])->name('quotes.convert');
        Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');
        Route::get('/quotes/{quote}/mail', [QuoteMailController::class, 'form'])->name('quotes.mail');
        Route::post('/quotes/{quote}/mail', [QuoteMailController::class, 'send'])->name('quotes.mail.send');

        Route::get('/invoices/article-price/{article}', [InvoiceController::class, 'articlePrice'])->name('invoices.article-price');
        Route::resource('invoices', InvoiceController::class);
        Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
        Route::post('/invoices/{invoice}/credit-note', [InvoiceController::class, 'creditNote'])->name('invoices.credit-note');
        Route::get('/invoices/{invoice}/mail', [InvoiceMailController::class, 'form'])->name('invoices.mail');
        Route::post('/invoices/{invoice}/mail', [InvoiceMailController::class, 'send'])->name('invoices.mail.send');
        Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
        Route::post('/invoices/{invoice}/rebuild-pdf', [InvoiceController::class, 'rebuildPdf'])->name('invoices.rebuild-pdf');

        Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::post('/payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');

        Route::get('/bank', [BankController::class, 'index'])->name('bank.index');
        Route::post('/bank/upload', [BankController::class, 'upload'])->name('bank.upload');
        Route::get('/bank/csv/{token}', [BankController::class, 'csv'])->name('bank.csv');
        Route::post('/bank/csv/{token}', [BankController::class, 'csvImport'])->name('bank.csv.import');
        Route::post('/bank/book', [BankController::class, 'book'])->name('bank.book');
        Route::post('/bank/ignore', [BankController::class, 'ignoreMany'])->name('bank.ignore-many');
        Route::post('/bank/{transaction}/ignore', [BankController::class, 'ignore'])->name('bank.ignore');
        Route::post('/bank/{transaction}/restore', [BankController::class, 'restore'])->name('bank.restore');
        Route::delete('/bank/imports/{import}', [BankController::class, 'destroyImport'])->whereNumber('import')->name('bank.imports.destroy');

        Route::get('/dunning', [DunningController::class, 'index'])->name('dunning.index');
        Route::post('/dunning', [DunningController::class, 'store'])->name('dunning.store');
        Route::get('/dunning/{dunning}/pdf', [DunningController::class, 'pdf'])->whereNumber('dunning')->name('dunning.pdf');
        Route::post('/dunning/{dunning}/void', [DunningController::class, 'void'])->whereNumber('dunning')->name('dunning.void');
        Route::post('/dunning/{dunning}/sent', [DunningController::class, 'sent'])->whereNumber('dunning')->name('dunning.sent');
        Route::get('/dunning/{dunning}/mail', [DunningMailController::class, 'form'])->whereNumber('dunning')->name('dunning.mail');
        Route::post('/dunning/{dunning}/mail', [DunningMailController::class, 'send'])->whereNumber('dunning')->name('dunning.mail.send');
        Route::post('/invoices/{invoice}/dunning-hold', [DunningController::class, 'hold'])->name('dunning.hold');
        Route::post('/dunning-holds', [DunningController::class, 'holdMany'])->name('dunning.hold-many');
        Route::post('/dunning-holds/{hold}/release', [DunningController::class, 'release'])->name('dunning.release');
        Route::get('/dunning-settings', [DunningSettingsController::class, 'edit'])->name('dunning.settings');
        Route::post('/dunning-settings', [DunningSettingsController::class, 'update'])->name('dunning.settings.update');

        Route::get('/layouts', [LayoutController::class, 'index'])->name('layouts.index');
        Route::post('/layouts', [LayoutController::class, 'store'])->name('layouts.store');
        Route::post('/layouts/wishes', [LayoutController::class, 'wishes'])->name('layouts.wishes');
        Route::post('/layouts/default', [LayoutController::class, 'useDefault'])->name('layouts.default');
        Route::get('/layouts/{layout}/preview', [LayoutController::class, 'preview'])->name('layouts.preview');
        Route::post('/layouts/{layout}/activate', [LayoutController::class, 'activate'])->name('layouts.activate');
        Route::delete('/layouts/{layout}', [LayoutController::class, 'destroy'])->name('layouts.destroy');

        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::get('/backups/{name}', [BackupController::class, 'download'])->where('name', 'backup-[0-9]{8}-[0-9]{6}\.zip')->name('backups.download');
        Route::delete('/backups/{name}', [BackupController::class, 'destroy'])->where('name', 'backup-[0-9]{8}-[0-9]{6}\.zip')->name('backups.destroy');

        Route::get('/mail', [MailSettingsController::class, 'edit'])->name('mail.edit');
        Route::post('/mail', [MailSettingsController::class, 'update'])->name('mail.update');
        Route::resource('mail-accounts', MailAccountController::class)->except('show')->parameters(['mail-accounts' => 'account']);
        Route::post('/mail-accounts/{account}/test', [MailAccountController::class, 'test'])->name('mail-accounts.test');

        Route::get('/numbering', [NumberingController::class, 'index'])->name('numbering.index');
        Route::post('/numbering', [NumberingController::class, 'update'])->name('numbering.update');

        Route::post('/tenants/{tenant}/switch', [TenantController::class, 'switch'])->name('tenants.switch');
        Route::resource('tenants', TenantController::class)->except(['show', 'destroy']);

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/username', [UserController::class, 'updateUsername'])->name('users.username');
        Route::post('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');
        Route::delete('/audit', [AuditController::class, 'clear'])->name('audit.clear');
    });
});
