<?php

/*
 * RE-FiX – Rechnungsprogramm
 * Copyright (C) 2026 Martin Adirsch
 *
 * This program is free software; you can redistribute it and/or modify it under the terms of
 * version 2 of the GNU General Public License as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
 * without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with this program
 * (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.
 *
 * SPDX-License-Identifier: GPL-2.0-only
 */

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\Customer;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ArticlePriceController extends Controller
{
    /**
     * Eine Preiserhöhung ist ein neuer Preis mit Startdatum. Der alte Preis bleibt in der Historie.
     */
    public function store(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('edit-master-data');

        $request->merge([
            'amount' => str_replace(',', '.', (string) $request->input('amount')),
            'currency' => strtoupper((string) $request->input('currency')),
            'customer_id' => $request->input('customer_id') ?: null,
        ]);

        $data = $request->validate([
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'valid_from' => ['required', 'date'],
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->where('tenant_id', Tenancy::id())->whereNull('deleted_at'),
            ],
        ]);

        $exists = $article->prices()
            ->where('currency', $data['currency'])
            ->whereDate('valid_from', $data['valid_from'])
            ->where(fn ($q) => $data['customer_id']
                ? $q->where('customer_id', $data['customer_id'])
                : $q->whereNull('customer_id'))
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['valid_from' => __('messages.articles.price_exists')]);
        }

        $article->prices()->create($data);

        return redirect()->route('articles.show', $article)->with('status', __('messages.articles.price_added'));
    }

    public function destroy(Article $article, ArticlePrice $price): RedirectResponse
    {
        $this->authorize('delete-master-data');
        abort_unless($price->article_id === $article->id, 404);

        $price->delete();

        return redirect()->route('articles.show', $article)->with('status', __('messages.articles.price_deleted'));
    }
}
