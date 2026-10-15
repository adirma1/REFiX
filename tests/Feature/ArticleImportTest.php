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
use App\Models\ArticlePrice;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ArticleImportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const HEAD = "Artikelnummer;Bezeichnung;Beschreibung;Einheit;Steuersatz;Preis (netto);Währung;Gültig ab;Aktiv\n";

    private Tenant $tenant;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo('2026-10-14 10:00:00');
        $this->tenant = $this->makeTenant();
        $this->clerk = $this->makeUser($this->tenant, 'clerk');
    }

    private function actAs(User $user, ?Tenant $tenant = null): static
    {
        $this->actingAs($user)->withSession(['tenant_id' => ($tenant ?? $this->tenant)->id]);

        return $this;
    }

    private function upload(string $csv, ?User $user = null): string
    {
        $response = $this->actAs($user ?? $this->clerk)->post('/articles/import', [
            'file' => UploadedFile::fake()->createWithContent('artikel.csv', $csv),
        ]);

        $response->assertSessionHasNoErrors();
        $location = $response->headers->get('Location');
        $this->assertMatchesRegularExpression('#/articles/import/[a-f0-9]{32}$#', $location);

        return basename($location);
    }

    /** @return array<string, mixed> */
    private function mapping(string $mode = 'skip'): array
    {
        return ['mode' => $mode, 'map' => [
            'article_number' => '0', 'name' => '1', 'description' => '2', 'unit' => '3', 'tax_rate' => '4',
            'price' => '5', 'currency' => '6', 'valid_from' => '7', 'active' => '8',
        ]];
    }

    private function articles()
    {
        Tenancy::set($this->tenant->id);

        return Article::withTrashed()->orderBy('id')->get();
    }

    public function test_full_flow_upload_map_preview_import(): void
    {
        $token = $this->upload(self::HEAD
            ."A-1;Beratung;Stundensatz;Stunde;19;1.234,56;;;ja\n"
            ."A-2;Buch;;Stück;7;19,90;EUR;01.12.2026;nein\n"
            ."A-3;Ohne Preis;;Stück;;;;;\n");

        $this->get(route('articles.import.map', $token))->assertOk()
            ->assertSee('Bezeichnung')->assertSee('Preis (netto)')->assertSee('Gültig ab');

        $this->post(route('articles.import.preview', $token), $this->mapping())
            ->assertOk()->assertSee('werden neu angelegt')->assertSee('Beratung')->assertSee('3 Artikel jetzt importieren');
        $this->assertCount(0, $this->articles());

        $this->post(route('articles.import.run', $token), $this->mapping())
            ->assertRedirect(route('articles.index'))->assertSessionHas('status');

        $articles = $this->articles();
        $this->assertCount(3, $articles);
        $this->assertSame('Beratung', $articles[0]->name);
        $this->assertSame('Stundensatz', $articles[0]->description);
        $this->assertEquals(19, $articles[0]->tax_rate);
        $this->assertTrue($articles[0]->active);
        $this->assertEquals(7, $articles[1]->tax_rate);
        $this->assertFalse($articles[1]->active);
        $this->assertEquals(19, $articles[2]->tax_rate); // leer → 19
        $this->assertTrue($articles[2]->active);          // leer → aktiv

        $price = $articles[0]->prices()->first();
        $this->assertEquals(1234.56, (float) $price->amount);
        $this->assertSame('EUR', $price->currency);
        $this->assertSame('2026-10-14', $price->valid_from->toDateString()); // leer → heute
        $this->assertSame('2026-12-01', $articles[1]->prices()->first()->valid_from->toDateString());
        $this->assertSame(0, $articles[2]->prices()->count());

        $this->assertSame(1, AuditLog::where('action', 'article.imported')->count());
        $this->assertSame([], Storage::disk('local')->files('imports/'.$this->tenant->id));
        $this->get(route('articles.import.map', $token))->assertRedirect(route('articles.import'));
    }

    public function test_columns_are_guessed_from_the_headings(): void
    {
        $token = $this->upload("Art.-Nr.;Bezeichnung;ME;MwSt;VK-Preis\nA-1;Test;Stück;19;5,00\n");

        $this->get(route('articles.import.map', $token))->assertOk()
            ->assertSee('<option value="0" selected>', false)
            ->assertSee('<option value="4" selected>', false);
    }

    public function test_duplicates_and_invalid_rows_are_skipped_and_reported(): void
    {
        Tenancy::set($this->tenant->id);
        Article::create(['article_number' => 'A-1', 'name' => 'Vorhanden', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);
        $gone = Article::create(['article_number' => 'A-3', 'name' => 'Gelöscht', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);
        $gone->delete(); // gelöschte Nummern bleiben belegt (eindeutiger Index)

        $token = $this->upload(self::HEAD
            ."A-1;Schon da;;Stück;19;;;;\n"
            ."A-2;Neu;;Stück;19;;;;\n"
            ."A-2;Doppelt in Datei;;Stück;19;;;;\n"
            ."A-3;War gelöscht;;Stück;19;;;;\n"
            ."A-4;;;Stück;19;;;;\n"
            ."A-5;Ohne Einheit;;;19;;;;\n"
            ."A-6;Preis kaputt;;Stück;19;abc;;;\n"
            ."A-7;Datum kaputt;;Stück;19;5;;31.02.2026;\n"
            ."A-8;Aktiv kaputt;;Stück;19;;;;vielleicht\n"
            ."A-9;Steuer kaputt;;Stück;150;;;;\n");

        $this->post(route('articles.import.preview', $token), $this->mapping())
            ->assertOk()
            ->assertSee('Artikelnummer A-1 gibt es bereits.')
            ->assertSee('Artikelnummer A-2 kommt in der Datei mehrfach vor.')
            ->assertSee('Artikelnummer A-3 gibt es bereits.')
            ->assertSee('Ohne Einheit')->assertSee('Preis kaputt')->assertSee('Datum kaputt')
            ->assertSee('Aktiv kaputt')->assertSee('Steuer kaputt');

        $this->post(route('articles.import.run', $token), $this->mapping())
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 Artikel wurden angelegt'));

        $this->assertSame(['A-1', 'A-3', 'A-2'], $this->articles()->pluck('article_number')->all());
        $this->assertSame('Vorhanden', Article::where('article_number', 'A-1')->value('name')); // unverändert
        $this->assertSame('Neu', Article::where('article_number', 'A-2')->value('name'));
    }

    public function test_rows_without_a_number_are_always_created(): void
    {
        $token = $this->upload("Bezeichnung;Einheit\nEins;Stück\nEins;Stück\n");
        $map = ['map' => ['name' => '0', 'unit' => '1']];

        $this->post(route('articles.import.run', $token), $map);

        $articles = $this->articles();
        $this->assertCount(2, $articles);
        $this->assertNull($articles[0]->article_number);
    }

    public function test_update_mode_changes_mapped_fields_and_adds_a_price_only_when_it_differs(): void
    {
        Tenancy::set($this->tenant->id);
        $changed = Article::create(['article_number' => 'A-1', 'name' => 'Alt', 'description' => 'bleibt?', 'unit' => 'Stück', 'tax_rate' => 7, 'active' => true]);
        $changed->prices()->create(['currency' => 'EUR', 'amount' => 10, 'valid_from' => '2026-01-01']);
        $same = Article::create(['article_number' => 'A-2', 'name' => 'Gleicher Preis', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);
        $same->prices()->create(['currency' => 'EUR', 'amount' => 5, 'valid_from' => '2026-01-01']);
        $clash = Article::create(['article_number' => 'A-3', 'name' => 'Gleiches Datum', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);
        $clash->prices()->create(['currency' => 'EUR', 'amount' => 8, 'valid_from' => '2026-10-14']);

        $csv = "Artikelnummer;Bezeichnung;Einheit;Preis (netto)\n"
            ."A-1;Neu benannt;Stunde;12,50\n"
            ."A-2;Gleicher Preis;Stück;5,00\n"
            ."A-3;Gleiches Datum;Stück;9,00\n";
        $map = ['mode' => 'update', 'map' => ['article_number' => '0', 'name' => '1', 'unit' => '2', 'price' => '3']];
        $token = $this->upload($csv);

        $this->post(route('articles.import.preview', $token), $map)
            ->assertOk()->assertSee('werden aktualisiert')->assertSee('+ neuer Preis')
            ->assertSee('Für dieses Datum gibt es schon einen anderen Preis');

        $this->post(route('articles.import.run', $token), $map)
            ->assertSessionHas('status', fn ($m) => str_contains($m, '3 aktualisiert'));

        Tenancy::set($this->tenant->id);
        $changed->refresh();
        $this->assertSame('Neu benannt', $changed->name);
        $this->assertSame('Stunde', $changed->unit);
        $this->assertSame('bleibt?', $changed->description); // nicht zugeordnet → unverändert
        $this->assertEquals(7, $changed->tax_rate);
        $this->assertSame([10.0, 12.5], $changed->prices()->orderBy('valid_from')->get()->map(fn ($p) => (float) $p->amount)->all()); // alter Preis bleibt
        $this->assertSame(1, $same->prices()->count());
        $this->assertSame(1, $clash->prices()->count());
        $this->assertEquals(8, $clash->prices()->first()->amount);
    }

    public function test_skip_mode_leaves_existing_articles_untouched(): void
    {
        Tenancy::set($this->tenant->id);
        $article = Article::create(['article_number' => 'A-1', 'name' => 'Alt', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);

        $token = $this->upload(self::HEAD."A-1;Neu;;Stück;19;50;;;\n");
        $this->post(route('articles.import.run', $token), $this->mapping('skip'));

        Tenancy::set($this->tenant->id);
        $this->assertSame('Alt', $article->fresh()->name);
        $this->assertSame(0, $article->prices()->count());
    }

    public function test_numbers_of_another_tenant_do_not_count_as_duplicates(): void
    {
        $other = $this->makeTenant('Andere GmbH');
        Tenancy::set($other->id);
        Article::create(['article_number' => 'A-1', 'name' => 'Fremd', 'unit' => 'Stück', 'tax_rate' => 19, 'active' => true]);

        $token = $this->upload(self::HEAD."A-1;Eigen;;Stück;19;;;;\n");
        $this->post(route('articles.import.run', $token), $this->mapping());

        Tenancy::set($this->tenant->id);
        $this->assertSame('Eigen', Article::where('article_number', 'A-1')->value('name'));
        Tenancy::set($other->id);
        $this->assertSame('Fremd', Article::where('article_number', 'A-1')->value('name'));
    }

    public function test_viewer_may_not_import_and_buttons_follow_the_right(): void
    {
        $viewer = $this->makeUser($this->tenant, 'viewer');

        $this->actAs($viewer)->get('/articles/import')->assertForbidden();
        $this->actAs($viewer)->post('/articles/import', [
            'file' => UploadedFile::fake()->createWithContent('a.csv', self::HEAD."A;B;;S;19;;;;\n"),
        ])->assertForbidden();
        $this->actAs($viewer)->get('/articles')->assertOk()->assertDontSee(route('articles.import'));

        $this->actAs($this->clerk)->get('/articles')->assertOk()->assertSee(route('articles.import'));
    }

    public function test_template_and_unreadable_files(): void
    {
        $response = $this->actAs($this->clerk)->get(route('articles.import.template'));
        $response->assertOk();
        $this->assertStringContainsString('Artikelnummer;Bezeichnung;Beschreibung;Einheit;Steuersatz;Preis (netto);Währung;Gültig ab;Aktiv', $response->getContent());

        $this->actAs($this->clerk)->post('/articles/import', [
            'file' => UploadedFile::fake()->createWithContent('leer.csv', "Artikelnummer;Bezeichnung\n"),
        ])->assertRedirect(route('articles.import'))->assertSessionHas('warning', fn ($m) => str_contains($m, 'keine Artikelzeilen'));
    }
}
