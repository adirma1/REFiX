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
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesMailSetup;
use Tests\Concerns\CreatesTenants;
use Tests\Support\FakeMailSender;
use Tests\TestCase;

/** Passwort vergessen (Link per E-Mail) und Zurücksetzen durch den Admin. */
class PasswordResetTest extends TestCase
{
    use CreatesMailSetup, CreatesTenants, RefreshDatabase;

    private const OLD = 'ein-langes-passwort-123';

    private const NEW = 'ganz-neues-passwort-456';

    private Tenant $tenant;

    private User $admin;

    private User $user;

    private FakeMailSender $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailer = $this->fakeMailer();
        $this->tenant = $this->makeTenant();
        $this->tenant->update(['mail_account_id' => $this->makeMailAccount()->id]);
        $this->admin = $this->makeUser($this->tenant, 'admin', ['email' => 'admin@example.com']);
        $this->user = $this->makeUser($this->tenant, 'clerk', ['email' => 'anna@example.com']);
    }

    /** Fordert den Link an und gibt den Pfad (mit Token und Adresse) aus der gesendeten Mail zurück. */
    private function requestLink(string $email = 'anna@example.com'): string
    {
        $this->post(route('password.email'), ['email' => $email])->assertRedirect(route('login'));

        $this->assertNotEmpty($this->mailer->sent, 'Es wurde keine Mail gesendet.');
        $text = $this->mailer->sent[array_key_last($this->mailer->sent)]['email']->getTextBody();
        $this->assertSame(1, preg_match('#https?://[^/]+(/reset-password/\S+)#', $text, $m));

        return $m[1];
    }

    private function resetData(string $path, array $overrides = []): array
    {
        $parts = parse_url($path);
        parse_str($parts['query'] ?? '', $query);

        return array_merge([
            'token' => basename($parts['path']),
            'email' => $query['email'] ?? 'anna@example.com',
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ], $overrides);
    }

    public function test_login_page_links_to_the_forgot_password_page(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get(route('password.request'))->assertOk()->assertSee(__('messages.auth.send_link'));
    }

    public function test_a_link_is_mailed_and_resets_the_password_once(): void
    {
        $path = $this->requestLink();

        $this->assertSame('anna@example.com', (string) $this->mailer->sent[0]['email']->getTo()[0]->getAddress());
        $this->assertStringContainsString('Passwort', (string) $this->mailer->sent[0]['email']->getSubject());

        $this->get($path)->assertOk()->assertSee('name="password_confirmation"', false);

        $this->post(route('password.update'), $this->resetData($path))
            ->assertRedirect(route('login'))->assertSessionHas('status');

        // Mit dem neuen Passwort anmelden geht, mit dem alten nicht mehr.
        $this->post('/login', ['login' => 'anna@example.com', 'password' => self::OLD])->assertSessionHasErrors('login');
        $this->post('/login', ['login' => 'anna@example.com', 'password' => self::NEW])->assertRedirect(route('dashboard'));

        // Der Link ist verbraucht.
        $this->post('/logout');
        $this->get($path)->assertRedirect(route('password.request'));
        $this->post(route('password.update'), $this->resetData($path, ['password' => 'noch-ein-passwort-789', 'password_confirmation' => 'noch-ein-passwort-789']))
            ->assertRedirect(route('password.request'));
        $this->assertTrue(\Hash::check(self::NEW, $this->user->fresh()->password));

        $this->assertTrue(AuditLog::where('action', 'user.password_reset')->exists());
    }

    public function test_reset_ends_sessions_and_clears_the_forced_change(): void
    {
        $this->user->forceFill(['must_change_password' => true])->save();
        DB::table('sessions')->insert([
            'id' => 'abc', 'user_id' => $this->user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'payload' => 'x', 'last_activity' => time(),
        ]);

        $path = $this->requestLink();
        $this->post(route('password.update'), $this->resetData($path))->assertRedirect(route('login'));

        $this->assertFalse($this->user->fresh()->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    public function test_unknown_addresses_get_the_same_answer_and_no_mail(): void
    {
        $this->post(route('password.email'), ['email' => 'niemand@example.com'])
            ->assertRedirect(route('login'))->assertSessionHas('status', __('messages.auth.reset_link_sent'));
        $this->post(route('password.email'), ['email' => 'anna@example.com'])
            ->assertRedirect(route('login'))->assertSessionHas('status', __('messages.auth.reset_link_sent'));

        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_inactive_users_and_tenants_without_mail_account_get_no_mail(): void
    {
        $this->user->forceFill(['active' => false])->save();
        $this->post(route('password.email'), ['email' => 'anna@example.com'])->assertRedirect(route('login'));
        $this->assertCount(0, $this->mailer->sent);

        $this->user->forceFill(['active' => true])->save();
        $this->tenant->update(['mail_account_id' => null]);
        $this->post(route('password.email'), ['email' => 'anna@example.com'])
            ->assertRedirect(route('login'))->assertSessionHas('status', __('messages.auth.reset_link_sent'));
        $this->assertCount(0, $this->mailer->sent);
        $this->assertTrue(AuditLog::where('action', 'user.password_reset_no_mail_account')->exists());
    }

    public function test_a_failing_mail_server_does_not_reveal_anything(): void
    {
        $this->mailer->fail = new \RuntimeException('Verbindung abgelehnt');

        $this->post(route('password.email'), ['email' => 'anna@example.com'])
            ->assertRedirect(route('login'))->assertSessionHas('status', __('messages.auth.reset_link_sent'));

        $this->assertTrue(AuditLog::where('action', 'user.password_reset_mail_failed')->exists());
    }

    public function test_links_expire_and_wrong_tokens_do_not_work(): void
    {
        $path = $this->requestLink();

        $this->post(route('password.update'), $this->resetData($path, ['token' => str_repeat('a', 64)]))
            ->assertRedirect(route('password.request'));
        $this->assertTrue(\Hash::check(self::OLD, $this->user->fresh()->password));

        $this->travel(61)->minutes();

        $this->get($path)->assertRedirect(route('password.request'));
        $this->post(route('password.update'), $this->resetData($path))->assertRedirect(route('password.request'));
        $this->assertTrue(\Hash::check(self::OLD, $this->user->fresh()->password));
    }

    public function test_weak_or_mismatching_passwords_are_rejected_and_the_link_stays_valid(): void
    {
        $path = $this->requestLink();

        $this->post(route('password.update'), $this->resetData($path, ['password' => 'kurz', 'password_confirmation' => 'kurz']))
            ->assertSessionHasErrors('password');
        $this->post(route('password.update'), $this->resetData($path, ['password_confirmation' => 'etwas-anderes-123']))
            ->assertSessionHasErrors('password');

        $this->get($path)->assertOk();
        $this->post(route('password.update'), $this->resetData($path))->assertRedirect(route('login'));
    }

    public function test_users_with_two_factor_need_a_code_or_recovery_code(): void
    {
        $google = app(Google2FA::class);
        $secret = $google->generateSecretKey();
        $this->user->forceFill([
            'two_factor_secret' => $secret, 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb'],
        ])->save();

        $path = $this->requestLink();
        $this->get($path)->assertOk()->assertSee('name="code"', false);

        // Ohne Code und mit falschem Code geht es nicht.
        $this->post(route('password.update'), $this->resetData($path))->assertSessionHasErrors('code');
        $this->post(route('password.update'), $this->resetData($path, ['code' => '000000']))->assertSessionHasErrors('code');
        $this->assertTrue(\Hash::check(self::OLD, $this->user->fresh()->password));

        // Mit dem richtigen Code schon.
        $this->post(route('password.update'), $this->resetData($path, ['code' => $google->getCurrentOtp($secret)]))
            ->assertRedirect(route('login'));
        $this->assertTrue(\Hash::check(self::NEW, $this->user->fresh()->password));
    }

    public function test_a_recovery_code_also_works_but_only_once(): void
    {
        $this->user->forceFill([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'],
        ])->save();

        $path = $this->requestLink();
        $this->post(route('password.update'), $this->resetData($path, ['recovery_code' => 'AAAAA-bbbbb']))
            ->assertRedirect(route('login'));

        $this->assertSame(['ccccc-ddddd'], $this->user->fresh()->two_factor_recovery_codes);
    }

    // ---- Zurücksetzen durch den Admin ----

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id]);
    }

    public function test_admin_creates_a_temporary_password_that_must_be_changed(): void
    {
        DB::table('sessions')->insert([
            'id' => 'xyz', 'user_id' => $this->user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x',
            'payload' => 'x', 'last_activity' => time(),
        ]);

        $this->asAdmin()->post(route('users.reset-password', $this->user))
            ->assertRedirect(route('users.index'))->assertSessionHas('temp_password');

        $temp = session('temp_password')['password'];
        $this->assertSame(16, strlen($temp));

        $user = $this->user->fresh();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(\Hash::check($temp, $user->password));
        $this->assertFalse(\Hash::check(self::OLD, $user->password));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertTrue(AuditLog::where('action', 'user.password_reset_by_admin')->exists());

        // Das Passwort wird angezeigt – einmal.
        $this->get(route('users.index'))->assertOk()->assertSee($temp);
        $this->get(route('users.index'))->assertDontSee($temp);
    }

    public function test_admin_reset_has_limits(): void
    {
        // Nicht für sich selbst.
        $this->asAdmin()->post(route('users.reset-password', $this->admin))->assertSessionHasErrors('role');

        // Nicht für Benutzer, die auch anderen Mandanten angehören.
        $other = $this->makeTenant('Andere GmbH');
        $this->user->tenants()->attach($other->id, ['role' => 'admin']);
        $this->asAdmin()->post(route('users.reset-password', $this->user))->assertSessionHasErrors('role');
        $this->assertTrue(\Hash::check(self::OLD, $this->user->fresh()->password));

        // Nicht für Fremde.
        $stranger = $this->makeUser($other, 'clerk');
        $this->asAdmin()->post(route('users.reset-password', $stranger))->assertNotFound();
    }

    public function test_only_admins_may_reset_passwords(): void
    {
        $accounting = $this->makeUser($this->tenant, 'accounting');

        $this->actingAs($accounting)->withSession(['tenant_id' => $this->tenant->id])
            ->post(route('users.reset-password', $this->user))->assertForbidden();

        $this->assertTrue(\Hash::check(self::OLD, $this->user->fresh()->password));
    }

    public function test_user_list_has_the_reset_button(): void
    {
        $this->asAdmin()->get(route('users.index'))->assertOk()
            ->assertSee(route('users.reset-password', $this->user), false)
            ->assertSee(__('messages.users.reset_password'));
    }
}
