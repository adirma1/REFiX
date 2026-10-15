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
--}}<tr class="item-row">
    <td class="pos muted"></td>
    <td class="desc">
        <select class="article-select" name="items[{{ $i }}][article_id]" aria-label="{{ __('messages.invoices.article') }}">
            <option value="">{{ __('messages.invoices.free_position') }}</option>
            @foreach($articles as $article)
                <option value="{{ $article->id }}" @selected((string) ($row['article_id'] ?? '') === (string) $article->id)>
                    {{ $article->article_number ? $article->article_number.' – ' : '' }}{{ $article->name }}
                </option>
            @endforeach
        </select>
        <textarea name="items[{{ $i }}][description]" rows="2" placeholder="{{ __('messages.invoices.description') }}">{{ $row['description'] ?? '' }}</textarea>
        <div class="hint price-hint" hidden>{{ __('messages.invoices.no_price') }}</div>
    </td>
    <td class="qty">
        <input type="text" inputmode="decimal" name="items[{{ $i }}][quantity]" value="{{ $row['quantity'] ?? '1' }}" aria-label="{{ __('messages.invoices.quantity') }}">
        <input type="text" name="items[{{ $i }}][unit]" list="unit-list" value="{{ $row['unit'] ?? '' }}" aria-label="{{ __('messages.invoices.unit') }}">
    </td>
    <td><input type="text" inputmode="decimal" name="items[{{ $i }}][unit_price]" value="{{ $row['unit_price'] ?? '' }}" aria-label="{{ __('messages.invoices.unit_price') }}"></td>
    <td><input type="text" inputmode="decimal" name="items[{{ $i }}][discount_percent]" value="{{ $row['discount_percent'] ?? '' }}" aria-label="{{ __('messages.invoices.discount') }}"></td>
    <td>
        @php($rate = (string) ($row['tax_rate'] ?? '19'))
        <select name="items[{{ $i }}][tax_rate]" class="tax-select" aria-label="{{ __('messages.invoices.tax') }}">
            @foreach(array_unique(array_merge($taxRates, [(float) str_replace(',', '.', $rate)])) as $r)
                <option value="{{ $r }}" @selected((float) $r === (float) str_replace(',', '.', $rate))>{{ $r }} %</option>
            @endforeach
        </select>
    </td>
    <td class="num line-net">0,00</td>
    <td><button type="button" class="btn small secondary remove-row" title="{{ __('messages.delete') }}" aria-label="{{ __('messages.delete') }}">×</button></td>
</tr>
