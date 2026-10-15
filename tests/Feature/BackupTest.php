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

use App\Models\AuditLog;
use App\Services\BackupInvalid;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use CreatesMailSetup, CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-11 10:00:00'));
        Storage::fake('local');
    }

    private function service(): BackupService
    {
        return app(BackupService::class);
    }

    private function zipOf(string $name): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path('backups/'.$name)));

        return $zip;
    }

    public function test_backup_contains_tables_files_and_a_manifest(): void
    {
        $tenant = $this->makeTenant();
        $this->makeUser($tenant, 'admin');
        $this->makeCustomer($tenant);
        Storage::disk('local')->put('invoices/1/R-1.pdf', '%PDF-test');

        $name = $this->service()->create();
        $this->assertSame('backup-20261011-100000.zip', $name);

        $zip = $this->zipOf($name);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);

        $this->assertSame(BackupService::keyFingerprint(), $manifest['key_fingerprint']);
        $this->assertSame(1, $manifest['tables']['customers']['rows']);
        $this->assertSame(1, $manifest['tables']['tenants']['rows']);
        $this->assertArrayHasKey('invoices/1/R-1.pdf', $manifest['files']);
        $this->assertSame('%PDF-test', $zip->getFromName('files/invoices/1/R-1.pdf'));

        // Laufzeit-Tabellen, Migrationsliste und der Backup-Ordner selbst sind nicht enthalten.
        foreach (['sessions', 'cache', 'jobs', 'migrations'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $manifest['tables']);
        }
        $this->assertNotEmpty($manifest['migrations']);
        foreach (array_keys($manifest['files']) as $path) {
            $this->assertStringStartsNotWith('backups/', $path);
        }

        // Der APP_KEY steckt nirgends im Archiv.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertStringNotContainsString((string) config('app.key'), (string) $zip->getFromIndex($i));
        }

        $this->assertSame($manifest, $this->service()->inspect(Storage::disk('local')->path('backups/'.$name)));
    }

    public function test_two_backups_in_the_same_second_get_different_names(): void
    {
        $this->makeTenant();

        $this->assertNotSame($this->service()->create(), $this->service()->create());
        $this->assertCount(2, $this->service()->list());
    }

    public function test_restore_brings_back_data_including_related_documents_and_files(): void
    {
        $tenant = $this->makeTenant();
        $accounting = $this->makeUser($tenant, 'accounting');
        $customer = $this->makeCustomer($tenant);
        $invoice = $this->issuedInvoice($tenant, $customer, $accounting);
        $this->post(route('invoices.credit-note', $invoice))->assertRedirect();
        $pdf = Storage::disk('local')->get($invoice->pdf_path);

        $name = $this->service()->create();
        $path = Storage::disk('local')->path('backups/'.$name);

        // Danach „geht etwas schief“: Belege weg, Stammdaten verändert, Datei gelöscht, Fremdes hinzugefügt.
        DB::table('invoice_items')->delete();
        DB::table('invoices')->delete();
        DB::table('customers')->delete();
        DB::table('tenants')->update(['name' => 'Kaputt GmbH']);
        DB::table('customers')->insert([
            'tenant_id' => $tenant->id, 'customer_number' => '99999', 'company' => 'Nachträglich', 'street' => 'x', 'zip' => '1',
            'city' => 'y', 'country' => 'DE', 'email' => 'x@example.com', 'currency' => 'EUR',
        ]);
        Storage::disk('local')->delete($invoice->pdf_path);

        $result = $this->service()->restore($path);

        $this->assertSame(2, DB::table('invoices')->count());
        $this->assertSame(1, DB::table('customers')->count());
        $this->assertSame('10001', DB::table('customers')->value('customer_number'));
        $this->assertSame('Testfirma GmbH', DB::table('tenants')->value('name'));
        $this->assertSame($invoice->id, DB::table('invoices')->whereNotNull('original_invoice_id')->value('original_invoice_id'));
        $this->assertSame($pdf, Storage::disk('local')->get($invoice->pdf_path));
        $this->assertSame(2, $result['tables']['invoices']);
        $this->assertGreaterThanOrEqual(1, $result['files']);

        // Die Sicherung selbst bleibt erhalten.
        $this->assertFileExists($path);
    }

    public function test_restore_is_refused_with_a_different_app_key_unless_forced(): void
    {
        $tenant = $this->makeTenant();
        $path = Storage::disk('local')->path('backups/'.$this->service()->create());
        $tenant->update(['name' => 'Geändert']);

        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);

        try {
            $this->service()->restore($path);
            $this->fail('Restore hätte abgelehnt werden müssen.');
        } catch (BackupInvalid $e) {
            $this->assertStringContainsString('APP_KEY', $e->getMessage());
        }
        $this->assertSame('Geändert', DB::table('tenants')->value('name'));

        $this->service()->restore($path, ignoreKey: true);
        $this->assertSame('Testfirma GmbH', DB::table('tenants')->value('name'));
    }

    public function test_damaged_backup_is_refused_before_anything_is_changed(): void
    {
        $tenant = $this->makeTenant();
        $this->makeCustomer($tenant);
        $name = $this->service()->create();
        $path = Storage::disk('local')->path('backups/'.$name);

        $zip = $this->zipOf($name);
        $zip->addFromString('db/customers.jsonl', "{\"id\":1}\n");
        $zip->close();

        $this->expectException(BackupInvalid::class);
        $this->expectExceptionMessage('Prüfsumme');

        try {
            $this->service()->restore($path);
        } finally {
            $this->assertSame(1, DB::table('customers')->count());
            $this->assertSame('10001', DB::table('customers')->value('customer_number'));
        }
    }

    public function test_files_that_are_not_backups_are_refused(): void
    {
        $this->makeTenant();
        Storage::disk('local')->put('backups/backup-20261011-100000.zip', 'das ist kein zip');

        $this->expectException(BackupInvalid::class);

        $this->service()->restore(Storage::disk('local')->path('backups/backup-20261011-100000.zip'));
    }

    public function test_backup_from_a_newer_program_version_is_refused(): void
    {
        $this->makeTenant();
        $name = $this->service()->create();
        $zip = $this->zipOf($name);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['migrations'][] = '2099_01_01_000000_from_the_future';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();

        $this->expectException(BackupInvalid::class);
        $this->expectExceptionMessage('neueren Programmversion');

        $this->service()->restore(Storage::disk('local')->path('backups/'.$name));
    }

    public function test_unsafe_paths_inside_a_backup_are_never_written(): void
    {
        $method = new ReflectionMethod(BackupService::class, 'safeRelativePath');

        foreach (['invoices/1/a.pdf', 'logos/1/logo.png'] as $ok) {
            $this->assertTrue($method->invoke($this->service(), $ok), $ok);
        }
        foreach (['../evil.txt', '/etc/passwd', 'a/../../b', 'backups/backup-20261011-100000.zip', 'a\\b', ''] as $bad) {
            $this->assertFalse($method->invoke($this->service(), $bad), $bad);
        }
    }

    public function test_only_admins_use_the_backup_page(): void
    {
        $tenant = $this->makeTenant();
        $name = $this->service()->create();

        foreach (['accounting', 'clerk', 'viewer'] as $role) {
            $this->actingAs($this->makeUser($tenant, $role))->withSession(['tenant_id' => $tenant->id]);
            $this->get('/backups')->assertForbidden();
            $this->post('/backups')->assertForbidden();
            $this->get(route('backups.download', $name))->assertForbidden();
            $this->delete(route('backups.destroy', $name))->assertForbidden();
        }

        $this->assertCount(1, $this->service()->list());
    }

    public function test_admin_creates_lists_downloads_and_deletes_backups(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id]);

        $this->post('/backups')->assertRedirect(route('backups.index'))->assertSessionHas('status');
        $this->assertSame(1, AuditLog::where('action', 'backup.created')->count());

        $name = $this->service()->list()[0]['name'];
        $this->get('/backups')->assertOk()->assertSee($name)->assertSee(BackupService::keyFingerprint())->assertSee('backup:restore');

        $response = $this->get(route('backups.download', $name))->assertOk();
        $this->assertStringContainsString($name, $response->headers->get('content-disposition'));
        $this->assertSame(1, AuditLog::where('action', 'backup.downloaded')->count());

        $this->delete(route('backups.destroy', $name))->assertRedirect(route('backups.index'));
        $this->assertCount(0, $this->service()->list());
        $this->delete(route('backups.destroy', $name))->assertNotFound();
    }

    public function test_download_only_accepts_real_backup_names(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id]);
        Storage::disk('local')->put('geheim.txt', 'x');

        $this->get('/backups/geheim.txt')->assertNotFound();
        $this->get('/backups/..%2Fgeheim.txt')->assertNotFound();
        $this->get('/backups/backup-20200101-000000.zip')->assertNotFound();
    }

    public function test_console_commands_create_and_restore(): void
    {
        $tenant = $this->makeTenant();

        $this->artisan('backup:create')->assertSuccessful();
        $name = $this->service()->list()[0]['name'];
        $tenant->update(['name' => 'Geändert']);

        // Ohne Bestätigung passiert nichts.
        $this->artisan('backup:restore', ['file' => $name])
            ->expectsConfirmation('Wirklich zurückspielen?', 'no')->assertFailed();
        $this->assertSame('Geändert', DB::table('tenants')->value('name'));

        $this->artisan('backup:restore', ['file' => $name, '--force' => true])->assertSuccessful();
        $this->assertSame('Testfirma GmbH', DB::table('tenants')->value('name'));
        $this->assertSame(1, AuditLog::where('action', 'backup.restored')->count());

        $this->artisan('backup:restore', ['file' => 'gibt-es-nicht.zip', '--force' => true])->assertFailed();
    }
}
