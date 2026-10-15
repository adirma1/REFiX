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

use App\Models\Invoice;
use App\Services\NumberGenerator;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NumberGeneratorTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_render_and_validate_formats(): void
    {
        $date = Carbon::parse('2026-10-11');

        $this->assertSame('R-2026-0019', NumberGenerator::render('R-{YYYY}-{NNNN}', 19, $date));
        $this->assertSame('TEST-R-2026-0019', NumberGenerator::render('R-{YYYY}-{NNNN}', 19, $date, true));
        $this->assertSame('2610-007', NumberGenerator::render('{YY}{MM}-{NNN}', 7, $date));
        $this->assertSame('12345', NumberGenerator::render('{N}', 12345, $date));

        $this->assertTrue(NumberGenerator::validateFormat('R-{NNNN}'));
        $this->assertFalse(NumberGenerator::validateFormat('R-2026'));
        $this->assertFalse(NumberGenerator::validateFormat('{YYYY}'));
    }

    public function test_numbers_are_sequential_and_restart_each_year(): void
    {
        $tenant = $this->makeTenant();
        $date = Carbon::parse('2026-10-11');

        $this->assertSame('R-2026-0001', NumberGenerator::next($tenant, 'invoice', false, $date));
        $this->assertSame('R-2026-0002', NumberGenerator::next($tenant, 'invoice', false, $date));
        $this->assertSame('R-2027-0001', NumberGenerator::next($tenant, 'invoice', false, Carbon::parse('2027-01-05')));
        $this->assertSame('R-2026-0003', NumberGenerator::next($tenant, 'invoice', false, $date));
    }

    public function test_credit_notes_and_test_documents_have_their_own_counters(): void
    {
        $tenant = $this->makeTenant();
        $date = Carbon::parse('2026-10-11');

        $this->assertSame('R-2026-0001', NumberGenerator::next($tenant, 'invoice', false, $date));
        $this->assertSame('G-2026-0001', NumberGenerator::next($tenant, 'credit_note', false, $date));
        $this->assertSame('TEST-R-2026-0001', NumberGenerator::next($tenant, 'invoice', true, $date));
        $this->assertSame('R-2026-0002', NumberGenerator::next($tenant, 'invoice', false, $date));
    }

    public function test_counters_are_separate_per_tenant(): void
    {
        $a = $this->makeTenant('A GmbH');
        $b = $this->makeTenant('B GmbH');
        $date = Carbon::parse('2026-10-11');

        NumberGenerator::next($a, 'invoice', false, $date);
        NumberGenerator::next($a, 'invoice', false, $date);

        $this->assertSame('R-2026-0001', NumberGenerator::next($b, 'invoice', false, $date));
    }

    public function test_counter_can_be_set_to_continue_from_the_old_program(): void
    {
        $tenant = $this->makeTenant();
        $date = Carbon::parse('2026-10-11');

        NumberGenerator::setLast($tenant, 'invoice', false, $date, 19);

        $this->assertSame(19, NumberGenerator::lastFor($tenant, 'invoice', false, $date));
        $this->assertSame('R-2026-0020', NumberGenerator::next($tenant, 'invoice', false, $date));
    }

    public function test_numbers_already_in_use_are_skipped(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant);
        $date = Carbon::parse('2026-10-11');

        Tenancy::set($tenant->id);
        Invoice::create(['customer_id' => $customer->id, 'issue_date' => '2026-10-11', 'number' => 'R-2026-0003', 'status' => 'issued']);
        Tenancy::clear();

        NumberGenerator::setLast($tenant, 'invoice', false, $date, 2);

        $this->assertSame('R-2026-0004', NumberGenerator::next($tenant, 'invoice', false, $date));
    }

    public function test_monthly_format_restarts_each_month(): void
    {
        $tenant = $this->makeTenant();
        $tenant->update(['invoice_number_format' => 'R{YY}{MM}-{NN}']);

        $this->assertSame('R2610-01', NumberGenerator::next($tenant, 'invoice', false, Carbon::parse('2026-10-11')));
        $this->assertSame('R2611-01', NumberGenerator::next($tenant, 'invoice', false, Carbon::parse('2026-11-02')));
        $this->assertSame('R2610-02', NumberGenerator::next($tenant, 'invoice', false, Carbon::parse('2026-10-20')));
    }
}
