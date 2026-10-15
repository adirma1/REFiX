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

use App\Models\LayoutVersion;
use App\Services\Layout\DocumentModel;
use App\Services\Layout\LayoutPrompt;
use App\Services\Layout\LayoutService;
use App\Services\Layout\TemplateEngine;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** Gültiges Layout: das Standard-Layout mit einem eindeutigen Kennzeichen. */
    private function validContent(string $marker = 'MARKER-4711'): string
    {
        [$body, $footer] = LayoutService::standardTemplate();

        return $body."\n<p>{$marker}</p>\n".LayoutService::FOOTER_MARKER."\n".$footer;
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mein Layout', 'content' => $this->validContent(),
            'margin_top' => 20, 'margin_right' => 20, 'margin_bottom' => 32, 'margin_left' => 20,
        ], $overrides);
    }

    private function admin(?string $name = null)
    {
        $tenant = $this->makeTenant($name ?? 'Testfirma GmbH', ['iban' => 'DE02120300000000202051', 'bic' => 'BYLADEM1001']);
        $admin = $this->makeUser($tenant, 'admin');
        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);
        Tenancy::set($tenant->id);

        return [$tenant, $admin];
    }

    public function test_only_admins_can_use_the_layout_pages(): void
    {
        [$tenant] = $this->admin();
        $this->get('/layouts')->assertOk()->assertSee('Prompt für die KI')->assertSee('REFERENZ-LAYOUT');

        foreach (['accounting', 'clerk', 'viewer'] as $role) {
            $user = $this->makeUser($tenant, $role);
            $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);
            $this->get('/layouts')->assertForbidden();
            $this->post('/layouts', $this->form())->assertForbidden();
        }
    }

    public function test_wishes_become_part_of_the_prompt(): void
    {
        [$tenant] = $this->admin();

        $this->post('/layouts/wishes', ['layout_wishes' => 'dunkelblaue Akzentfarbe, Logo links'])->assertRedirect('/layouts');

        $this->assertSame('dunkelblaue Akzentfarbe, Logo links', $tenant->fresh()->layout_wishes);
        $this->get('/layouts')->assertSee('dunkelblaue Akzentfarbe, Logo links');
    }

    public function test_valid_layout_is_saved_as_a_new_version_and_can_be_activated(): void
    {
        [$tenant] = $this->admin();

        $this->post('/layouts', $this->form())->assertSessionHasNoErrors()->assertRedirect('/layouts');
        $this->post('/layouts', $this->form(['name' => 'Zweites', 'activate' => 1]))->assertSessionHasNoErrors();

        $versions = LayoutVersion::withoutGlobalScopes()->orderBy('version')->get();
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame([false, true], $versions->pluck('is_active')->all());
        $this->assertStringContainsString('MARKER-4711', $versions[0]->body_html);
        $this->assertNotNull($versions[0]->footer_html);

        $this->post(route('layouts.activate', $versions[0]))->assertRedirect('/layouts');
        $this->assertSame([true, false], LayoutVersion::withoutGlobalScopes()->orderBy('version')->pluck('is_active')->all());
        $this->assertSame($versions[0]->id, LayoutVersion::activeFor($tenant->id)->id);

        $this->post(route('layouts.default'));
        $this->assertNull(LayoutVersion::activeFor($tenant->id));
    }

    public function test_unsafe_or_broken_layouts_are_rejected_and_not_saved(): void
    {
        $this->admin();

        $cases = [
            'Skript' => $this->validContent().'<script>alert(1)</script>',
            'externes Bild' => $this->validContent().'<img src="https://evil.example/x.png">',
            'unbekannter Platzhalter' => $this->validContent().'{{tenant.passwort}}',
            'offener Block' => $this->validContent().'{{#if doc.intro}}',
            'Pflichtplatzhalter fehlt' => '<p>Nur Text</p>',
        ];

        foreach ($cases as $label => $content) {
            $this->post('/layouts', $this->form(['content' => $content]))->assertSessionHasErrors('layout');
            $this->assertSame(0, LayoutVersion::withoutGlobalScopes()->count(), $label);
        }
    }

    public function test_pasted_ai_answer_with_markdown_fences_is_understood(): void
    {
        [$body, $footer] = LayoutService::standardTemplate();
        $answer = "Hier ist das Layout:\n```html\n{$body}\n===FOOTER===\n{$footer}\n```";

        // Text vor dem Codeblock ist kein gültiges HTML-Layout, aber harmlos; der Zaun und die Marke müssen verschwinden.
        [$parsedBody, $parsedFooter] = LayoutService::split(substr($answer, strpos($answer, '```')));
        $this->assertStringNotContainsString('```', $parsedBody);
        $this->assertStringNotContainsString('FOOTER===', $parsedBody);
        $this->assertStringContainsString('{{tenant.name}}', (string) $parsedFooter);
        $this->assertStringNotContainsString('```', (string) $parsedFooter);

        [$b, $f] = LayoutService::split('<p>nur Text</p>');
        $this->assertSame('<p>nur Text</p>', $b);
        $this->assertNull($f);
    }

    public function test_preview_returns_a_pdf_and_is_tenant_isolated(): void
    {
        [$tenant] = $this->admin();
        $this->post('/layouts', $this->form())->assertSessionHasNoErrors();
        $layout = LayoutVersion::withoutGlobalScopes()->firstOrFail();

        $response = $this->get(route('layouts.preview', $layout))->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());

        [$other] = $this->admin('Andere GmbH');
        $this->get(route('layouts.preview', $layout))->assertNotFound();
        $this->post(route('layouts.activate', $layout))->assertNotFound();
        $this->delete(route('layouts.destroy', $layout))->assertNotFound();
    }

    public function test_versions_are_immutable_and_the_active_one_cannot_be_deleted(): void
    {
        [$tenant] = $this->admin();
        $this->post('/layouts', $this->form(['activate' => 1]));
        $this->post('/layouts', $this->form(['name' => 'Zweites']));
        [$active, $other] = [LayoutVersion::withoutGlobalScopes()->where('version', 1)->first(), LayoutVersion::withoutGlobalScopes()->where('version', 2)->first()];

        $this->delete(route('layouts.destroy', $active))->assertSessionHas('warning');
        $this->assertNotNull(LayoutVersion::withoutGlobalScopes()->find($active->id));

        $this->delete(route('layouts.destroy', $other))->assertSessionHas('status');
        $this->assertNull(LayoutVersion::withoutGlobalScopes()->find($other->id));

        $this->expectException(LogicException::class);
        $active->update(['body_html' => '<p>geändert</p>']);
    }

    public function test_issued_documents_use_the_active_layout(): void
    {
        [$tenant, $admin] = $this->admin();
        $this->post('/layouts', $this->form(['activate' => 1]))->assertSessionHasNoErrors();

        $customer = $this->makeCustomer($tenant);
        $this->post('/invoices', [
            'type' => 'invoice', 'customer_id' => $customer->id, 'issue_date' => now()->toDateString(), 'currency' => 'EUR', 'tax_mode' => 'standard',
            'items' => [['article_id' => '', 'description' => 'Beratung', 'quantity' => '1', 'unit' => 'Std', 'unit_price' => '100,00', 'discount_percent' => '', 'tax_rate' => '19']],
        ])->assertSessionHasNoErrors();
        Tenancy::set($tenant->id);
        $invoice = \App\Models\Invoice::latest('id')->firstOrFail();

        $this->mock(LayoutService::class, function ($mock) {
            $mock->shouldReceive('render')->once()->andReturn(['<p>eigenes Layout</p>', '<p>Fuß</p>']);
        });

        $pdf = app(\App\Services\InvoicePdf::class)->render($invoice);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_every_documented_placeholder_exists_in_the_model(): void
    {
        [$tenant] = $this->admin();
        $model = DocumentModel::sample($tenant, 'data:image/png;base64,AAAA', 'data:image/svg+xml;base64,AAAA');

        foreach (array_keys(DocumentModel::REFERENCE) as $path) {
            if (str_ends_with($path, '.*')) {
                continue;
            }

            $value = $model;
            foreach (explode('.', $path) as $segment) {
                if (is_array($value) && isset($value[0]) && is_array($value[0]) && ! array_key_exists($segment, $value)) {
                    $value = $value[0];
                }
                $this->assertTrue(is_array($value) && array_key_exists($segment, $value), "Platzhalter {$path} fehlt im Datenmodell.");
                $value = $value[$segment];
            }
        }
    }

    public function test_standard_template_renders_without_unknown_placeholders(): void
    {
        [$tenant] = $this->admin();
        $model = DocumentModel::sample($tenant, null);
        [$body, $footer] = LayoutService::standardTemplate();

        $this->assertSame([], TemplateEngine::unknownPaths(TemplateEngine::parse($body), $model));
        $this->assertSame([], TemplateEngine::unknownPaths(TemplateEngine::parse($footer), $model));

        $html = TemplateEngine::render(TemplateEngine::parse($body), $model);
        $this->assertStringContainsString('R-2026-0001', $html);
        $this->assertStringContainsString('Beispiel Kunde GmbH', $html);
    }
    /** Gültiges Mahn-Layout: die Mahn-Vorlage mit einem Kennzeichen. */
    private function dunningContent(string $marker = 'MAHN-4711'): string
    {
        [$body, $footer] = LayoutService::standardTemplate('dunning');

        return $body."\n<p>{$marker}</p>\n".LayoutService::FOOTER_MARKER."\n".$footer;
    }

    public function test_every_documented_dunning_placeholder_exists_in_the_model(): void
    {
        [$tenant] = $this->admin();
        $model = DocumentModel::dunningSample($tenant, 'data:image/png;base64,AAAA', 'data:image/svg+xml;base64,AAAA');

        foreach (array_keys(DocumentModel::REFERENCE_DUNNING) as $path) {
            if (str_ends_with($path, '.*')) {
                continue;
            }

            $value = $model;
            foreach (explode('.', $path) as $segment) {
                $this->assertTrue(is_array($value) && array_key_exists($segment, $value), "Platzhalter {$path} fehlt im Mahn-Datenmodell.");
                $value = $value[$segment];
            }
        }

        // Umgekehrt ist jeder Platzhalter des Modells auch beschrieben.
        $documented = array_keys(DocumentModel::REFERENCE_DUNNING);
        foreach ($model as $group => $fields) {
            foreach (array_keys($fields) as $field) {
                $this->assertTrue(
                    in_array("{$group}.{$field}", $documented, true) || in_array("{$group}.*", $documented, true),
                    "Platzhalter {$group}.{$field} ist nicht dokumentiert."
                );
            }
        }
    }

    public function test_dunning_standard_template_renders_without_unknown_placeholders(): void
    {
        [$tenant] = $this->admin();
        $model = DocumentModel::dunningSample($tenant, null);
        [$body, $footer] = LayoutService::standardTemplate('dunning');

        $this->assertSame([], TemplateEngine::unknownPaths(TemplateEngine::parse($body), $model));
        $this->assertSame([], TemplateEngine::unknownPaths(TemplateEngine::parse($footer), $model));

        $html = TemplateEngine::render(TemplateEngine::parse($body), $model);
        foreach (['R-2026-0001', 'Beispiel Kunde GmbH', '1. Mahnung', '303,73', '297,50', 'Mahngebühr', 'Verzugszinsen'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_quote_standard_template_renders_without_unknown_placeholders(): void
    {
        [$tenant] = $this->admin();
        $model = DocumentModel::sampleFor('quote', $tenant, null);
        [$body, $footer] = LayoutService::standardTemplate('quote');

        $this->assertSame([], TemplateEngine::unknownPaths(TemplateEngine::parse($body), $model));
        $this->assertSame([], TemplateEngine::unknownPaths(TemplateEngine::parse($footer), $model));

        $html = TemplateEngine::render(TemplateEngine::parse($body), $model);
        $this->assertStringContainsString('Angebot', $html);
    }

    public function test_quote_layouts_are_a_separate_series(): void
    {
        [$tenant] = $this->admin();
        [$body, $footer] = LayoutService::standardTemplate('quote');
        $content = $body."\n<p>ANGEBOT-4711</p>\n".LayoutService::FOOTER_MARKER."\n".$footer;

        $this->post('/layouts', $this->form(['kind' => 'quote', 'name' => 'Angebot A', 'content' => $content, 'activate' => 1]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/layouts?kind=quote');

        $this->assertSame('Angebot A', LayoutVersion::activeFor($tenant->id, 'quote')->name);
        $this->assertNull(LayoutVersion::activeFor($tenant->id));

        $layout = LayoutVersion::withoutGlobalScopes()->where('kind', 'quote')->firstOrFail();
        $this->get(route('layouts.preview', $layout))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_dunning_layouts_are_a_separate_series_with_their_own_active_version(): void
    {
        [$tenant] = $this->admin();

        $this->post('/layouts', $this->form(['name' => 'Rechnung A', 'activate' => 1]))->assertSessionHasNoErrors();
        $this->post('/layouts', $this->form(['kind' => 'dunning', 'name' => 'Mahnung A', 'content' => $this->dunningContent(), 'activate' => 1]))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/layouts?kind=dunning');
        $this->post('/layouts', $this->form(['kind' => 'dunning', 'name' => 'Mahnung B', 'content' => $this->dunningContent('MAHN-B')]))
            ->assertSessionHasNoErrors();

        // Nummerierung je Art; Aktivierung beeinflusst die andere Art nicht.
        $dunning = LayoutVersion::withoutGlobalScopes()->where('kind', 'dunning')->orderBy('version')->get();
        $this->assertSame([1, 2], $dunning->pluck('version')->all());
        $this->assertSame([true, false], $dunning->pluck('is_active')->all());
        $this->assertSame(['Rechnung A'], LayoutVersion::withoutGlobalScopes()->where('kind', 'invoice')->pluck('name')->all());
        $this->assertSame('Rechnung A', LayoutVersion::activeFor($tenant->id)->name);
        $this->assertSame('Mahnung A', LayoutVersion::activeFor($tenant->id, 'dunning')->name);

        $this->post(route('layouts.activate', $dunning[1]))->assertRedirect('/layouts?kind=dunning');
        $this->assertSame('Mahnung B', LayoutVersion::activeFor($tenant->id, 'dunning')->name);
        $this->assertSame('Rechnung A', LayoutVersion::activeFor($tenant->id)->name);

        // Zurück zum Standard betrifft nur die gewählte Art.
        $this->post(route('layouts.default'), ['kind' => 'dunning'])->assertRedirect('/layouts?kind=dunning');
        $this->assertNull(LayoutVersion::activeFor($tenant->id, 'dunning'));
        $this->assertSame('Rechnung A', LayoutVersion::activeFor($tenant->id)->name);

        // Die Seiten zeigen jeweils nur die eigene Art.
        $this->get('/layouts?kind=dunning')->assertOk()->assertSee('Mahnung A')->assertDontSee('Rechnung A');
        $this->get('/layouts')->assertOk()->assertSee('Rechnung A')->assertDontSee('Mahnung A');
    }

    public function test_layouts_must_match_their_kind(): void
    {
        $this->admin();

        // Eine Rechnungsvorlage ist als Mahn-Layout unzulässig (unbekannte Platzhalter, Pflichtplatzhalter fehlen) und umgekehrt.
        $this->post('/layouts', $this->form(['kind' => 'dunning']))->assertSessionHasErrors('layout');
        $this->post('/layouts', $this->form(['content' => $this->dunningContent()]))->assertSessionHasErrors('layout');
        $this->assertSame(0, LayoutVersion::withoutGlobalScopes()->count());

        // Unbekannte Art fällt auf „Rechnung“ zurück.
        $this->post('/layouts', $this->form(['kind' => 'quatsch']))->assertSessionHasNoErrors();
        $this->assertSame('invoice', LayoutVersion::withoutGlobalScopes()->first()->kind);
    }

    public function test_dunning_wishes_and_prompt_are_separate(): void
    {
        [$tenant] = $this->admin();

        $this->post('/layouts/wishes', ['layout_wishes' => 'Rechnung grün'])->assertRedirect('/layouts');
        $this->post('/layouts/wishes', ['layout_wishes' => 'Mahnung rot', 'kind' => 'dunning'])->assertRedirect('/layouts?kind=dunning');

        $tenant->refresh();
        $this->assertSame('Rechnung grün', $tenant->layout_wishes);
        $this->assertSame('Mahnung rot', $tenant->layout_wishes_dunning);

        $prompt = LayoutPrompt::build($tenant->layout_wishes_dunning, 'Testfirma GmbH', 'dunning');
        $this->assertStringContainsString('Mahnung rot', $prompt);
        $this->assertStringContainsString('Zahlungserinnerungen und Mahnschreiben', $prompt);
        $this->assertStringContainsString('{{dunning.total}}', $prompt);
        $this->assertStringContainsString('{{doc.text}}', $prompt);
        $this->assertStringNotContainsString('{{totals.gross_total}}', $prompt);

        $invoicePrompt = LayoutPrompt::build($tenant->layout_wishes, 'Testfirma GmbH');
        $this->assertStringContainsString('{{totals.gross_total}}', $invoicePrompt);
        $this->assertStringNotContainsString('{{dunning.total}}', $invoicePrompt);

        $this->get('/layouts?kind=dunning')->assertOk()->assertSee('Mahnung rot');
    }

    public function test_dunning_layout_preview_is_a_pdf_and_tenant_isolated(): void
    {
        [$tenant] = $this->admin();
        $this->post('/layouts', $this->form(['kind' => 'dunning', 'content' => $this->dunningContent()]))->assertSessionHasNoErrors();
        $layout = LayoutVersion::withoutGlobalScopes()->where('kind', 'dunning')->firstOrFail();

        $response = $this->get(route('layouts.preview', $layout));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // Fremder Mandant sieht fremde Layouts nicht.
        $otherTenant = $this->makeTenant('Andere GmbH');
        $other = $this->makeUser($otherTenant, 'admin');
        $this->actingAs($other)->withSession(['tenant_id' => $otherTenant->id]);
        Tenancy::set($otherTenant->id);
        $this->get(route('layouts.preview', $layout))->assertNotFound();
    }
}
