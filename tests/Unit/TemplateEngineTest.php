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

use App\Services\Layout\LayoutSecurity;
use App\Services\Layout\TemplateEngine;
use App\Services\Layout\TemplateException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TemplateEngineTest extends TestCase
{
    private array $data = [
        'a' => 'x<y&"z"',
        'text' => "eins\nzwei",
        'yes' => true,
        'empty' => '',
        'zero' => '0',
        'items' => [['p' => '1', 'name' => 'A'], ['p' => '2', 'name' => 'B']],
        'none' => [],
        'tenant' => ['name' => 'Firma'],
    ];

    private function render(string $template): string
    {
        return TemplateEngine::render(TemplateEngine::parse($template), $this->data);
    }

    public function test_values_are_escaped_and_filters_work(): void
    {
        $this->assertSame('x&lt;y&amp;&quot;z&quot;', $this->render('{{a}}'));
        $this->assertSame('eins<br>zwei', $this->render('{{ text | br }}'));
        $this->assertSame('FIRMA', $this->render('{{tenant.name | upper}}'));
        $this->assertSame('', $this->render('{{unknown.path}}'));
    }

    public function test_conditions(): void
    {
        $this->assertSame('J', $this->render('{{#if yes}}J{{else}}N{{/if}}'));
        $this->assertSame('N', $this->render('{{#if empty}}J{{else}}N{{/if}}'));
        $this->assertSame('N', $this->render('{{#if zero}}J{{else}}N{{/if}}'));
        $this->assertSame('N', $this->render('{{#if none}}J{{else}}N{{/if}}'));
        $this->assertSame('U', $this->render('{{#unless empty}}U{{/unless}}'));
        $this->assertSame('', $this->render('{{#unless yes}}U{{/unless}}'));
    }

    public function test_loops_see_item_fields_and_the_outer_data(): void
    {
        $this->assertSame('[1:A:Firma][2:B:Firma]', $this->render('{{#each items}}[{{p}}:{{name}}:{{tenant.name}}]{{/each}}'));
        $this->assertSame('', $this->render('{{#each none}}x{{/each}}'));
        $this->assertSame('okok', $this->render('{{#each items}}{{#if p}}ok{{/if}}{{/each}}'));
    }

    #[DataProvider('brokenTemplates')]
    public function test_broken_templates_are_rejected(string $template): void
    {
        $this->expectException(TemplateException::class);
        TemplateEngine::parse($template);
    }

    public static function brokenTemplates(): array
    {
        return [
            'offener Block' => ['{{#if a}}x'],
            'Ende ohne Anfang' => ['{{/if}}'],
            'falscher Filter' => ['{{a | system}}'],
            'offene Klammer' => ['{{ a'],
            'else in each' => ['{{#each items}}{{else}}{{/each}}'],
            'unbekannter Block' => ['{{#include a}}{{/include}}'],
            'ungültiger Pfad' => ['{{ 1a }}'],
            'PHP-Aufruf' => ['{{ phpinfo() }}'],
            'falsch geschachtelt' => ['{{#if a}}{{#each items}}{{/if}}{{/each}}'],
        ];
    }

    public function test_unknown_placeholders_are_reported(): void
    {
        $nodes = TemplateEngine::parse('{{a}}{{zzz}}{{#each items}}{{name}}{{nope}}{{/each}}{{#each a}}{{/each}}');

        $this->assertSame(['{{zzz}}', '{{nope}}', '{{#each a}} (keine Liste)'], TemplateEngine::unknownPaths($nodes, $this->data));
    }

    public function test_template_code_cannot_execute_anything(): void
    {
        $this->assertSame('&lt;?php echo 1; ?&gt;', $this->render('{{a}}') === '' ? '' : TemplateEngine::render(TemplateEngine::parse('{{a}}'), ['a' => '<?php echo 1; ?>']));
    }

    #[DataProvider('unsafeHtml')]
    public function test_unsafe_html_is_rejected(string $html): void
    {
        $this->assertNotSame([], LayoutSecurity::check($html, 'Layout'));
    }

    public static function unsafeHtml(): array
    {
        return [
            ['<script>alert(1)</script>'],
            ['<img src="https://evil.example/x.png">'],
            ['<img src="file:///etc/passwd">'],
            ['<IMG SRC=/etc/passwd>'],
            ['<div style="background:url(http://x.example/y)">x</div>'],
            ['<style>@import url(x.css)</style>'],
            ['<style>@font-face { font-family: x; src: url(x.ttf) }</style>'],
            ['<p onclick="x()">x</p>'],
            ['<a href="javascript:alert(1)">x</a>'],
            ['<link rel="stylesheet" href="x.css">'],
            ['<iframe src="x"></iframe>'],
            ['<svg onload="x"></svg>'],
            ['<img src="phar://x.phar">'],
        ];
    }

    public function test_harmless_design_html_is_accepted(): void
    {
        $html = '<style>td { border: 1px solid #ccc; font-family: dejavusans; }</style>'
            .'<img src="{{ tenant.logo }}" style="max-height: 20mm"><div style="background: url({{tenant.logo}})">Profile: x</div>'
            .'<a href="https://example.com">Link</a><a href="mailto:a@b.de">Mail</a>';

        $this->assertSame([], LayoutSecurity::check($html, 'Layout'));
    }
}
