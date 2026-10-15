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
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

/** Hilfen für Tests rund um Zahlungen und Bankimport. */
trait CreatesPaymentSetup
{
    /**
     * Legt eine Rechnung über das Formular an und gibt sie frei (als Buchhaltung).
     * Standard: 2,5 Std. à 100,00 € + 19 % = 297,50 € brutto, Datum 11.10.2026, fällig 25.10.2026.
     */
    protected function issueInvoice(Tenant $tenant, Customer $customer, User $accounting, array $extra = [], string $net = '100,00'): Invoice
    {
        $this->actingAs($accounting)->withSession(['tenant_id' => $tenant->id])
            ->post('/invoices', array_merge([
                'type' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => '2026-10-11',
                'currency' => 'EUR', 'tax_mode' => 'standard',
                'items' => [['article_id' => '', 'description' => 'Beratung', 'quantity' => '2,5', 'unit' => 'Stunde',
                    'unit_price' => $net, 'discount_percent' => '', 'tax_rate' => '19']],
            ], $extra))->assertSessionHasNoErrors();

        Tenancy::set($tenant->id);
        $invoice = Invoice::latest('id')->firstOrFail();

        $this->post(route('invoices.issue', $invoice))->assertRedirect();

        return $invoice->fresh();
    }
}
