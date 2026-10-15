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
@php($editing = $article->exists)
@section('title', $editing ? __('messages.articles.edit') : __('messages.articles.new'))
@section('medium', '1')
@section('content')
    <h1>{{ $editing ? __('messages.articles.edit') : __('messages.articles.new') }}</h1>

    @cannot('edit-master-data')
        <div class="alert warn">{{ __('messages.read_only') }}</div>
    @endcannot

    <form method="POST" action="{{ $editing ? route('articles.update', $article) : route('articles.store') }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="card">
            <div class="grid">
                <x-field name="article_number" :label="__('messages.articles.number')" :value="$article->article_number" maxlength="40" />
                <x-field name="name" :label="__('messages.articles.name')" :value="$article->name" required />
            </div>
            <div class="field">
                <label for="description">{{ __('messages.articles.description') }}</label>
                <textarea id="description" name="description">{{ old('description', $article->description) }}</textarea>
                @error('description')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="grid">
                <x-select name="unit" :label="__('messages.articles.unit')"
                          :options="array_combine(config('invoice.units'), config('invoice.units'))"
                          :value="$article->unit" required />
                <x-select name="tax_rate" :label="__('messages.articles.tax_rate')"
                          :options="collect(config('invoice.tax_rates'))->mapWithKeys(fn ($r) => [$r => $r.' %'])->all()"
                          :value="(int) $article->tax_rate" required />
            </div>
            <div class="field check">
                <input type="hidden" name="active" value="0">
                <label><input type="checkbox" name="active" value="1" @checked(old('active', $article->active))>
                    {{ __('messages.articles.active') }}</label>
            </div>
        </div>

        @can('edit-master-data')
            <button class="btn" type="submit">{{ __('messages.save') }}</button>
        @endcan
        <a class="btn secondary" href="{{ $editing ? route('articles.show', $article) : route('articles.index') }}">{{ __('messages.cancel') }}</a>
    </form>
@endsection
