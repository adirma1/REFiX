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
use App\Models\Customer;
use App\Support\ListFilter;
use App\Support\Tenancy;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));

        $units = Article::query()->whereNotNull('unit')->where('unit', '<>', '')
            ->distinct()->orderBy('unit')->pluck('unit')->all();
        $taxRates = Article::query()->distinct()->orderBy('tax_rate')->pluck('tax_rate')
            ->map(fn ($rate) => (string) (float) $rate)->unique()->values()->all();

        $filters = [
            'status' => ListFilter::pick($request, 'status', ['active', 'inactive']),
            'tax' => ListFilter::pick($request, 'tax', $taxRates),
            'unit' => ListFilter::pick($request, 'unit', $units),
            'price' => ListFilter::pick($request, 'price', ['none']),
        ];

        $articles = Article::query()
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('article_number', 'like', $like)->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($filters['status'] === 'active', fn ($q) => $q->where('active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('active', false))
            ->when($filters['tax'] !== '', fn ($q) => $q->where('tax_rate', (float) $filters['tax']))
            ->when($filters['unit'] !== '', fn ($q) => $q->where('unit', $filters['unit']))
            // Wie die Spalte „Preis“: Standardpreis in EUR, gültig ab heute oder früher.
            ->when($filters['price'] === 'none', fn ($q) => $q->whereDoesntHave('prices', fn ($p) => $p
                ->where('currency', 'EUR')->whereNull('customer_id')->whereDate('valid_from', '<=', now()->toDateString())))
            ->orderBy('name')
            ->paginate(PerPage::for($request->user()))
            ->withQueryString();

        // Aktueller Standardpreis (EUR) je Artikel für die Liste.
        $current = $articles->getCollection()->mapWithKeys(
            fn (Article $article) => [$article->id => $article->priceFor(now(), 'EUR')]
        );

        return view('articles.index', [
            'articles' => $articles, 'search' => $search, 'current' => $current, 'filters' => $filters,
            'units' => $units, 'taxRates' => $taxRates,
            'filtered' => ListFilter::active($filters + ['q' => $search]),
        ]);
    }

    public function create(): View
    {
        $this->authorize('edit-master-data');

        return view('articles.form', ['article' => new Article(['tax_rate' => 19, 'active' => true, 'unit' => 'Stück'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('edit-master-data');

        $article = Article::create($this->validated($request));

        return redirect()->route('articles.show', $article)
            ->with('status', __('messages.articles.created', ['name' => $article->name]));
    }

    public function show(Article $article): View
    {
        return view('articles.show', [
            'article' => $article,
            'prices' => $article->prices()->with('customer')->orderByDesc('valid_from')->orderByDesc('id')->get(),
            'customers' => Customer::orderBy('customer_number')->get(['id', 'customer_number', 'company']),
        ]);
    }

    public function edit(Article $article): View
    {
        return view('articles.form', ['article' => $article]);
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $this->authorize('edit-master-data');

        $article->update($this->validated($request, $article));

        return redirect()->route('articles.show', $article)
            ->with('status', __('messages.articles.updated', ['name' => $article->name]));
    }

    public function destroy(Article $article): RedirectResponse
    {
        $this->authorize('delete-master-data');

        $article->delete();

        return redirect()->route('articles.index')
            ->with('status', __('messages.articles.deleted', ['name' => $article->name]));
    }

    private function validated(Request $request, ?Article $article = null): array
    {
        $request->merge([
            'tax_rate' => str_replace(',', '.', (string) $request->input('tax_rate')),
            'article_number' => trim((string) $request->input('article_number')) ?: null,
            'active' => $request->boolean('active'),
        ]);

        $data = $request->validate([
            'article_number' => [
                'nullable', 'string', 'max:40',
                Rule::unique('articles', 'article_number')
                    ->where('tenant_id', Tenancy::id())
                    ->ignore($article?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['required', 'string', 'max:20'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'active' => ['boolean'],
        ]);

        return $this->emptyToNull($data);
    }
}
