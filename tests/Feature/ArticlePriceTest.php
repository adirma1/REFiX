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

namespace Tests\Feature;

use App\Models\Article;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ArticlePriceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function makeArticle(int $tenantId): Article
    {
        Tenancy::set($tenantId);

        return Article::create(['name' => 'Support', 'unit' => 'Stunde', 'tax_rate' => 19]);
    }

    public function test_price_for_picks_latest_price_valid_on_the_date(): void
    {
        $tenant = $this->makeTenant();
        $article = $this->makeArticle($tenant->id);

        $article->prices()->create(['currency' => 'EUR', 'amount' => '90', 'valid_from' => '2025-01-01']);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => '2026-01-01']);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '99', 'valid_from' => '2027-01-01']);

        $this->assertNull($article->priceFor('2024-12-31'));
        $this->assertSame('90.0000', (string) $article->priceFor('2025-06-01')->amount);
        $this->assertSame('95.0000', (string) $article->priceFor('2026-12-31')->amount);
        $this->assertSame('95.0000', (string) $article->priceFor('2026-10-10')->amount);
        $this->assertSame('99.0000', (string) $article->priceFor('2027-01-01')->amount);
    }

    public function test_customer_specific_price_wins_and_other_customers_get_the_standard(): void
    {
        $tenant = $this->makeTenant();
        $customer = $this->makeCustomer($tenant, '10001');
        $other = $this->makeCustomer($tenant, '10002');
        $article = $this->makeArticle($tenant->id);

        $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => '2026-01-01']);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '80', 'valid_from' => '2026-01-01', 'customer_id' => $customer->id]);

        $this->assertSame('80.0000', (string) $article->priceFor('2026-10-10', 'EUR', $customer->id)->amount);
        $this->assertSame('95.0000', (string) $article->priceFor('2026-10-10', 'EUR', $other->id)->amount);
        $this->assertSame('95.0000', (string) $article->priceFor('2026-10-10', 'EUR')->amount);
    }

    public function test_prices_are_kept_per_currency(): void
    {
        $tenant = $this->makeTenant();
        $article = $this->makeArticle($tenant->id);

        $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => '2026-01-01']);
        $article->prices()->create(['currency' => 'USD', 'amount' => '110', 'valid_from' => '2026-01-01']);

        $this->assertSame('110.0000', (string) $article->priceFor('2026-10-10', 'USD')->amount);
        $this->assertNull($article->priceFor('2026-10-10', 'CHF'));
    }

    public function test_price_increase_via_form_keeps_the_old_price(): void
    {
        $tenant = $this->makeTenant();
        $article = $this->makeArticle($tenant->id);
        $user = $this->makeUser($tenant, 'accounting');
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $this->post(route('articles.prices.store', $article), [
            'amount' => '95,00', 'currency' => 'eur', 'valid_from' => '2026-01-01',
        ])->assertRedirect(route('articles.show', $article));

        $this->post(route('articles.prices.store', $article), [
            'amount' => '99,50', 'currency' => 'EUR', 'valid_from' => '2027-01-01',
        ])->assertRedirect(route('articles.show', $article));

        Tenancy::set($tenant->id);
        $this->assertSame(2, $article->prices()->count());
        $this->assertSame('95.0000', (string) $article->priceFor('2026-12-31')->amount);
        $this->assertSame('99.5000', (string) $article->priceFor('2027-01-01')->amount);
    }

    public function test_duplicate_price_for_same_date_and_currency_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $article = $this->makeArticle($tenant->id);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => '2026-01-01']);
        $user = $this->makeUser($tenant, 'accounting');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post(route('articles.prices.store', $article), [
                'amount' => '99', 'currency' => 'EUR', 'valid_from' => '2026-01-01',
            ])->assertSessionHasErrors('valid_from');
    }

    public function test_clerk_cannot_delete_prices_and_foreign_customer_price_is_rejected(): void
    {
        $a = $this->makeTenant('Firma A');
        $b = $this->makeTenant('Firma B');
        $foreignCustomer = $this->makeCustomer($b, '90001');
        $article = $this->makeArticle($a->id);
        $price = $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => '2026-01-01']);
        $clerk = $this->makeUser($a, 'clerk');

        $this->actingAs($clerk)->withSession(['tenant_id' => $a->id]);

        $this->delete(route('articles.prices.destroy', [$article, $price]))->assertForbidden();

        $this->post(route('articles.prices.store', $article), [
            'amount' => '50', 'currency' => 'EUR', 'valid_from' => '2026-05-01',
            'customer_id' => $foreignCustomer->id,
        ])->assertSessionHasErrors('customer_id');
    }

    public function test_article_list_and_detail_pages_render(): void
    {
        $tenant = $this->makeTenant();
        $article = $this->makeArticle($tenant->id);
        $article->prices()->create(['currency' => 'EUR', 'amount' => '95', 'valid_from' => now()->subDay()->toDateString()]);
        $user = $this->makeUser($tenant, 'admin');

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/articles')->assertOk()->assertSee('Support')->assertSee('95,00');
        $this->get(route('articles.show', $article))->assertOk()->assertSee('Preise und Preishistorie');
        $this->get(route('articles.edit', $article))->assertOk();
    }
}
