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
--}}<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') – {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('img/refix-logo.png') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body>
<div class="shell">
<div class="topbar">
    <a class="brand" href="{{ route('dashboard') }}"><img src="{{ asset('img/refix-logo.png') }}" alt="" height="30"><span>{{ config('app.brand') }}</span></a>
    @php($fullAccess = ! auth()->user()->must_change_password && (auth()->user()->hasTwoFactor() || ! \App\Support\TwoFactorPolicy::requiredFor(auth()->user())))
    @if($fullAccess)
        <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>{{ __('messages.nav.dashboard') }}</a>
        <a href="{{ route('quotes.index') }}" @class(['active' => request()->routeIs('quotes.*')])>{{ __('messages.nav.quotes') }}</a>
        <a href="{{ route('invoices.index') }}" @class(['active' => request()->routeIs('invoices.*')])>{{ __('messages.nav.invoices') }}</a>
        <a href="{{ route('recurring.index') }}" @class(['active' => request()->routeIs('recurring.*')])>{{ __('messages.nav.recurring') }}</a>
        @can('manage-payments')
            <a href="{{ route('payments.index') }}" @class(['active' => request()->routeIs('payments.*', 'bank.*', 'dunning.index', 'dunning.mail')])>{{ __('messages.nav.payments') }}</a>
        @endcan
        <a href="{{ route('customers.index') }}" @class(['active' => request()->routeIs('customers.*')])>{{ __('messages.nav.customers') }}</a>
        <a href="{{ route('articles.index') }}" @class(['active' => request()->routeIs('articles.*')])>{{ __('messages.nav.articles') }}</a>
        @can('manage-users')
            <details @class(['dropdown', 'active' => request()->routeIs('users.*', 'tenants.*', 'numbering.*', 'layouts.*', 'mail.*', 'mail-accounts.*', 'dunning.settings*', 'backups.*', 'audit.*')]) @if(request()->routeIs('users.*', 'tenants.*', 'numbering.*', 'layouts.*', 'mail.*', 'mail-accounts.*', 'dunning.settings*', 'backups.*', 'audit.*')) open @endif>
                <summary>{{ __('messages.nav.settings') }}</summary>
                <div class="menu">
                    <a href="{{ route('users.index') }}" @class(['active' => request()->routeIs('users.*')])>{{ __('messages.nav.users') }}</a>
                    <a href="{{ route('tenants.index') }}" @class(['active' => request()->routeIs('tenants.*')])>{{ __('messages.nav.tenants') }}</a>
                    <a href="{{ route('numbering.index') }}" @class(['active' => request()->routeIs('numbering.*')])>{{ __('messages.nav.numbering') }}</a>
                    <a href="{{ route('layouts.index') }}" @class(['active' => request()->routeIs('layouts.*')])>{{ __('messages.nav.layouts') }}</a>
                    <a href="{{ route('mail.edit') }}" @class(['active' => request()->routeIs('mail.*')])>{{ __('messages.nav.mail') }}</a>
                    <a href="{{ route('mail-accounts.index') }}" @class(['active' => request()->routeIs('mail-accounts.*')])>{{ __('messages.nav.mail_accounts') }}</a>
                    <a href="{{ route('dunning.settings') }}" @class(['active' => request()->routeIs('dunning.settings*')])>{{ __('messages.nav.dunning_settings') }}</a>
                    <a href="{{ route('backups.index') }}" @class(['active' => request()->routeIs('backups.*')])>{{ __('messages.nav.backups') }}</a>
                    <a href="{{ route('audit.index') }}" @class(['active' => request()->routeIs('audit.*')])>{{ __('messages.nav.audit') }}</a>
                </div>
            </details>
        @endcan
    @endif
    <span class="spacer"></span>
    @if(isset($userTenants) && $userTenants->count() > 1 && $fullAccess)
        <form method="POST" id="tenant-switch" action="#">
            @csrf
            <select aria-label="{{ __('messages.nav.tenant') }}"
                    onchange="var f=document.getElementById('tenant-switch'); f.action='{{ url('/tenants') }}/'+this.value+'/switch'; f.submit();">
                @foreach($userTenants as $tenant)
                    <option value="{{ $tenant->id }}" @selected($tenant->id === $currentTenant->id)>{{ $tenant->name }}</option>
                @endforeach
            </select>
        </form>
    @elseif(isset($currentTenant))
        <span>{{ $currentTenant->name }}</span>
    @endif
    <a href="{{ route('profile.show') }}" @class(['active' => request()->routeIs('profile.*')])>{{ auth()->user()->name }}</a>
    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="link">{{ __('messages.nav.logout') }}</button>
    </form>
    <a href="{{ route('about') }}" @class(['version', 'active' => request()->routeIs('about')])>{{ config('app.brand') }} {{ config('app.version') }}</a>
</div>

<div class="content">
@if(isset($currentTenant) && $currentTenant->test_mode)
    <div class="testmode">{{ __('messages.testmode.banner') }}</div>
@endif

<main @class(['medium' => View::hasSection('medium')])>
    @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if(session('warning'))<div class="alert warn">{{ session('warning') }}</div>@endif
    @if($errors->has('role'))<div class="alert error">{{ $errors->first('role') }}</div>@endif
    @yield('content')
</main>
</div>
</div>
<script>
    // narrow screens only: close dropdown on outside click or Escape (in the sidebar the settings group stays open)
    var narrow = function () { return window.matchMedia('(max-width: 860px)').matches; };
    document.addEventListener('click', function (e) {
        if (!narrow()) { return; }
        document.querySelectorAll('details.dropdown[open]').forEach(function (d) { if (!d.contains(e.target)) { d.removeAttribute('open'); } });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && narrow()) { document.querySelectorAll('details.dropdown[open]').forEach(function (d) { d.removeAttribute('open'); }); }
    });
</script>
@stack('scripts')
</body>
</html>
