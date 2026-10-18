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
@section('title', __('messages.nav.mail_accounts'))
@section('content')
    <div class="toolbar">
        <h1 style="margin:0">{{ __('messages.nav.mail_accounts') }}</h1>
        <span class="spacer"></span>
        <a class="btn" href="{{ route('mail-accounts.create') }}">{{ __('messages.mail_accounts.new') }}</a>
    </div>
    <p class="muted">{{ __('messages.mail_accounts.intro') }}</p>

    @if($accounts->isEmpty())
        <p class="muted">{{ __('messages.mail_accounts.empty') }}</p>
    @else
        <table class="wrap-cells">
            <thead>
            <tr>
                <x-th-sort :sort="$sort" col="name">{{ __('messages.mail_accounts.name') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="server">{{ __('messages.mail_accounts.server') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="from">{{ __('messages.mail_accounts.from_address') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="bcc">{{ __('messages.mail_accounts.bcc') }}</x-th-sort>
                <x-th-sort :sort="$sort" col="used_by">{{ __('messages.mail_accounts.used_by') }}</x-th-sort>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach($accounts as $account)
                <tr>
                    <td>{{ $account->name }}</td>
                    <td class="mono">{{ $account->host }}:{{ $account->port }} ({{ __('messages.mail_accounts.enc.'.$account->encryption) }})</td>
                    <td>{{ $account->from_address }}</td>
                    <td>{{ $account->bcc ?: '–' }}</td>
                    <td>{{ $account->tenants_count }}</td>
                    <td class="actions">
                        <div class="icon-actions">
                            <x-icon-btn kind="edit" :href="route('mail-accounts.edit', $account)"/>
                            <form method="POST" action="{{ route('mail-accounts.destroy', $account) }}" onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                                @csrf
                                @method('DELETE')
                                <x-icon-btn kind="delete"/>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
