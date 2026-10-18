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
@section('title', __('messages.nav.numbering'))
@section('content')
    <h1>{{ __('messages.nav.numbering') }}</h1>
    <p class="muted">{{ __('messages.numbering.intro') }}</p>

    <table>
        <thead>
        <tr>
            <x-th-sort :sort="$sort" col="type">{{ __('messages.invoices.type') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="format">{{ __('messages.numbering.format') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="last">{{ __('messages.numbering.last') }}</x-th-sort>
            <x-th-sort :sort="$sort" col="next">{{ __('messages.numbering.next') }}</x-th-sort>
            <th>{{ __('messages.numbering.set_last') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($rows as $row)
            <tr>
                <td>
                    {{ match ($row['type']) { 'customer' => __('messages.numbering.customers'), 'invoice' => __('messages.invoices.invoice'), 'quote' => __('messages.quotes.title_single'), default => __('messages.invoices.credit_note') } }}
                    @if($row['is_test'])<span class="badge">{{ __('messages.testmode.on') }}</span>@endif
                </td>
                <td class="mono">{{ $row['format'] === null ? __('messages.numbering.plain_number') : ($row['is_test'] ? 'TEST-' : '').$row['format'] }}</td>
                <td class="mono">{{ $row['last_number'] ?? '–' }}</td>
                <td class="mono">{{ $row['next_number'] }}</td>
                <td>
                    <form method="POST" action="{{ route('numbering.update') }}" style="display:flex;gap:.4rem">
                        @csrf
                        <input type="hidden" name="type" value="{{ $row['type'] }}">
                        <input type="hidden" name="is_test" value="{{ $row['is_test'] ? 1 : 0 }}">
                        <input type="number" name="last" min="0" value="{{ $row['last'] }}" style="width:7rem;padding:.35rem .5rem;border:1px solid var(--line);border-radius:5px">
                        <button class="btn small" type="submit">{{ __('messages.save') }}</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @error('last')<div class="alert error" style="margin-top:1rem">{{ $message }}</div>@enderror
    <p class="hint muted" style="margin-top:1rem">{{ __('messages.numbering.set_last_hint') }}</p>
@endsection
