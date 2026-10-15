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

use App\Support\LogoImage;
use PHPUnit\Framework\TestCase;

class LogoImageTest extends TestCase
{
    public function test_transparent_png_is_flattened_onto_white(): void
    {
        $image = imagecreatetruecolor(40, 20);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);
        imagefilledrectangle($image, 10, 5, 30, 15, imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();

        $uri = LogoImage::dataUri($png, 'image/png');
        $this->assertStringStartsWith('data:image/png;base64,', $uri);

        $result = imagecreatefromstring(base64_decode(substr($uri, strlen('data:image/png;base64,'))));
        $this->assertSame(0xFFFFFF, imagecolorat($result, 1, 1) & 0xFFFFFF); // vorher transparent, jetzt weiß
        $this->assertSame(0xC80000, imagecolorat($result, 20, 10) & 0xFFFFFF); // Motiv bleibt
    }

    public function test_jpeg_and_broken_png_pass_through(): void
    {
        $this->assertSame('data:image/jpeg;base64,'.base64_encode('abc'), LogoImage::dataUri('abc', 'image/jpeg'));
        $this->assertSame('data:image/png;base64,'.base64_encode('kein bild'), LogoImage::dataUri('kein bild', 'image/png'));
        $this->assertSame('data:image/png;base64,'.base64_encode("\x89PNG kaputt"), LogoImage::dataUri("\x89PNG kaputt", 'image/png'));
    }
}
