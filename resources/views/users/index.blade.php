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
@section('title', __('messages.nav.users'))
@section('content')
    <h1>{{ __('messages.nav.users') }}</h1>

    @if(session('temp_password'))
        <div class="alert warn">
            <strong>{{ __('messages.users.reset_done', ['name' => session('temp_password')['name']]) }}</strong>
            <div style="margin:.5rem 0"><code style="font-size:1.15rem;letter-spacing:.05em">{{ session('temp_password')['password'] }}</code></div>
            {{ __('messages.users.reset_hint') }}
        </div>
    @endif

    <div class="toolbar" data-pick-actions>
        <button class="btn small" type="button" data-act="edit" data-event disabled>{{ __('messages.users.edit') }}</button>
        <button class="btn small secondary" type="button" data-act="reset" data-method="post"
                data-confirm="{{ __('messages.users.reset_confirm') }}" disabled>{{ __('messages.users.reset_password') }}</button>
        <button class="btn small danger" type="button" data-act="remove" data-method="delete"
                data-confirm="{{ __('messages.confirm_delete') }}" disabled>{{ __('messages.users.remove') }}</button>
        <span class="muted" id="pick-hint">{{ __('messages.users.pick_first') }}</span>
    </div>

    <div class="card hold-panel" id="edit-panel" hidden>
        <h2 style="margin-top:0" id="edit-title"></h2>
        <form method="POST" id="edit-form" action="">
            @csrf
            @method('PATCH')
            <div class="grid">
                <x-field name="username" :label="__('messages.users.username')" :tip="__('messages.users.username_hint')" autocomplete="off" maxlength="50" />
                <x-select name="role" :label="__('messages.users.role')"
                          :options="collect($roles)->mapWithKeys(fn ($r) => [$r => __('messages.roles.'.$r)])->all()" required />
            </div>
            <button class="btn" type="submit">{{ __('messages.save') }}</button>
            <button class="btn secondary" type="button" id="edit-cancel">{{ __('messages.cancel') }}</button>
        </form>
    </div>

    <table class="users-table">
        <thead>
        <tr>
            <th class="pick-col"></th>
            <th>{{ __('messages.users.name') }}</th>
            <th>{{ __('messages.users.username') }}</th>
            <th>{{ __('messages.users.email') }}</th>
            <th>{{ __('messages.users.role') }}</th>
            <th>{{ __('messages.two_factor.title') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($users as $user)
            <tr data-pick>
                <td class="pick-col">
                    <input type="radio" class="pick-one" name="user" value="{{ $user->id }}" aria-label="{{ __('messages.users.pick_row') }}"
                           data-edit="{{ route('users.update', $user) }}"
                           @if($user->id !== auth()->id()) data-reset="{{ route('users.reset-password', $user) }}" @endif
                           data-remove="{{ route('users.destroy', $user) }}"
                           data-name="{{ $user->name }}" data-username="{{ $user->username }}" data-role="{{ $user->pivot->role }}">
                </td>
                <td><strong>{{ $user->name }}</strong></td>
                <td>{{ $user->username }}</td>
                <td>{!! str_replace('@', '@<wbr>', e($user->email)) !!}</td>
                <td>{{ __('messages.roles.'.$user->pivot->role) }}</td>
                <td>
                    @if($user->hasTwoFactor())
                        <span class="badge ok">{{ __('messages.two_factor.active') }}</span>
                    @elseif(! \App\Support\TwoFactorPolicy::requiredFor($user))
                        <span class="badge">{{ __('messages.two_factor.optional_off') }}</span>
                    @else
                        <span class="badge warn">{{ __('messages.two_factor.inactive') }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <form id="pick-form" method="POST" action="{{ route('users.index') }}" hidden>@csrf<input type="hidden" name="_method" value="POST" id="pick-method"></form>

    <div class="card" style="margin-top:1.5rem">
        <h2 style="margin-top:0">{{ __('messages.users.add') }}</h2>
        <p class="muted">{{ __('messages.users.add_hint') }}</p>
        <form method="POST" action="{{ route('users.store') }}">
            @csrf
            <div class="grid">
                <x-field name="email" type="email" :label="__('messages.users.email')" required />
                <x-field name="name" :label="__('messages.users.name')" />
                <x-field name="username" :label="__('messages.users.username')" :tip="__('messages.users.username_hint')" autocomplete="off" maxlength="50" />
                <x-field name="password" type="password" :label="__('messages.users.initial_password')"
                         :tip="__('messages.password_hint')" autocomplete="new-password" />
                <x-select name="role" :label="__('messages.users.role')"
                          :options="collect($roles)->mapWithKeys(fn ($r) => [$r => __('messages.roles.'.$r)])->all()"
                          value="clerk" required />
            </div>
            <button class="btn" type="submit">{{ __('messages.users.add') }}</button>
        </form>
    </div>
@push('scripts')
    <script src="{{ asset('js/rowpick.js') }}?v={{ filemtime(public_path('js/rowpick.js')) }}"></script>
    <script>
        (function () {
            var panel = document.getElementById('edit-panel'), form = document.getElementById('edit-form');
            var title = document.getElementById('edit-title');
            var hide = function () { panel.hidden = true; };
            document.addEventListener('pick-change', hide);
            document.getElementById('edit-cancel').addEventListener('click', hide);
            document.addEventListener('pick-act', function (e) {
                var r = e.detail.radio;
                form.action = r.dataset.edit;
                form.elements.username.value = r.dataset.username || '';
                form.elements.role.value = r.dataset.role;
                title.textContent = @json(__('messages.users.editing')) + ' ' + r.dataset.name;
                panel.hidden = false;
                form.elements.username.focus();
            });
        })();
    </script>
@endpush
@endsection
