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

namespace Tests\Feature;

use App\Support\Iban;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/** IBAN-Prüfung (Länge und Prüfziffer) und ihr Einsatz im Mandantenformular. */
class IbanTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public static function validIbans(): array
    {
        return [
            'Deutschland' => ['DE89370400440532013000'],
            'mit Leerzeichen und klein' => ['de89 3704 0044 0532 0130 00'],
            'Großbritannien' => ['GB82WEST12345698765432'],
            'Frankreich mit Buchstaben' => ['FR1420041010050500013M02606'],
            'Österreich' => ['AT611904300234573201'],
            'Schweiz' => ['CH9300762011623852957'],
            'Niederlande' => ['NL91ABNA0417164300'],
        ];
    }

    public static function invalidIbans(): array
    {
        return [
            'falsche Prüfziffer' => ['DE89370400440532013001', 'checksum'],
            'zu kurz für Deutschland' => ['DE8937040044053201300', 'length'],
            'zu lang für Deutschland' => ['DE893704004405320130000', 'length'],
            'Buchstabensalat' => ['ABCDEFGHIJKLMNOPQRS', 'format'],
            'zu kurz' => ['DE00XXXX', 'format'],
            'leer' => ['', 'format'],
        ];
    }

    #[DataProvider('validIbans')]
    public function test_valid_ibans_pass(string $iban): void
    {
        $this->assertTrue(Iban::isValid($iban));
        $this->assertNull(Iban::problem($iban));
    }

    #[DataProvider('invalidIbans')]
    public function test_invalid_ibans_are_named(string $iban, string $problem): void
    {
        $this->assertFalse(Iban::isValid($iban));
        $this->assertSame($problem, Iban::problem($iban));
    }

    public function test_normalize_removes_spaces_and_uppercases(): void
    {
        $this->assertSame('DE89370400440532013000', Iban::normalize(' de89 3704 0044 0532 0130 00 '));
    }

    public function test_tenant_form_rejects_invalid_ibans_and_accepts_valid_ones(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $base = ['name' => 'Testfirma GmbH', 'country' => 'DE', 'default_payment_days' => 14];

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->put(route('tenants.update', $tenant), $base + ['iban' => 'DE89 3704 0044 0532 0130 01'])
            ->assertSessionHasErrors('iban');
        $this->put(route('tenants.update', $tenant), $base + ['iban' => 'DE00 1234'])
            ->assertSessionHasErrors('iban');
        $this->assertNotSame('DE89370400440532013001', $tenant->fresh()->iban);

        // Leer ist erlaubt (Angabe optional).
        $this->put(route('tenants.update', $tenant), $base + ['iban' => ''])->assertSessionHasNoErrors();

        $this->put(route('tenants.update', $tenant), $base + ['iban' => 'de89 3704 0044 0532 0130 00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('DE89370400440532013000', $tenant->fresh()->iban);
    }

    public function test_new_tenants_get_the_same_check(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->post(route('tenants.store'), ['name' => 'Neue GmbH', 'country' => 'DE', 'default_payment_days' => 14, 'iban' => 'DE12345'])
            ->assertSessionHasErrors('iban');
    }
}
