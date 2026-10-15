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
@section('title', __('messages.nav.mail'))
@section('content')
    <h1>{{ __('messages.nav.mail') }} <span class="badge">{{ $tenant->name }}</span></h1>
    <p class="muted">{{ __('messages.mail.intro') }}</p>

    <form method="POST" action="{{ route('mail.update') }}">
        @csrf

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.mail.account_section') }}</h2>
            <x-select name="mail_account_id" :label="__('messages.mail.account')" :value="$tenant->mail_account_id"
                      :blank="__('messages.mail.no_account')"
                      :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->name.' ('.$a->from_address.')'])->all()" />
            @if($accounts->isEmpty())
                <p class="muted">{{ __('messages.mail.no_accounts_yet') }} <a href="{{ route('mail-accounts.create') }}">{{ __('messages.mail_accounts.new') }}</a></p>
            @endif
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.mail.rights_section') }}</h2>
            <p class="muted">{{ __('messages.mail.rights_help') }}</p>
            <table class="matrix">
                <thead>
                <tr>
                    <th></th>
                    <th>{{ __('messages.roles.admin') }}</th>
                    @foreach($grantable as $role)<th>{{ __('messages.roles.'.$role) }}</th>@endforeach
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>{{ __('messages.mail.right_send') }}</td>
                    <td><input type="checkbox" checked disabled aria-label="{{ __('messages.roles.admin') }}"></td>
                    @foreach($grantable as $role)
                        <td><input type="checkbox" name="mail_send_roles[]" value="{{ $role }}" aria-label="{{ __('messages.roles.'.$role) }}"
                                   @checked(in_array($role, old('mail_send_roles', $tenant->mailRoles()), true))></td>
                    @endforeach
                </tr>
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.mail.texts_section') }}</h2>
            <p class="muted">{{ __('messages.mail.texts_help') }}</p>
            <p class="muted placeholder-list">
                @foreach($placeholders as $name)<code>{{ '{'.$name.'}' }}</code> @endforeach
            </p>

            @foreach(['invoice' => 'mail.for_invoice', 'credit' => 'mail.for_credit', 'quote' => 'mail.for_quote'] as $kind => $heading)
                <h3>{{ __('messages.'.$heading) }}</h3>
                <x-field :name="'mail_'.$kind.'_subject'" :label="__('messages.mail.subject')" :value="$texts['mail_'.$kind.'_subject']" maxlength="255" />
                <div class="field">
                    <label for="mail_{{ $kind }}_body">{{ __('messages.mail.body') }}
                        <span class="tip" data-tip="{{ __('messages.mail.reset_tip') }}" tabindex="0" aria-label="{{ __('messages.mail.reset_tip') }}">ⓘ</span>
                    </label>
                    <textarea id="mail_{{ $kind }}_body" name="mail_{{ $kind }}_body" rows="9">{{ old('mail_'.$kind.'_body', $texts['mail_'.$kind.'_body']) }}</textarea>
                    @error('mail_'.$kind.'_body')<div class="error">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>

        <button class="btn" type="submit">{{ __('messages.save') }}</button>
    </form>
@endsection
