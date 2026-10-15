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

namespace Tests\Unit;

use App\Services\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

class InvoiceCalculatorTest extends TestCase
{
    private function items(): array
    {
        return [
            ['quantity' => 2, 'unit_price' => '49.90', 'discount_percent' => 0, 'tax_rate' => 19],
            ['quantity' => 1, 'unit_price' => '100.00', 'discount_percent' => 10, 'tax_rate' => 7],
        ];
    }

    public function test_lines_groups_and_totals(): void
    {
        $r = InvoiceCalculator::calculate($this->items());

        $this->assertEqualsWithDelta([99.80, 90.00], $r['lines'], 0.0001);
        $this->assertCount(2, $r['groups']);
        $this->assertEqualsWithDelta(19.0, $r['groups'][0]['rate'], 0.0001);
        $this->assertEqualsWithDelta(99.80, $r['groups'][0]['net'], 0.0001);
        $this->assertEqualsWithDelta(18.96, $r['groups'][0]['tax'], 0.0001);
        $this->assertEqualsWithDelta(90.00, $r['groups'][1]['net'], 0.0001);
        $this->assertEqualsWithDelta(6.30, $r['groups'][1]['tax'], 0.0001);
        $this->assertEqualsWithDelta(189.80, $r['net_total'], 0.0001);
        $this->assertEqualsWithDelta(25.26, $r['tax_total'], 0.0001);
        $this->assertEqualsWithDelta(215.06, $r['gross_total'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $r['discount_total'], 0.0001);
    }

    public function test_total_discount_reduces_each_tax_group(): void
    {
        $r = InvoiceCalculator::calculate($this->items(), 10.0);

        $this->assertEqualsWithDelta(89.82, $r['groups'][0]['net'], 0.0001);
        $this->assertEqualsWithDelta(17.07, $r['groups'][0]['tax'], 0.0001);
        $this->assertEqualsWithDelta(81.00, $r['groups'][1]['net'], 0.0001);
        $this->assertEqualsWithDelta(5.67, $r['groups'][1]['tax'], 0.0001);
        $this->assertEqualsWithDelta(170.82, $r['net_total'], 0.0001);
        $this->assertEqualsWithDelta(18.98, $r['discount_total'], 0.0001);
        $this->assertEqualsWithDelta(22.74, $r['tax_total'], 0.0001);
        $this->assertEqualsWithDelta(193.56, $r['gross_total'], 0.0001);
    }

    public function test_reverse_charge_has_no_tax(): void
    {
        $r = InvoiceCalculator::calculate($this->items(), 0.0, 'reverse_charge');

        $this->assertCount(1, $r['groups']);
        $this->assertEqualsWithDelta(0.0, $r['groups'][0]['rate'], 0.0001);
        $this->assertEqualsWithDelta(189.80, $r['groups'][0]['net'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $r['tax_total'], 0.0001);
        $this->assertEqualsWithDelta(189.80, $r['gross_total'], 0.0001);
    }

    public function test_negative_quantity_gives_negative_amounts(): void
    {
        $r = InvoiceCalculator::calculate([['quantity' => -1, 'unit_price' => '50', 'tax_rate' => 19]]);

        $this->assertEqualsWithDelta(-50.0, $r['net_total'], 0.0001);
        $this->assertEqualsWithDelta(-9.50, $r['tax_total'], 0.0001);
        $this->assertEqualsWithDelta(-59.50, $r['gross_total'], 0.0001);
    }

    public function test_empty_items_give_zero(): void
    {
        $r = InvoiceCalculator::calculate([]);

        $this->assertSame([], $r['groups']);
        $this->assertEqualsWithDelta(0.0, $r['gross_total'], 0.0001);
    }
}
