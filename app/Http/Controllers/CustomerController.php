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

use App\Models\Customer;
use App\Models\Tenant;
use App\Services\NumberGenerator;
use App\Support\CustomerFilter;
use App\Support\CustomerRules;
use App\Support\ListFilter;
use App\Support\Tenancy;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $options = CustomerFilter::options();
        ['search' => $search, 'filters' => $filters] = CustomerFilter::fromRequest($request, $options);

        $customers = CustomerFilter::query($search, $filters)
            ->paginate(PerPage::for($request->user()))
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers, 'search' => $search, 'filters' => $filters,
            'countries' => $options['countries'], 'currencies' => $options['currencies'], 'tags' => $options['tags'],
            'filtered' => ListFilter::active($filters + ['q' => $search]),
        ]);
    }

    public function create(): View
    {
        $this->authorize('edit-master-data');

        return view('customers.form', ['customer' => new Customer([
            'country' => 'DE', 'currency' => 'EUR', 'active' => true, 'customer_since' => now()->startOfDay(),
            // Vorschlag: nächste freie Nummer aus dem Zähler (wird beim Speichern nötigenfalls neu vergeben).
            'customer_number' => NumberGenerator::peekCustomerNumber(Tenant::findOrFail(Tenancy::id())),
        ])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-master-data');

        $data = $this->validated($request);

        // Kundennummer: leer oder schon vergeben → nächste freie Nummer aus dem Zähler.
        $wanted = (string) ($data['customer_number'] ?? '');
        $changed = false;
        if ($wanted === '' || Customer::withTrashed()->where('customer_number', $wanted)->exists()) {
            $data['customer_number'] = NumberGenerator::nextCustomerNumber(Tenant::findOrFail(Tenancy::id()));
            $changed = $wanted !== '';
        }

        $customer = Customer::create($data);

        return redirect()->route('customers.index')
            ->with('status', __('messages.customers.created', ['name' => $customer->company])
                .($changed ? ' '.__('messages.customers.number_reassigned', ['old' => $wanted, 'new' => $customer->customer_number]) : ''));
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('edit-master-data');

        $customer->update($this->validated($request, $customer));

        return redirect()->route('customers.index')
            ->with('status', __('messages.customers.updated', ['name' => $customer->company]));
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete-master-data');

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('status', __('messages.customers.deleted', ['name' => $customer->company]));
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        $request->merge(CustomerRules::normalize($request->all()));

        $rules = CustomerRules::rules([
            Rule::unique('customers', 'customer_number')
                ->where('tenant_id', Tenancy::id())
                ->ignore($customer?->id),
        ], $request->all());

        // Neuer Kunde: Nummer darf fehlen oder schon existieren, dann wird sie automatisch vergeben (siehe store()).
        if ($customer === null) {
            $rules['customer_number'] = ['nullable', 'string', 'max:30'];
        }

        $data = $request->validate($rules);

        $data = $this->emptyToNull($data);
        // Ohne Häkchen im Formular kommt nur der versteckte Wert „0“; fehlt das Feld ganz, bleibt der Zustand unverändert.
        if (array_key_exists('active', $data)) {
            $data['active'] = $request->boolean('active');
        }
        // Leeres „Kunde seit“: neue Kunden bekommen heute (Model), bestehende behalten ihren Wert.
        if (($data['customer_since'] ?? null) === null) {
            unset($data['customer_since']);
        }

        return $data;
    }
}
