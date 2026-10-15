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
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        // Noch kein Benutzer vorhanden: zur Ersteinrichtung.
        if (! User::query()->exists()) {
            return redirect()->route('setup');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $data['login'] = trim($data['login']);
        $key = 'login|'.Str::lower($data['login']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'login' => __('messages.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        // Anmeldung wahlweise mit Benutzername oder E-Mail-Adresse.
        $user = User::findByLogin($data['login']);

        if (! $user || ! $user->active || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            AuditLog::record('login.failed', null, ['login' => $data['login']]);

            throw ValidationException::withMessages(['login' => __('messages.auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        if ($user->hasTwoFactor()) {
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        AuditLog::record('login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function showChallenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('login.id');

        if (! $userId) {
            return redirect()->route('login');
        }

        $user = User::findOrFail($userId);
        $key = '2fa|'.$userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => __('messages.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $code = preg_replace('/\s+/', '', (string) $request->input('code'));
        $recovery = trim((string) $request->input('recovery_code'));
        $ok = false;

        if ($code !== '') {
            $timestamp = app(Google2FA::class)->verifyKeyNewer(
                $user->two_factor_secret, $code, (int) $user->two_factor_last_ts
            );

            if ($timestamp !== false) {
                $user->forceFill(['two_factor_last_ts' => $timestamp])->save();
                $ok = true;
            }
        } elseif ($recovery !== '') {
            $codes = $user->two_factor_recovery_codes ?? [];

            foreach ($codes as $index => $stored) {
                if (hash_equals($stored, Str::lower($recovery))) {
                    unset($codes[$index]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    AuditLog::record('login.recovery_code_used', $user);
                    $ok = true;
                    break;
                }
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['code' => __('messages.two_factor.invalid_code')]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');
        $request->session()->regenerate();

        Auth::login($user, $remember);
        AuditLog::record('login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        AuditLog::record('logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function locale(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, config('invoice.locales'), true), 404);

        $request->session()->put('locale', $locale);
        $request->user()?->forceFill(['locale' => $locale])->save();

        return back();
    }
}
