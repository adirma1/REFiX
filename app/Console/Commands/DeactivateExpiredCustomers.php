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

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Console\Command;

class DeactivateExpiredCustomers extends Command
{
    protected $signature = 'customers:deactivate-expired';

    protected $description = 'Schaltet „Aktiv“ bei Kunden ab, deren „Kunde bis“-Datum überschritten ist';

    public function handle(): int
    {
        $count = 0;

        foreach (Tenant::query()->get() as $tenant) {
            Tenancy::set($tenant->id);

            try {
                Customer::query()->where('active', true)->whereNotNull('customer_until')
                    ->whereDate('customer_until', '<', now()->toDateString())
                    ->each(function (Customer $customer) use (&$count) {
                        $customer->update(['active' => false]);
                        AuditLog::record('customer.deactivated_expired', $customer, ['until' => $customer->customer_until?->toDateString()]);
                        $count++;
                    });
            } finally {
                Tenancy::clear();
            }
        }

        $this->info("Kunden deaktiviert: {$count}");

        return self::SUCCESS;
    }
}
