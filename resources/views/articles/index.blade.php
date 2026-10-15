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
@section('title', __('messages.nav.articles'))
@section('content')
    <h1>{{ __('messages.nav.articles') }}</h1>
    <div class="toolbar">
        <form method="GET" action="{{ route('articles.index') }}">
            <input type="text" name="q" value="{{ $search }}" placeholder="{{ __('messages.search') }}">
            <select name="status" aria-label="{{ __('messages.invoices.status') }}">
                <option value="">{{ __('messages.filter.status_all') }}</option>
                <option value="active" @selected($filters['status'] === 'active')>{{ __('messages.filter.active') }}</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>{{ __('messages.filter.inactive') }}</option>
            </select>
            <select name="tax" aria-label="{{ __('messages.articles.tax_rate') }}">
                <option value="">{{ __('messages.filter.tax_all') }}</option>
                @foreach($taxRates as $rate)
                    <option value="{{ $rate }}" @selected($filters['tax'] === $rate)>{{ number_format((float) $rate, 0, ',', '.') }} %</option>
                @endforeach
            </select>
            <select name="unit" aria-label="{{ __('messages.articles.unit') }}">
                <option value="">{{ __('messages.filter.unit_all') }}</option>
                @foreach($units as $unit)
                    <option value="{{ $unit }}" @selected($filters['unit'] === $unit)>{{ $unit }}</option>
                @endforeach
            </select>
            <select name="price" aria-label="{{ __('messages.articles.current_price') }}">
                <option value="">{{ __('messages.filter.price_all') }}</option>
                <option value="none" @selected($filters['price'] === 'none')>{{ __('messages.filter.price_none') }}</option>
            </select>
            <button class="btn secondary" type="submit">{{ __('messages.search') }}</button>
            @if($filtered)
                <a class="btn secondary" href="{{ route('articles.index') }}">{{ __('messages.filter.reset') }}</a>
            @endif
        </form>
        <span class="spacer"></span>
        @can('edit-master-data')
            <a class="btn secondary" href="{{ route('articles.import') }}">{{ __('messages.article_import.button') }}</a>
            <a class="btn" href="{{ route('articles.create') }}">{{ __('messages.articles.new') }}</a>
        @endcan
    </div>

    <table>
        <thead>
        <tr>
            <th>{{ __('messages.articles.number') }}</th>
            <th>{{ __('messages.articles.name') }}</th>
            <th>{{ __('messages.articles.unit') }}</th>
            <th class="num">{{ __('messages.articles.tax_rate') }}</th>
            <th class="num">{{ __('messages.articles.current_price') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @forelse($articles as $article)
            @php($price = $current[$article->id])
            <tr>
                <td>{{ $article->article_number }}</td>
                <td><a href="{{ route('articles.show', $article) }}">{{ $article->name }}</a>
                    @unless($article->active)<span class="badge">{{ __('messages.articles.inactive') }}</span>@endunless
                </td>
                <td>{{ $article->unit }}</td>
                <td class="num">{{ number_format((float) $article->tax_rate, 0, ',', '.') }} %</td>
                <td class="num">
                    @if($price)
                        {{ number_format((float) $price->amount, 2, ',', '.') }} {{ $price->currency }}
                    @else
                        <span class="muted">–</span>
                    @endif
                </td>
                <td class="actions">
                    <a class="btn small secondary" href="{{ route('articles.show', $article) }}">{{ __('messages.articles.prices') }}</a>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">{{ __('messages.articles.empty') }}</td></tr>
        @endforelse
        </tbody>
    </table>
    @include('pagination.footer', ['paginator' => $articles, 'id' => 'articles'])
@endsection
