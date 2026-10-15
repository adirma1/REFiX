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

namespace App\Services\Layout;

use App\Models\AuditLog;
use App\Models\LayoutVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoicePdf;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LayoutService
{
    public const FOOTER_MARKER = '===FOOTER===';

    public const DEFAULT_MARGINS = ['top' => 20, 'right' => 20, 'bottom' => 32, 'left' => 20];

    /** Das eingebaute Standard-Layout als Vorlage (Kopfbereich und Fußzeile). @return array{0: string, 1: string} */
    public static function standardTemplate(string $kind = LayoutVersion::KIND_INVOICE): array
    {
        $file = match ($kind) {
            LayoutVersion::KIND_DUNNING => 'dunning.html',
            LayoutVersion::KIND_QUOTE => 'quote.html',
            default => 'standard.html',
        };
        [$body, $footer] = self::split((string) file_get_contents(resource_path('layouts/'.$file)));

        return [$body, (string) $footer];
    }

    /**
     * Trennt die Antwort der KI: entfernt Markdown-Zäune und teilt an der Marke ===FOOTER===.
     *
     * @return array{0: string, 1: string|null}
     */
    public static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        // Markdown-Codezäune (```html) in der KI-Antwort entfernen
        $raw = trim(preg_replace('/^[ \t]*```[a-z]*[ \t]*$/mi', '', $raw));

        $parts = preg_split('/^\s*===\s*FOOTER\s*===\s*$/mi', $raw, 2);
        $body = trim($parts[0]);
        $footer = isset($parts[1]) ? trim($parts[1]) : null;

        return [$body, $footer === '' ? null : $footer];
    }

    /**
     * Vollständige Prüfung eines Layouts; liefert deutsche Fehlermeldungen (leer = in Ordnung).
     *
     * @param  array{top: int, right: int, bottom: int, left: int}  $margins
     * @return array<int, string>
     */
    public function validate(string $body, ?string $footer, array $margins, Tenant $tenant, string $kind = LayoutVersion::KIND_INVOICE): array
    {
        $errors = [];

        if (trim($body) === '') {
            return ['Der Layout-Text ist leer.'];
        }

        $errors = array_merge($errors, LayoutSecurity::check($body, 'Layout'));
        if ($footer !== null) {
            $errors = array_merge($errors, LayoutSecurity::check($footer, 'Fußzeile'));
        }

        foreach ([['Layout', $body], ['Fußzeile', $footer]] as [$label, $source]) {
            if ($source === null) {
                continue;
            }

            try {
                $nodes = TemplateEngine::parse($source);
            } catch (TemplateException $e) {
                $errors[] = "{$label}: ".$e->getMessage();

                continue;
            }

            $sample = DocumentModel::sampleFor($kind, $tenant, null);
            foreach (TemplateEngine::unknownPaths($nodes, $sample) as $unknown) {
                $errors[] = "{$label}: Unbekannter Platzhalter {$unknown}.";
            }
        }

        $required = $kind === LayoutVersion::KIND_DUNNING
            ? [
                '/\{\{\s*doc\.text\b/' => '{{doc.text}} (Brieftext)',
                '/\{\{\s*invoice\.number\b/' => '{{invoice.number}} (Rechnungsnummer)',
                '/\{\{\s*dunning\.total\b/' => '{{dunning.total}} (Gesamtforderung)',
            ]
            : [
                '/\{\{\s*doc\.number\b/' => '{{doc.number}} (Belegnummer)',
                '/\{\{#each\s+items\s*\}\}/' => '{{#each items}} … {{/each}} (Positionen)',
                '/\{\{\s*totals\.gross_total\b/' => '{{totals.gross_total}} (Endbetrag)',
            ];

        foreach ($required as $pattern => $label) {
            if (! preg_match($pattern, $body)) {
                $errors[] = "Layout: Pflicht-Platzhalter fehlt: {$label}.";
            }
        }

        foreach (['top' => 'oben', 'right' => 'rechts', 'bottom' => 'unten', 'left' => 'links'] as $key => $label) {
            if (! is_int($margins[$key]) || $margins[$key] < 0 || $margins[$key] > 80) {
                $errors[] = "Seitenrand {$label}: Bitte eine Zahl von 0 bis 80 (mm) angeben.";
            }
        }

        if ($errors !== []) {
            return array_values(array_unique($errors));
        }

        // Probelauf: lässt sich das Layout wirklich in ein PDF umsetzen?
        try {
            $pdf = app(InvoicePdf::class)->renderSample(['body_html' => $body, 'footer_html' => $footer, 'margins' => $margins], $tenant, $kind);

            if (! str_starts_with($pdf, '%PDF')) {
                $errors[] = 'Das Layout ergibt kein gültiges PDF.';
            }
        } catch (Throwable $e) {
            $errors[] = 'Das Layout lässt sich nicht in ein PDF umsetzen: '.mb_substr($e->getMessage(), 0, 200);
        }

        return $errors;
    }

    /** @return array{0: string, 1: string} HTML für Text und Fußzeile */
    public function render(LayoutVersion $layout, array $model): array
    {
        return $this->renderParts($layout->body_html, $layout->footer_html, $model, Tenant::findOrFail($layout->tenant_id));
    }

    /** @return array{0: string, 1: string} */
    public function renderParts(string $body, ?string $footer, array $model, Tenant $tenant): array
    {
        $html = TemplateEngine::render(TemplateEngine::parse($body), $model);

        $footerHtml = $footer !== null
            ? TemplateEngine::render(TemplateEngine::parse($footer), $model)
            : view('pdf.footer', ['tenant' => $tenant])->render();

        return [$html, $footerHtml];
    }

    /**
     * Legt eine neue Version an (nach erfolgreicher Prüfung).
     *
     * @param  array{top: int, right: int, bottom: int, left: int}  $margins
     */
    public function createVersion(Tenant $tenant, User $user, string $name, string $body, ?string $footer, array $margins, string $kind = LayoutVersion::KIND_INVOICE): LayoutVersion
    {
        $errors = $this->validate($body, $footer, $margins, $tenant, $kind);

        if ($errors !== []) {
            throw new LayoutInvalid($errors);
        }

        return DB::transaction(function () use ($tenant, $user, $name, $body, $footer, $margins, $kind) {
            $next = (int) LayoutVersion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', $kind)->max('version') + 1;

            $layout = new LayoutVersion([
                'kind' => $kind, 'version' => $next, 'name' => $name, 'body_html' => $body, 'footer_html' => $footer,
                'margin_top' => $margins['top'], 'margin_right' => $margins['right'],
                'margin_bottom' => $margins['bottom'], 'margin_left' => $margins['left'],
                'is_active' => false, 'created_by' => $user->id,
            ]);
            $layout->tenant_id = $tenant->id;
            $layout->save();

            AuditLog::record('layout.created', $layout, ['kind' => $kind, 'version' => $next, 'name' => $name]);

            return $layout;
        });
    }

    public function activate(LayoutVersion $layout): void
    {
        DB::transaction(function () use ($layout) {
            LayoutVersion::withoutGlobalScopes()->where('tenant_id', $layout->tenant_id)->where('kind', $layout->kind)->where('is_active', true)->update(['is_active' => false]);
            LayoutVersion::withoutGlobalScopes()->whereKey($layout->id)->update(['is_active' => true]);

            AuditLog::record('layout.activated', $layout, ['kind' => $layout->kind, 'version' => $layout->version]);
        });
    }

    /** Zurück zum eingebauten Standard-Layout. */
    public function useDefault(Tenant $tenant, string $kind = LayoutVersion::KIND_INVOICE): void
    {
        DB::transaction(function () use ($tenant, $kind) {
            LayoutVersion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('kind', $kind)->where('is_active', true)->update(['is_active' => false]);

            AuditLog::record('layout.default_activated', $tenant, ['kind' => $kind]);
        });
    }

    public function delete(LayoutVersion $layout): void
    {
        if ($layout->is_active) {
            throw new RuntimeException('Das aktive Layout kann nicht gelöscht werden.');
        }

        AuditLog::record('layout.deleted', $layout, ['kind' => $layout->kind, 'version' => $layout->version, 'name' => $layout->name]);
        $layout->delete();
    }
}
