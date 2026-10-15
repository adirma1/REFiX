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

use App\Support\TwoFactorPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TwoFactorPreferenceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const PASSWORD = 'ein-langes-passwort-123';
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_default_is_on_and_follows_the_environment_default_until_the_user_decides(): void
    {
        $user = $this->makeUser($this->makeTenant());

        config(['invoice.require_2fa' => true]);
        $this->assertTrue(TwoFactorPolicy::requiredFor($user));

        config(['invoice.require_2fa' => false]);
        $this->assertFalse(TwoFactorPolicy::requiredFor($user));

        $user->forceFill(['two_factor_preference' => true])->save();
        $this->assertTrue(TwoFactorPolicy::requiredFor($user->fresh()), 'eigene Entscheidung hat Vorrang');
    }

    public function test_user_turns_two_factor_off_with_password_and_can_work_without_it(): void
    {
        config(['invoice.require_2fa' => true]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $user->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => ['a']])->save();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/two-factor/preference', ['current_password' => self::PASSWORD])
            ->assertRedirect(route('profile.two-factor'))->assertSessionHasNoErrors();

        $fresh = $user->fresh();
        $this->assertFalse($fresh->hasTwoFactor());
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_recovery_codes);
        $this->assertFalse((bool) $fresh->two_factor_preference);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.two_factor_disabled']);

        // Kein Zwang mehr, obwohl der Standard "an" ist.
        $this->actingAs($fresh)->withSession(['tenant_id' => $tenant->id])->get('/customers')->assertOk();
    }

    public function test_turning_off_needs_the_correct_password(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $user->forceFill(['two_factor_secret' => self::SECRET, 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/two-factor/preference', ['current_password' => 'falsch'])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_user_turns_it_on_again_and_is_sent_to_the_setup(): void
    {
        config(['invoice.require_2fa' => false]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $user->forceFill(['two_factor_preference' => false])->save();

        // Aus: die Seite zeigt keinen QR-Code.
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->get('/profile/two-factor')->assertOk()->assertDontSee('<svg', false);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])
            ->post('/profile/two-factor/preference', ['enabled' => '1'])
            ->assertRedirect(route('profile.two-factor'));

        $this->assertTrue((bool) $user->fresh()->two_factor_preference);

        // Eingeschaltet, aber noch nicht eingerichtet: Setup wird eingefordert.
        $this->actingAs($user->fresh())->withSession(['tenant_id' => $tenant->id])->get('/customers')
            ->assertRedirect(route('profile.two-factor'));
        $this->actingAs($user->fresh())->withSession(['tenant_id' => $tenant->id])
            ->get('/profile/two-factor')->assertOk()->assertSee('<svg', false);
    }

    public function test_user_who_has_not_decided_is_still_forced_into_setup_by_default(): void
    {
        config(['invoice.require_2fa' => true]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);

        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id])->get('/customers')
            ->assertRedirect(route('profile.two-factor'));
    }

    public function test_one_users_choice_does_not_affect_others(): void
    {
        config(['invoice.require_2fa' => true]);
        $tenant = $this->makeTenant();
        $a = $this->makeUser($tenant, 'clerk');
        $b = $this->makeUser($tenant, 'clerk');
        $a->forceFill(['two_factor_preference' => false])->save();

        $this->actingAs($a->fresh())->withSession(['tenant_id' => $tenant->id])->get('/customers')->assertOk();
        $this->actingAs($b)->withSession(['tenant_id' => $tenant->id])->get('/customers')->assertRedirect(route('profile.two-factor'));
    }

    public function test_confirming_the_setup_remembers_the_choice(): void
    {
        config(['invoice.require_2fa' => true]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser($tenant);
        $this->actingAs($user)->withSession(['tenant_id' => $tenant->id]);

        $this->get('/profile/two-factor');
        $secret = $user->fresh()->two_factor_secret;
        $this->post('/profile/two-factor', ['code' => app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('profile.two-factor'));

        $this->assertTrue((bool) $user->fresh()->two_factor_preference);
    }
}
