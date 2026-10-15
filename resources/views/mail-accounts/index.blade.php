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
                <th>{{ __('messages.mail_accounts.name') }}</th>
                <th>{{ __('messages.mail_accounts.server') }}</th>
                <th>{{ __('messages.mail_accounts.from_address') }}</th>
                <th>{{ __('messages.mail_accounts.bcc') }}</th>
                <th>{{ __('messages.mail_accounts.used_by') }}</th>
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
                        <a class="btn small secondary" href="{{ route('mail-accounts.edit', $account) }}">{{ __('messages.edit') }}</a>
                        <form method="POST" action="{{ route('mail-accounts.destroy', $account) }}" onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn small danger" type="submit">{{ __('messages.delete') }}</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
