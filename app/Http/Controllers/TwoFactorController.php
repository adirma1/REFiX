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

use App\Models\AuditLog;
use App\Support\TwoFactorPolicy;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return view('profile.two-factor', [
                'enabled' => true,
                'secret' => null,
                'qr' => null,
                'wanted' => true,
                'recoveryCodes' => session('recovery_codes'),
                'remaining' => count($user->two_factor_recovery_codes ?? []),
            ]);
        }

        // Der Benutzer hat die Zwei-Faktor-Anmeldung abgeschaltet: nichts einzurichten.
        if (! TwoFactorPolicy::requiredFor($user)) {
            return view('profile.two-factor', [
                'enabled' => false,
                'wanted' => false,
                'secret' => null,
                'qr' => null,
                'recoveryCodes' => null,
                'remaining' => 0,
            ]);
        }

        $google2fa = app(Google2FA::class);

        if (! $user->two_factor_secret) {
            $user->forceFill(['two_factor_secret' => $google2fa->generateSecretKey(32)])->save();
        }

        $url = $google2fa->getQRCodeUrl(config('app.name'), $user->email, $user->two_factor_secret);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

        return view('profile.two-factor', [
            'enabled' => false,
            'secret' => $user->two_factor_secret,
            'qr' => substr($svg, (int) strpos($svg, '<svg')),
            'wanted' => true,
            'recoveryCodes' => null,
            'remaining' => 0,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $user = $request->user();
        abort_if($user->hasTwoFactor() || ! $user->two_factor_secret, 404);

        $timestamp = app(Google2FA::class)->verifyKeyNewer(
            $user->two_factor_secret, preg_replace('/\s+/', '', $request->input('code')), 0
        );

        if ($timestamp === false) {
            throw ValidationException::withMessages(['code' => __('messages.two_factor.invalid_code')]);
        }

        $codes = $this->newRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_preference' => true,
            'two_factor_last_ts' => $timestamp,
            'two_factor_recovery_codes' => $codes,
        ])->save();

        AuditLog::record('user.two_factor_enabled', $user);

        return redirect()->route('profile.two-factor')->with('recovery_codes', $codes);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 404);

        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        AuditLog::record('user.recovery_codes_regenerated', $user);

        return redirect()->route('profile.two-factor')->with('recovery_codes', $codes);
    }

    /**
     * Jeder Benutzer entscheidet selbst, ob er die Zwei-Faktor-Anmeldung verwendet.
     * Ausschalten (mit aktuellem Passwort) löscht den Schlüssel und die Wiederherstellungscodes.
     * Einschalten führt zur Einrichtung (QR-Code); bis sie bestätigt ist, wird sie wie beim Standard eingefordert.
     */
    public function preference(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($request->boolean('enabled')) {
            $user->forceFill(['two_factor_preference' => true])->save();
            AuditLog::record('user.two_factor_wanted', $user);

            return redirect()->route('profile.two-factor');
        }

        $request->validate(['current_password' => ['required', 'current_password']]);

        $user->forceFill([
            'two_factor_preference' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_ts' => null,
        ])->save();

        AuditLog::record('user.two_factor_disabled', $user);

        return redirect()->route('profile.two-factor')->with('status', __('messages.two_factor.switched_off'));
    }

    /** @return array<int, string> */
    private function newRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();
    }
}
