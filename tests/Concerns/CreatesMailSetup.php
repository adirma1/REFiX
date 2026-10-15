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

namespace Tests\Concerns;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\MailAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\MailSender;
use App\Support\Tenancy;
use Tests\Support\FakeMailSender;

/** Hilfen für Tests rund um den E-Mail-Versand. */
trait CreatesMailSetup
{
    protected function fakeMailer(): FakeMailSender
    {
        $fake = new FakeMailSender;
        $this->app->instance(MailSender::class, $fake);

        return $fake;
    }

    protected function makeMailAccount(array $attributes = []): MailAccount
    {
        return MailAccount::create(array_merge([
            'name' => 'GMX Firma', 'host' => 'mail.example.com', 'port' => 587, 'encryption' => 'starttls',
            'username' => 'rechnung@example.com', 'smtp_password' => 'geheim-123',
            'from_address' => 'rechnung@example.com', 'from_name' => 'Testfirma GmbH',
        ], $attributes));
    }

    /** Legt über das Formular eine Rechnung an und gibt sie frei (als Buchhaltung). */
    protected function issuedInvoice(Tenant $tenant, Customer $customer, User $accounting): Invoice
    {
        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id])
            ->post('/invoices', [
                'type' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-10-11',
                'currency' => 'EUR', 'tax_mode' => 'standard',
                'items' => [['article_id' => '', 'description' => 'Beratung', 'quantity' => '2,5', 'unit' => 'Stunde',
                    'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19']],
            ])->assertSessionHasNoErrors();

        Tenancy::set($tenant->id);
        $invoice = Invoice::latest('id')->firstOrFail();

        $this->post(route('invoices.issue', $invoice))->assertRedirect();

        return $invoice->fresh();
    }

    protected function validAccountData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'GMX Firma', 'host' => 'mail.gmx.net', 'port' => '587', 'encryption' => 'starttls',
            'username' => 'rechnung@example.com', 'smtp_password' => 'geheim-123',
            'from_address' => 'rechnung@example.com', 'from_name' => 'Testfirma GmbH',
            'reply_to' => '', 'bcc' => '',
        ], $overrides);
    }
}
