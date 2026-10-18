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
use App\Services\NumberGenerator;
use App\Rules\IbanRule;
use App\Support\Iban;
use App\Support\ListSort;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantController extends Controller
{
    /** Mandanten, in denen der Benutzer Admin ist. */
    public function index(Request $request): View
    {
        $sort = ListSort::make($request, 'tenants', [
            'name' => 'name', 'city' => fn ($t) => $t->zip.' '.$t->city, 'mode' => fn ($t) => (int) $t->test_mode,
        ], ['name', 'asc']);

        return view('tenants.index', ['sort' => $sort, 'tenants' => $sort->sortCollection($this->adminTenants($request))]);
    }

    public function create(): View
    {
        return view('tenants.form', ['tenant' => new Tenant(['country' => 'DE', 'default_payment_days' => 14, 'invoice_number_format' => 'R-{YYYY}-{NNNN}', 'credit_note_number_format' => 'G-{YYYY}-{NNNN}', 'quote_number_format' => 'A-{YYYY}-{NNNN}', 'quote_valid_days' => 30])]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->adminTenants($request)->isNotEmpty(), 403);

        $tenant = DB::transaction(function () use ($request) {
            $tenant = Tenant::create(Arr::except($this->validated($request), ['logo', 'remove_logo']));
            $tenant->users()->attach($request->user()->id, ['role' => 'admin']);
            $this->storeLogo($request, $tenant);

            return $tenant;
        });

        $request->user()->forgetRoleCache();

        return redirect()->route('tenants.index')
            ->with('status', __('messages.tenants.created', ['name' => $tenant->name]));
    }

    public function edit(Request $request, Tenant $tenant): View
    {
        $this->ensureAdmin($request, $tenant);

        return view('tenants.form', ['tenant' => $tenant]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $this->ensureAdmin($request, $tenant);

        $tenant->update(Arr::except($this->validated($request), ['logo', 'remove_logo']));
        $this->storeLogo($request, $tenant);

        return redirect()->route('tenants.index')
            ->with('status', __('messages.tenants.updated', ['name' => $tenant->name]));
    }

    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($request->user()->tenants()->whereKey($tenant->id)->exists(), 403);

        $request->session()->put('tenant_id', $tenant->id);

        return redirect()->route('dashboard');
    }

    private function storeLogo(Request $request, Tenant $tenant): void
    {
        if ($request->boolean('remove_logo') && $tenant->logo_path) {
            Storage::disk('local')->delete($tenant->logo_path);
            $tenant->update(['logo_path' => null]);
        }

        if ($request->hasFile('logo')) {
            if ($tenant->logo_path) {
                Storage::disk('local')->delete($tenant->logo_path);
            }

            $path = $request->file('logo')->store('logos/'.$tenant->id, 'local');
            $tenant->update(['logo_path' => $path]);
        }
    }

    private function adminTenants(Request $request)
    {
        return $request->user()->tenants()->wherePivot('role', 'admin')->orderBy('name')->get();
    }

    private function ensureAdmin(Request $request, Tenant $tenant): void
    {
        abort_unless(
            $request->user()->tenants()->whereKey($tenant->id)->wherePivot('role', 'admin')->exists(),
            403
        );
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'country' => strtoupper((string) $request->input('country')),
            'iban' => Iban::normalize($request->input('iban')) ?: null,
            'bic' => strtoupper(str_replace(' ', '', (string) $request->input('bic'))) ?: null,
            'vat_id' => strtoupper(str_replace(' ', '', (string) $request->input('vat_id'))) ?: null,
            'test_mode' => $request->boolean('test_mode'),
            'vat_free' => $request->boolean('vat_free'),
            'vat_free_note' => trim((string) $request->input('vat_free_note')) ?: null,
        ]);

        // Nur auswerten, wenn das Feld im Formular war (sonst bleibt der bisherige Wert).
        foreach (['show_due_text', 'show_customer_number', 'show_service_date'] as $switch) {
            if ($request->has($switch)) {
                $request->merge([$switch => $request->boolean($switch)]);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'vat_id' => ['nullable', 'string', 'max:20'],
            'tax_number' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'max:255'], // Freitext, wird so in der Fußzeile gedruckt (z. B. „Email: …“)
            'phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', new IbanRule],
            'bic' => ['nullable', 'string', 'regex:/^[A-Z0-9]{8}([A-Z0-9]{3})?$/'],
            'court' => ['nullable', 'string', 'max:255'],
            'register_number' => ['nullable', 'string', 'max:40'],
            'managing_director' => ['nullable', 'string', 'max:255'],
            'default_payment_days' => ['required', 'integer', 'min:0', 'max:365'],
            'test_mode' => ['boolean'],
            'vat_free' => ['boolean'],
            'show_due_text' => ['sometimes', 'boolean'],
            'show_customer_number' => ['sometimes', 'boolean'],
            'show_service_date' => ['sometimes', 'boolean'],
            'vat_free_note' => [Rule::requiredIf($request->boolean('vat_free')), 'nullable', 'string', 'max:500'],
            'invoice_number_format' => ['sometimes', 'required', 'string', 'max:60', $this->numberFormatRule()],
            'credit_note_number_format' => ['sometimes', 'required', 'string', 'max:60', $this->numberFormatRule()],
            'quote_number_format' => ['sometimes', 'required', 'string', 'max:60', $this->numberFormatRule()],
            'quote_valid_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'invoice_intro' => ['nullable', 'string', 'max:5000'],
            'invoice_footer' => ['nullable', 'string', 'max:5000'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        return $this->emptyToNull($data);
    }

    private function numberFormatRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (! NumberGenerator::validateFormat((string) $value)) {
                $fail(__('messages.numbering.invalid_format'));
            }
        };
    }
}
