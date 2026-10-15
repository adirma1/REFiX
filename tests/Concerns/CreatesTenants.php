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
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

trait CreatesTenants
{
    protected function makeTenant(string $name = 'Testfirma GmbH', array $attributes = []): Tenant
    {
        // Vollständige Absenderdaten, damit freigegebene Belege eine gültige E-Rechnung ergeben.
        return Tenant::create(array_merge([
            'name' => $name, 'street' => 'Musterstraße 1', 'zip' => '33378', 'city' => 'Rheda-Wiedenbrück',
            'country' => 'DE', 'vat_id' => 'DE123456789',
        ], $attributes));
    }

    protected function makeUser(Tenant $tenant, string $role = 'admin', array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Test '.$role,
            'email' => $role.'-'.$tenant->id.'-'.uniqid().'@example.com',
            'password' => 'ein-langes-passwort-123',
        ], $attributes));

        $tenant->users()->attach($user->id, ['role' => $role]);

        return $user;
    }

    protected function makeCustomer(Tenant $tenant, string $number = '10001', array $attributes = []): Customer
    {
        Tenancy::set($tenant->id);

        $customer = Customer::create(array_merge([
            'customer_number' => $number,
            'company' => 'Kunde '.$number.' GmbH',
            'street' => 'Musterstr. 1',
            'zip' => '33378',
            'city' => 'Rheda-Wiedenbrück',
            'country' => 'DE',
            'email' => 'kunde'.$number.'@example.com',
        ], $attributes));

        Tenancy::clear();

        return $customer;
    }

    /** Gültige Formulardaten für einen Kunden. */
    protected function customerData(array $overrides = []): array
    {
        return array_merge([
            'customer_number' => '20001',
            'company' => 'Neue Kunde GmbH',
            'street' => 'Hauptstr. 5',
            'zip' => '33154',
            'city' => 'Salzkotten',
            'country' => 'DE',
            'email' => 'info@neue-kunde.example',
            'currency' => 'EUR',
        ], $overrides);
    }
}
