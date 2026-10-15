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

use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PASSWORD = 'ein-langes-passwort-123';

    public function test_login_with_correct_credentials(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);

        $this->post('/login', ['login' => 'a@example.com', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);

        $this->post('/login', ['login' => 'a@example.com', 'password' => 'falsch'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_is_blocked_for_inactive_user(): void
    {
        $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com', 'active' => false]);

        $this->post('/login', ['login' => 'a@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_is_throttled_after_five_failures(): void
    {
        $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'a@example.com', 'password' => 'falsch']);
        }

        // Selbst das richtige Passwort wird jetzt abgewiesen.
        $this->post('/login', ['login' => 'a@example.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->makeUser($this->makeTenant());

        $this->get('/customers')->assertRedirect(route('login'));
    }

    public function test_user_with_forced_password_change_is_sent_to_profile(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'admin', ['must_change_password' => true]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/customers')
            ->assertRedirect(route('profile.show'));
    }

    public function test_changing_password_clears_the_forced_change(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant, 'admin', ['must_change_password' => true]);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/password', [
                'current_password' => self::PASSWORD,
                'password' => 'ein-ganz-neues-passwort',
                'password_confirmation' => 'ein-ganz-neues-passwort',
            ])->assertRedirect(route('profile.show'));

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_missing_two_factor_is_enforced_when_required(): void
    {
        config(['invoice.require_2fa' => true]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/customers')
            ->assertRedirect(route('profile.two-factor'));
    }

    public function test_two_factor_setup_can_be_confirmed_and_issues_recovery_codes(): void
    {
        config(['invoice.require_2fa' => true]);

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        // Aufruf der Seite erzeugt das Geheimnis.
        $this->get('/profile/two-factor')->assertOk()->assertSee('<svg', false);

        $secret = $user->fresh()->two_factor_secret;
        $this->assertNotEmpty($secret);

        $this->post('/profile/two-factor', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('profile.two-factor'));

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactor());
        $this->assertCount(8, $fresh->two_factor_recovery_codes);

        // Danach ist der Zugriff auf die Anwendung frei.
        $this->get('/customers')->assertOk();
    }

    public function test_after_setup_the_recovery_codes_page_offers_to_continue(): void
    {
        config(['invoice.require_2fa' => true]); // Standard: an, daher zeigt die Seite die Einrichtung

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/profile/two-factor');
        $this->post('/profile/two-factor', ['code' => app(Google2FA::class)->getCurrentOtp($user->fresh()->two_factor_secret)])
            ->assertRedirect(route('profile.two-factor'));

        // Direkt nach dem Einrichten: Codes, Weiter-Knopf – und kein Formular für neue Codes.
        $this->get('/profile/two-factor')->assertOk()
            ->assertSee(__('messages.two_factor.recovery_hint'))
            ->assertSee(__('messages.two_factor.continue'))
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertDontSee(route('profile.two-factor.regenerate'), false);

        // Beim nächsten Aufruf ist das Formular wieder da, die Codes nicht.
        $this->get('/profile/two-factor')->assertOk()
            ->assertDontSee(__('messages.two_factor.recovery_hint'))
            ->assertSee(route('profile.two-factor.regenerate'), false);
    }

    public function test_two_factor_setup_rejects_wrong_code(): void
    {
        config(['invoice.require_2fa' => true]); // Standard: an, daher zeigt die Seite die Einrichtung

        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/profile/two-factor');

        $this->post('/profile/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_login_with_two_factor_requires_the_code(): void
    {
        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey();

        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['login' => 'a@example.com', 'password' => self::PASSWORD])
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        $this->withSession(['login.id' => $user->id])
            ->post('/two-factor', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->withSession(['login.id' => $user->id])
            ->post('/two-factor', ['code' => $google2fa->getCurrentOtp($secret)])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->makeUser($this->makeTenant(), 'admin', ['email' => 'a@example.com']);
        $user->forceFill([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['aaaaa-bbbbb', 'ccccc-ddddd'],
        ])->save();

        $this->withSession(['login.id' => $user->id])
            ->post('/two-factor', ['recovery_code' => 'aaaaa-bbbbb'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(['ccccc-ddddd'], $user->fresh()->two_factor_recovery_codes);
    }
}
