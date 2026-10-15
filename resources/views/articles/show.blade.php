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
@section('title', $article->name)
@section('content')
    <div class="toolbar">
        <h1 style="margin:0">{{ $article->name }}
            @if($article->article_number)<span class="muted">({{ $article->article_number }})</span>@endif
        </h1>
        <span class="spacer"></span>
        <a class="btn secondary" href="{{ route('articles.edit', $article) }}">{{ __('messages.edit') }}</a>
        @can('delete-master-data')
            <form method="POST" action="{{ route('articles.destroy', $article) }}"
                  onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                @csrf
                @method('DELETE')
                <button class="btn danger" type="submit">{{ __('messages.delete') }}</button>
            </form>
        @endcan
    </div>

    <div class="card">
        <p style="margin:0">
            {{ __('messages.articles.unit') }}: <strong>{{ $article->unit }}</strong> ·
            {{ __('messages.articles.tax_rate') }}: <strong>{{ number_format((float) $article->tax_rate, 0, ',', '.') }} %</strong>
        </p>
        @if($article->description)<p class="muted">{{ $article->description }}</p>@endif
    </div>

    <h2>{{ __('messages.articles.price_history') }}</h2>
    <table>
        <thead>
        <tr>
            <th>{{ __('messages.articles.valid_from') }}</th>
            <th class="num">{{ __('messages.articles.price') }}</th>
            <th>{{ __('messages.articles.customer_price') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($prices as $price)
            <tr>
                <td>{{ $price->valid_from->format('d.m.Y') }}</td>
                <td class="num">{{ number_format((float) $price->amount, 2, ',', '.') }} {{ $price->currency }}</td>
                <td>
                    @if($price->customer)
                        {{ $price->customer->customer_number }} – {{ $price->customer->company }}
                    @else
                        <span class="muted">{{ __('messages.articles.all_customers') }}</span>
                    @endif
                </td>
                <td class="actions">
                    @can('delete-master-data')
                        <form method="POST" action="{{ route('articles.prices.destroy', [$article, $price]) }}"
                              onsubmit="return confirm('{{ __('messages.confirm_delete') }}')">
                            @csrf
                            @method('DELETE')
                            <button class="btn small danger" type="submit">{{ __('messages.delete') }}</button>
                        </form>
                    @endcan
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">{{ __('messages.articles.no_prices') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    <p class="muted">{{ __('messages.articles.price_note') }}</p>

    @can('edit-master-data')
        <div class="card">
            <h2 style="margin-top:0">{{ __('messages.articles.add_price') }}</h2>
            <form method="POST" action="{{ route('articles.prices.store', $article) }}">
                @csrf
                <div class="grid">
                    <x-field name="amount" :label="__('messages.articles.price')" required />
                    <x-select name="currency" :label="__('messages.customers.currency')"
                              :options="array_combine(config('invoice.currencies'), config('invoice.currencies'))"
                              value="EUR" required />
                    <x-field name="valid_from" type="date" :label="__('messages.articles.valid_from')" :value="now()->toDateString()" required />
                    <x-select name="customer_id" :label="__('messages.articles.customer_price')"
                              :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->customer_number.' – '.$c->company])->all()"
                              :blank="__('messages.articles.all_customers')" />
                </div>
                <button class="btn" type="submit">{{ __('messages.articles.add_price') }}</button>
            </form>
        </div>
    @endcan
@endsection
