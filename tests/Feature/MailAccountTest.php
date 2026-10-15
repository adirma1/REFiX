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
use App\Models\MailAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class MailAccountTest extends TestCase
{
    use CreatesMailSetup, CreatesTenants, RefreshDatabase;

    public function test_admin_creates_an_account_and_the_password_is_stored_encrypted(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id])
            ->post('/mail-accounts', $this->validAccountData(['bcc' => 'chef@example.com; buchhaltung@example.com']))
            ->assertSessionHasNoErrors();

        $account = MailAccount::firstOrFail();
        $this->assertSame('geheim-123', $account->smtp_password);
        $this->assertNotSame('geheim-123', DB::table('mail_accounts')->value('smtp_password'));
        $this->assertSame('chef@example.com, buchhaltung@example.com', $account->bcc);
        $this->assertSame(['chef@example.com', 'buchhaltung@example.com'], $account->bccList());

        // Weder Protokoll noch Formular verraten das Passwort.
        $this->assertStringNotContainsString('geheim-123', AuditLog::all()->toJson());
        $this->get(route('mail-accounts.edit', $account))->assertOk()->assertDontSee('geheim-123');
        $this->assertArrayNotHasKey('smtp_password', $account->toArray());
    }

    public function test_only_admins_manage_accounts(): void
    {
        $tenant = $this->makeTenant();
        $account = $this->makeMailAccount();

        foreach (['accounting', 'clerk', 'viewer'] as $role) {
            $this->actingAs($this->makeUser($tenant, $role))->withSession(['tenant_id' => $tenant->id]);

            $this->get('/mail-accounts')->assertForbidden();
            $this->get('/mail-accounts/create')->assertForbidden();
            $this->post('/mail-accounts', $this->validAccountData())->assertForbidden();
            $this->put(route('mail-accounts.update', $account), $this->validAccountData())->assertForbidden();
            $this->delete(route('mail-accounts.destroy', $account))->assertForbidden();
            $this->post(route('mail-accounts.test', $account))->assertForbidden();
        }

        $this->assertSame(1, MailAccount::count());
    }

    public function test_empty_password_field_keeps_the_stored_password(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser($tenant, 'admin');
        $account = $this->makeMailAccount();

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->put(route('mail-accounts.update', $account), $this->validAccountData(['name' => 'Neu', 'smtp_password' => '']))
            ->assertSessionHasNoErrors()->assertRedirect(route('mail-accounts.index'));
        $this->assertSame('Neu', $account->fresh()->name);
        $this->assertSame('geheim-123', $account->fresh()->smtp_password);

        $this->put(route('mail-accounts.update', $account), $this->validAccountData(['smtp_password' => 'anderes-pw']));
        $this->assertSame('anderes-pw', $account->fresh()->smtp_password);
    }

    public function test_input_is_validated(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id]);

        $this->post('/mail-accounts', $this->validAccountData([
            'host' => 'mail server; rm -rf', 'port' => '70000', 'encryption' => 'ssl3',
            'from_address' => 'kein-mail', 'reply_to' => 'auch-nicht', 'bcc' => 'ok@example.com, kaputt',
        ]))->assertSessionHasErrors(['host', 'port', 'encryption', 'from_address', 'reply_to', 'bcc']);

        $this->assertSame(0, MailAccount::count());
    }

    public function test_account_without_user_name_stores_no_password(): void
    {
        $tenant = $this->makeTenant();
        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id]);

        $this->post('/mail-accounts', $this->validAccountData(['username' => '', 'encryption' => 'none', 'port' => '25']))
            ->assertSessionHasNoErrors();

        $account = MailAccount::firstOrFail();
        $this->assertNull($account->username);
        $this->assertNull($account->smtp_password);
    }

    public function test_account_in_use_cannot_be_deleted(): void
    {
        $tenant = $this->makeTenant('Mandant Eins');
        $admin = $this->makeUser($tenant, 'admin');
        $account = $this->makeMailAccount();
        $tenant->update(['mail_account_id' => $account->id]);

        $this->actingAs($admin)->withSession(['tenant_id' => $tenant->id]);

        $this->delete(route('mail-accounts.destroy', $account))
            ->assertRedirect(route('mail-accounts.index'))->assertSessionHas('warning');
        $this->assertSame(1, MailAccount::count());

        $tenant->update(['mail_account_id' => null]);
        $this->delete(route('mail-accounts.destroy', $account))->assertSessionHas('status');
        $this->assertSame(0, MailAccount::count());
    }

    public function test_connection_test_checks_without_sending(): void
    {
        $tenant = $this->makeTenant();
        $account = $this->makeMailAccount();
        $fake = $this->fakeMailer();

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post(route('mail-accounts.test', $account))
            ->assertRedirect(route('mail-accounts.edit', $account))->assertSessionHas('status');

        $this->assertSame(1, $fake->checks);
        $this->assertCount(0, $fake->sent);
    }

    public function test_test_mail_is_sent_to_the_given_address(): void
    {
        $tenant = $this->makeTenant();
        $account = $this->makeMailAccount();
        $fake = $this->fakeMailer();

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post(route('mail-accounts.test', $account), ['to' => 'ich@example.com'])
            ->assertSessionHas('status');

        $this->assertCount(1, $fake->sent);
        $email = $fake->sent[0]['email'];
        $this->assertSame('ich@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('rechnung@example.com', $email->getFrom()[0]->getAddress());
    }

    public function test_failed_test_shows_the_server_message_but_never_the_password(): void
    {
        $tenant = $this->makeTenant();
        $account = $this->makeMailAccount();
        $fake = $this->fakeMailer();
        $fake->fail = new RuntimeException('535 Authentication failed');

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->post(route('mail-accounts.test', $account))
            ->assertRedirect(route('mail-accounts.edit', $account))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '535 Authentication failed') && ! str_contains($m, 'geheim-123'));
    }

    public function test_account_list_shows_all_accounts(): void
    {
        $tenant = $this->makeTenant();
        $this->makeMailAccount(['name' => 'Zugang Alpha']);
        $this->makeMailAccount(['name' => 'Zugang Beta']);

        $this->actingAs($this->makeUser($tenant, 'admin'))->withSession(['tenant_id' => $tenant->id])
            ->get('/mail-accounts')->assertOk()->assertSee('Zugang Alpha')->assertSee('Zugang Beta');
    }
}
