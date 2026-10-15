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
@section('title', __('messages.dunning.mail_title', ['number' => $dunning->invoice->number]))
@section('medium', '1')
@section('content')
    <h1>{{ __('messages.dunning.mail_title', ['number' => $dunning->invoice->number]) }}</h1>

    <div class="card">
        <p class="muted" style="margin-top:0">
            {{ __('messages.invoices.mail_from') }}: <strong>{{ $account->from_name ? $account->from_name.' <'.$account->from_address.'>' : $account->from_address }}</strong>
            @if($account->bcc) · {{ __('messages.mail_accounts.bcc') }}: {{ $account->bcc }}@endif
            · {{ __('messages.invoices.mail_attachment') }}: {{ $dunning->label() }}.pdf
        </p>
        @if($dunning->isSent())
            <div class="alert warn">{{ __('messages.dunning.already_sent') }}</div>
        @endif
        <form method="POST" action="{{ route('dunning.mail.send', $dunning) }}"
              onsubmit="var b=this.querySelector('button[type=submit]'); b.disabled=true; b.textContent='{{ __('messages.invoices.mail_sending') }}';">
            @csrf
            <x-field name="to" :label="__('messages.invoices.mail_to')" :value="$to" required maxlength="500" :tip="__('messages.invoices.mail_to_tip')" />
            <x-field name="subject" :label="__('messages.invoices.mail_subject')" :value="$subject" required maxlength="200" />
            <div class="field">
                <label for="body">{{ __('messages.invoices.mail_body') }} *</label>
                <textarea id="body" name="body" rows="14" required>{{ old('body', $body) }}</textarea>
                @error('body')<div class="error">{{ $message }}</div>@enderror
            </div>
            <button class="btn" type="submit">{{ __('messages.invoices.mail_send') }}</button>
            <a class="btn secondary" href="{{ route('dunning.index', ['tab' => 'history']) }}">{{ __('messages.cancel') }}</a>
        </form>
    </div>
@endsection
