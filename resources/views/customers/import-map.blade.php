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
@section('title', __('messages.customer_import.title'))
@section('content')
    <h1>{{ __('messages.customer_import.title') }}</h1>
    <p class="muted">{{ __('messages.customer_import.map_intro', ['rows' => count($parsed['rows'])]) }}</p>

    <form method="POST" action="{{ route('customers.import.preview', $token) }}">
        @csrf
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customer_import.step_map') }}</h2>
            <table class="matrix-map">
                <thead>
                <tr>
                    <th>{{ __('messages.customer_import.field') }}</th>
                    <th>{{ __('messages.customer_import.column') }}</th>
                    <th>{{ __('messages.customer_import.example') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach($fields as $field)
                    @php($selected = $mapping[$field] ?? null)
                    <tr>
                        <td>{{ $importer->label($field) }}@if(in_array($field, ['street', 'zip', 'city', 'email'], true)) *@endif</td>
                        <td>
                            <select name="map[{{ $field }}]">
                                <option value="">{{ __('messages.customer_import.not_imported') }}</option>
                                @foreach($parsed['headers'] as $index => $header)
                                    <option value="{{ $index }}" @selected((string) $selected === (string) $index)>{{ $header !== '' ? $header : __('messages.customer_import.column_n', ['n' => $index + 1]) }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="muted">{{ $selected !== null ? ($parsed['rows'][0][$selected] ?? '') : '' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn" type="submit">{{ __('messages.customer_import.preview') }}</button>
        <a class="btn secondary" href="{{ route('customers.import') }}">{{ __('messages.cancel') }}</a>
    </form>
@endsection
