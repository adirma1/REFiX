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

namespace App\Http\Controllers;

use App\Models\LayoutVersion;
use App\Models\Tenant;
use App\Services\InvoicePdf;
use App\Services\Layout\LayoutInvalid;
use App\Services\Layout\LayoutPrompt;
use App\Services\Layout\LayoutService;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/**
 * Layout-Verwaltung der Belege (nur Admin): Prompt für die KI, Einfügen, Vorschau, Versionen.
 * Drei Arten: Rechnungen/Gutschriften (invoice), Zahlungserinnerung/Mahnung (dunning) und Angebote (quote), je mit eigenen Versionen.
 */
class LayoutController extends Controller
{
    public function __construct(private LayoutService $layouts) {}

    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        $kind = $this->kind($request->query('kind'));
        $tenant = Tenant::findOrFail(Tenancy::id());
        $versions = LayoutVersion::query()->where('kind', $kind)->with('creator:id,name')->orderByDesc('version')->get();

        // Vorbelegung des Formulars: aus einer vorhandenen Version oder dem Standard-Layout
        $prefill = null;
        if ($request->query('standard')) {
            [$body, $footer] = LayoutService::standardTemplate($kind);
            $prefill = ['name' => 'Standard-Layout (Kopie)', 'content' => $body."\n".LayoutService::FOOTER_MARKER."\n".$footer];
        } elseif ($from = $versions->firstWhere('id', (int) $request->query('from'))) {
            $prefill = ['name' => $from->name.' (Kopie)', 'content' => $from->body_html.($from->footer_html !== null ? "\n".LayoutService::FOOTER_MARKER."\n".$from->footer_html : '')];
        }

        return view('layouts-admin.index', [
            'kind' => $kind,
            'tenant' => $tenant,
            'versions' => $versions,
            'active' => $versions->firstWhere('is_active', true),
            'wishes' => $tenant->{$this->wishesColumn($kind)},
            'prompt' => LayoutPrompt::build($tenant->{$this->wishesColumn($kind)}, $tenant->name, $kind),
            'prefill' => $prefill,
            'margins' => LayoutService::DEFAULT_MARGINS,
        ]);
    }

    public function wishes(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $data = $request->validate(['layout_wishes' => ['nullable', 'string', 'max:3000']]);
        $kind = $this->kind($request->input('kind'));
        Tenant::findOrFail(Tenancy::id())->update([$this->wishesColumn($kind) => trim((string) ($data['layout_wishes'] ?? '')) ?: null]);

        return redirect($this->back($kind))->with('status', __('messages.saved'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:700000'],
            'margin_top' => ['required', 'integer', 'between:0,80'],
            'margin_right' => ['required', 'integer', 'between:0,80'],
            'margin_bottom' => ['required', 'integer', 'between:0,80'],
            'margin_left' => ['required', 'integer', 'between:0,80'],
            'activate' => ['nullable', 'boolean'],
        ]);

        $kind = $this->kind($request->input('kind'));
        [$body, $footer] = LayoutService::split($data['content']);
        $tenant = Tenant::findOrFail(Tenancy::id());

        try {
            $layout = $this->layouts->createVersion($tenant, $request->user(), $data['name'], $body, $footer, [
                'top' => (int) $data['margin_top'], 'right' => (int) $data['margin_right'],
                'bottom' => (int) $data['margin_bottom'], 'left' => (int) $data['margin_left'],
            ], $kind);
        } catch (LayoutInvalid $e) {
            return redirect($this->back($kind))->withInput()->withErrors(['layout' => $e->errors]);
        }

        if ($request->boolean('activate')) {
            $this->layouts->activate($layout);
        }

        return redirect($this->back($kind))->with('status', __('messages.layouts.created_ok', ['version' => $layout->version]));
    }

    public function preview(LayoutVersion $layout, InvoicePdf $pdf): Response
    {
        $this->authorize('manage-users');

        $content = $pdf->renderSample([
            'body_html' => $layout->body_html, 'footer_html' => $layout->footer_html, 'margins' => $layout->margins(),
        ], Tenant::findOrFail(Tenancy::id()), $layout->kind);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="layout-vorschau-'.$layout->version.'.pdf"',
        ]);
    }

    public function activate(LayoutVersion $layout): RedirectResponse
    {
        $this->authorize('manage-users');
        $this->layouts->activate($layout);

        return redirect($this->back($layout->kind))->with('status', __('messages.layouts.activated', ['version' => $layout->version]));
    }

    public function useDefault(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');
        $kind = $this->kind($request->input('kind'));
        $this->layouts->useDefault(Tenant::findOrFail(Tenancy::id()), $kind);

        return redirect($this->back($kind))->with('status', __('messages.layouts.default_active'));
    }

    public function destroy(LayoutVersion $layout): RedirectResponse
    {
        $this->authorize('manage-users');

        try {
            $this->layouts->delete($layout);
        } catch (RuntimeException) {
            return redirect($this->back($layout->kind))->with('warning', __('messages.layouts.cannot_delete_active'));
        }

        return redirect($this->back($layout->kind))->with('status', __('messages.layouts.deleted'));
    }

    /** Spalte des Mandanten mit den Gestaltungswünschen der jeweiligen Art. */
    private function wishesColumn(string $kind): string
    {
        return match ($kind) {
            LayoutVersion::KIND_DUNNING => 'layout_wishes_dunning',
            LayoutVersion::KIND_QUOTE => 'layout_wishes_quote',
            default => 'layout_wishes',
        };
    }

    /** Unbekannte Werte fallen auf „Rechnung“ zurück. */
    private function kind(mixed $value): string
    {
        return in_array($value, LayoutVersion::KINDS, true) ? $value : LayoutVersion::KIND_INVOICE;
    }

    /** Zurück zur Seite der jeweiligen Art; die Rechnungsseite bleibt unter /layouts erreichbar. */
    private function back(string $kind): string
    {
        return route('layouts.index', $kind === LayoutVersion::KIND_INVOICE ? [] : ['kind' => $kind]);
    }
}
