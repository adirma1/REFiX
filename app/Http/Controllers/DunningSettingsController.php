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

use App\Models\Tenant;
use App\Services\DunningService;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Einstellungen des Mahnwesens für den aktuellen Mandanten (nur Admin). */
class DunningSettingsController extends Controller
{
    public function edit(): View
    {
        $this->authorize('manage-users');

        $tenant = Tenant::findOrFail(Tenancy::id());

        return view('dunning.settings', [
            'tenant' => $tenant,
            'placeholders' => DunningService::PLACEHOLDERS,
            'texts' => [1 => $this->text($tenant, 1), 2 => $this->text($tenant, 2), 3 => $this->text($tenant, 3)],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        foreach (['dunning_fee_1', 'dunning_fee_2', 'dunning_fee_3', 'dunning_base_rate', 'dunning_interest_margin'] as $key) {
            $request->merge([$key => $this->decimal($request->input($key))]);
        }

        $placeholderRule = function (string $attribute, mixed $value, \Closure $fail) {
            if ($unknown = DunningService::unknownPlaceholders((string) $value)) {
                $fail(__('messages.mail.unknown_placeholder', ['names' => '{'.implode('}, {', $unknown).'}']));
            }
        };

        $data = $request->validate([
            'dunning_days_after_due' => ['required', 'integer', 'min:0', 'max:365'],
            'dunning_grace_days' => ['required', 'integer', 'min:1', 'max:90'],
            'dunning_fee_1' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'dunning_fee_2' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'dunning_fee_3' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'dunning_interest_enabled' => ['nullable', 'boolean'],
            'dunning_base_rate' => ['nullable', 'numeric', 'min:-10', 'max:50', 'required_if:dunning_interest_enabled,1'],
            'dunning_interest_margin' => ['nullable', 'numeric', 'min:0', 'max:30'],
            'dunning_text_1' => ['nullable', 'string', 'max:5000', $placeholderRule],
            'dunning_text_2' => ['nullable', 'string', 'max:5000', $placeholderRule],
            'dunning_text_3' => ['nullable', 'string', 'max:5000', $placeholderRule],
        ]);

        $update = [
            'dunning_days_after_due' => (int) $data['dunning_days_after_due'],
            'dunning_grace_days' => (int) $data['dunning_grace_days'],
            'dunning_fee_1' => $data['dunning_fee_1'] ?? 0,
            'dunning_fee_2' => $data['dunning_fee_2'] ?? 0,
            'dunning_fee_3' => $data['dunning_fee_3'] ?? 0,
            'dunning_interest_enabled' => (bool) ($data['dunning_interest_enabled'] ?? false),
            'dunning_base_rate' => $data['dunning_base_rate'] ?? null,
            'dunning_interest_margin' => $data['dunning_interest_margin'] ?? 9,
        ];

        foreach ([1, 2, 3] as $level) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['dunning_text_'.$level] ?? '')));
            // Leer oder wie der Standard: nichts speichern, damit spätere Verbesserungen des Standards wirken.
            $update['dunning_text_'.$level] = ($text === '' || $text === DunningService::defaultText($level)) ? null : $text;
        }

        // Nur auswerten, wenn das Feld im Formular war (sonst bleibt der bisherige Wert).
        if ($request->has('dunning_enabled_present')) {
            $update['dunning_enabled'] = $request->boolean('dunning_enabled');
        }

        Tenant::findOrFail(Tenancy::id())->update($update);

        return redirect()->route('dunning.settings')->with('status', __('messages.saved'));
    }

    private function text(Tenant $tenant, int $level): string
    {
        $value = $tenant->{'dunning_text_'.$level};

        return filled($value) ? (string) $value : DunningService::defaultText($level);
    }
}
