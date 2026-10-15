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

use App\Services\EpcQr;
use PHPUnit\Framework\TestCase;

class EpcQrTest extends TestCase
{
    public function test_payload_follows_the_epc_layout(): void
    {
        $lines = explode("\n", EpcQr::payload('Muster GmbH', 'DE02 1203 0000 0000 2020 51', 'byladem1001', 1956.4, 'Rechnung R-2026-0020'));

        $this->assertSame('BCD', $lines[0]);
        $this->assertSame('002', $lines[1]);
        $this->assertSame('1', $lines[2]);
        $this->assertSame('SCT', $lines[3]);
        $this->assertSame('BYLADEM1001', $lines[4]);
        $this->assertSame('Muster GmbH', $lines[5]);
        $this->assertSame('DE02120300000000202051', $lines[6]);
        $this->assertSame('EUR1956.40', $lines[7]);
        $this->assertSame('Rechnung R-2026-0020', $lines[10]);
    }

    public function test_long_name_and_text_are_truncated(): void
    {
        $lines = explode("\n", EpcQr::payload(str_repeat('N', 100), 'DE02120300000000202051', null, 1, str_repeat('V', 200)));

        $this->assertSame(70, mb_strlen($lines[5]));
        $this->assertSame(140, mb_strlen($lines[10]));
        $this->assertSame('', $lines[4]);
    }

    public function test_svg_data_uri(): void
    {
        $uri = EpcQr::svgDataUri(EpcQr::payload('Muster GmbH', 'DE02120300000000202051', null, 10, 'Test'));

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
        $this->assertStringContainsString('<svg', base64_decode(substr($uri, strlen('data:image/svg+xml;base64,'))));
    }
}
