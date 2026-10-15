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
@section('title', __('messages.nav.dunning_settings'))
@section('content')
    <h1>{{ __('messages.nav.dunning_settings') }} <span class="badge">{{ $tenant->name }}</span></h1>
    <p class="muted">{{ __('messages.dunning.settings_intro') }}</p>
    @can('manage-users')
        <p class="muted">{!! __('messages.dunning.layout_link', ['link' => '<a href="'.e(route('layouts.index', ['kind' => 'dunning'])).'">'.e(__('messages.nav.layouts')).'</a>']) !!}</p>
    @endcan

    <form method="POST" action="{{ route('dunning.settings.update') }}">
        @csrf

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.dunning.settings_timing') }}</h2>
            <div class="grid">
                <x-field name="dunning_days_after_due" type="number" :label="__('messages.dunning.days_after_due')" :value="$tenant->dunning_days_after_due" required
                         :tip="__('messages.dunning.days_after_due_tip')" />
                <x-field name="dunning_grace_days" type="number" :label="__('messages.dunning.grace_days')" :value="$tenant->dunning_grace_days" required
                         :tip="__('messages.dunning.grace_days_tip')" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.dunning.settings_fees') }}</h2>
            <p class="muted">{{ __('messages.dunning.fees_help') }}</p>
            <div class="grid">
                @foreach([1, 2, 3] as $level)
                    <x-field :name="'dunning_fee_'.$level" :label="__('messages.dunning.level_'.$level)" :value="number_format((float) $tenant->{'dunning_fee_'.$level}, 2, ',', '')" />
                @endforeach
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.dunning.settings_interest') }}</h2>
            <p class="muted">{{ __('messages.dunning.interest_help') }}</p>
            <div class="field check">
                <label><input type="checkbox" name="dunning_interest_enabled" value="1" @checked(old('dunning_interest_enabled', $tenant->dunning_interest_enabled))> {{ __('messages.dunning.interest_enabled') }}</label>
            </div>
            <div class="grid">
                <x-field name="dunning_base_rate" :label="__('messages.dunning.base_rate')" :tip="__('messages.dunning.base_rate_tip')"
                         :value="$tenant->dunning_base_rate !== null ? number_format((float) $tenant->dunning_base_rate, 2, ',', '') : ''" />
                <x-field name="dunning_interest_margin" :label="__('messages.dunning.margin')" :tip="__('messages.dunning.margin_tip')"
                         :value="number_format((float) $tenant->dunning_interest_margin, 2, ',', '')" />
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.dunning.settings_texts') }}</h2>
            <p class="muted">{{ __('messages.dunning.texts_help') }}</p>
            <p class="muted placeholder-list">@foreach($placeholders as $name)<code>{{ '{'.$name.'}' }}</code> @endforeach</p>
            @foreach([1, 2, 3] as $level)
                <div class="field">
                    <label for="dunning_text_{{ $level }}">{{ __('messages.dunning.level_'.$level) }}</label>
                    <textarea id="dunning_text_{{ $level }}" name="dunning_text_{{ $level }}" rows="8">{{ old('dunning_text_'.$level, $texts[$level]) }}</textarea>
                    @error('dunning_text_'.$level)<div class="error">{{ $message }}</div>@enderror
                </div>
            @endforeach
        </div>

        <button class="btn" type="submit">{{ __('messages.save') }}</button>
    </form>
@endsection
