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
@section('title', __('messages.customer_export.title'))
@section('medium', '1')
@section('content')
    <h1>{{ __('messages.customer_export.title') }}</h1>
    <p class="muted">{{ __('messages.customer_export.intro') }}</p>

    <form method="POST" action="{{ route('customers.export.download', request()->query()) }}">
        @csrf

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customer_export.which') }}</h2>
            <div class="field check">
                <label><input type="radio" name="scope" value="selection" @checked(old('scope', $filtered ? 'selection' : 'all') === 'selection')>
                    {{ __('messages.customer_export.scope_selection', ['n' => $selectionCount]) }}
                    @if($summary !== '')<span class="muted">({{ $summary }})</span>@endif</label>
            </div>
            <div class="field check">
                <label><input type="radio" name="scope" value="all" @checked(old('scope', $filtered ? 'selection' : 'all') === 'all')>
                    {{ __('messages.customer_export.scope_all', ['n' => $totalCount]) }}</label>
            </div>
            <div class="field check">
                <label><input type="checkbox" name="with_deleted" value="1" @checked(old('with_deleted'))>
                    {{ __('messages.customer_export.with_deleted') }}</label>
            </div>
            @error('scope')<div class="error">{{ $message }}</div>@enderror
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customer_export.fields') }}
                <span class="badge" id="fields-count" title="{{ __('messages.customer_export.fields_count') }}"></span></h2>
            @php $chosen = old('fields', $prefs['fields']); @endphp
            @foreach($groups as $group => $fields)
                <fieldset data-group style="border:0;padding:0;margin:0 0 1rem">
                    <legend style="font-weight:600;padding:0;margin-bottom:.35rem">
                        {{ __('messages.customer_export.group_'.$group) }}
                        <button type="button" class="btn small secondary" data-group-toggle="all">{{ __('messages.customer_export.all') }}</button>
                        <button type="button" class="btn small secondary" data-group-toggle="none">{{ __('messages.customer_export.none') }}</button>
                    </legend>
                    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr))">
                        @foreach($fields as $field)
                            <div class="field check" style="margin-bottom:.3rem">
                                <label><input type="checkbox" name="fields[]" value="{{ $field }}" @checked(in_array($field, $chosen, true))>
                                    {{ \App\Services\CustomerExporter::label($field) }}</label>
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            @error('fields')<div class="error">{{ $message }}</div>@enderror
            <p class="hint muted" style="margin:0">{{ __('messages.customer_export.order_hint') }}</p>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.customer_export.format') }}</h2>
            <div class="grid">
                <x-select name="delimiter" :label="__('messages.customer_export.delimiter')" :value="old('delimiter', $prefs['delimiter'])"
                          :options="['semicolon' => __('messages.customer_export.delim_semicolon'), 'comma' => __('messages.customer_export.delim_comma'), 'tab' => __('messages.customer_export.delim_tab')]" />
                <x-select name="encoding" :label="__('messages.customer_export.encoding')" :value="old('encoding', $prefs['encoding'])"
                          :options="['utf8' => __('messages.customer_export.enc_utf8'), 'cp1252' => __('messages.customer_export.enc_cp1252')]" />
                <x-select name="date_format" :label="__('messages.customer_export.date_format')" :value="old('date_format', $prefs['date_format'])"
                          :options="['de' => __('messages.customer_export.date_de'), 'iso' => __('messages.customer_export.date_iso')]" />
            </div>
            <p class="hint muted" style="margin:0">{{ __('messages.customer_export.format_hint') }}</p>
        </div>

        <button class="btn" type="submit" id="export-submit">{{ __('messages.customer_export.download') }}</button>
        <a class="btn secondary" href="{{ route('customers.index', request()->query()) }}">{{ __('messages.cancel') }}</a>
    </form>
@endsection
@push('scripts')
    <script src="{{ asset('js/export-fields.js') }}?v={{ filemtime(public_path('js/export-fields.js')) }}"></script>
@endpush
