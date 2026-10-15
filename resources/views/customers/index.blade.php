{{--
    RE-FiX – Rechnungsprogramm
    Copyright (C) 2026 Martin Adirsch

    This program is free software; you can redistribute it and/or modify it under the terms of
    version 2 of the GNU General Public License as published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
    without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
    See the GNU General Public License for more details.

    You should have received a copy of the GNU General Public License along with this program
    (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.

    SPDX-License-Identifier: GPL-2.0-only
--}}@extends('layouts.app')
@section('title', __('messages.nav.customers'))
@section('content')
    <h1>{{ __('messages.nav.customers') }}</h1>
    <div class="toolbar">
        <form method="GET" action="{{ route('customers.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('messages.search') }}">
            <select name="kind" aria-label="{{ __('messages.filter.kind') }}">
                <option value="">{{ __('messages.filter.kind_all') }}</option>
                <option value="company" @selected($filters['kind'] === 'company')>{{ __('messages.filter.kind_company') }}</option>
                <option value="private" @selected($filters['kind'] === 'private')>{{ __('messages.filter.kind_private') }}</option>
            </select>
            <select name="country" aria-label="{{ __('messages.customers.country') }}">
                <option value="">{{ __('messages.filter.country_all') }}</option>
                @foreach($countries as $country)
                    <option value="{{ $country }}" @selected($filters['country'] === $country)>{{ $country }}</option>
                @endforeach
            </select>
            <select name="currency" aria-label="{{ __('messages.filter.currency') }}">
                <option value="">{{ __('messages.filter.currency_all') }}</option>
                @foreach($currencies as $currency)
                    <option value="{{ $currency }}" @selected($filters['currency'] === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
            <select name="ref" aria-label="{{ __('messages.filter.reference') }}">
                <option value="">{{ __('messages.filter.reference_all') }}</option>
                <option value="1" @selected($filters['ref'] === '1')>{{ __('messages.filter.reference_set') }}</option>
            </select>
            <select name="status" aria-label="{{ __('messages.customers.status') }}">
                <option value="">{{ __('messages.filter.status_all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('messages.filter.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('messages.filter.inactive') }}</option>
            </select>
            @if(count($tags))
                <select name="tag" aria-label="{{ __('messages.customers.tags') }}">
                    <option value="">{{ __('messages.filter.tag_all') }}</option>
                    @foreach($tags as $tag)
                        <option value="{{ $tag }}" @selected($filters['tag'] === $tag)>{{ $tag }}</option>
                    @endforeach
                </select>
            @endif
            <button class="btn secondary" type="submit">{{ __('messages.search') }}</button>
            @if($filtered)
                <a class="btn secondary" href="{{ route('customers.index') }}">{{ __('messages.filter.reset') }}</a>
            @endif
        </form>
        <span class="spacer"></span>
        @can('edit-master-data')
            <a class="btn secondary" href="{{ route('customers.export', request()->query()) }}">{{ __('messages.customer_export.button') }}</a>
            <a class="btn secondary" href="{{ route('customers.import') }}">{{ __('messages.customer_import.button') }}</a>
            <a class="btn" href="{{ route('customers.create') }}">{{ __('messages.customers.new') }}</a>
        @endcan
    </div>

    <table>
        <thead>
        <tr>
            <th>{{ __('messages.customers.number') }}</th>
            <th>{{ __('messages.customers.company') }}</th>
            <th>{{ __('messages.customers.city') }}</th>
            <th>{{ __('messages.customers.email') }}</th>
            <th>{{ __('messages.customers.status') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($customers as $customer)
            <tr>
                <td>{{ $customer->customer_number }}</td>
                <td>{{ $customer->company }}<div class="muted">{{ $customer->contactLine() }}</div>
                    @if($customer->website)<div class="muted"><a href="{{ $customer->website }}" target="_blank" rel="noopener noreferrer">{{ preg_replace('#^https?://(www\.)?#i', '', rtrim($customer->website, '/')) }}</a></div>@endif</td>
                <td>{{ $customer->zip }} {{ $customer->city }}</td>
                <td>{{ $customer->email }}</td>
                <td>
                    @if($customer->active)
                        <span class="badge ok">{{ __('messages.filter.active') }}</span>
                    @else
                        <span class="badge">{{ __('messages.filter.inactive') }}</span>
                    @endif
                    @if($customer->tags)<div class="muted">{{ $customer->tags }}</div>@endif
                    @if($customer->customer_until)<div class="muted">{{ __('messages.customers.until_short', ['date' => $customer->customer_until->format('d.m.Y')]) }}</div>@endif
                </td>
                <td class="actions">
                    <a class="btn small secondary" href="{{ route('customers.edit', $customer) }}">{{ __('messages.edit') }}</a>
                    @can('delete-master-data')
                        <form method="POST" action="{{ route('customers.destroy', $customer) }}"
                              onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn small danger" type="submit">{{ __('messages.delete') }}</button>
                        </form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('messages.customers.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @include('pagination.footer', ['paginator' => $customers, 'id' => 'customers'])
@endsection
