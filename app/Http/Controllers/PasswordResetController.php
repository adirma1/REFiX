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
use App\Services\PasswordResetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** „Passwort vergessen?“ auf der Anmeldeseite. */
class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $service) {}

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        $this->service->request($data['email']);

        // Immer dieselbe Antwort, egal ob es die Adresse gibt.
        return redirect()->route('login')->with('status', __('messages.auth.reset_link_sent'));
    }

    public function form(Request $request, string $token): View|RedirectResponse
    {
        $user = $this->service->findValid($request->query('email'), $token);

        if (! $user) {
            return redirect()->route('password.request')->withErrors(['email' => __('messages.auth.reset_invalid')]);
        }

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $user->email,
            'needsTwoFactor' => $user->hasTwoFactor(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $key = 'pwreset|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'password' => __('messages.auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = $this->service->findValid($data['email'], $data['token']);

        if (! $user) {
            RateLimiter::hit($key, 60);

            return redirect()->route('password.request')->withErrors(['email' => __('messages.auth.reset_invalid')]);
        }

        if ($user->hasTwoFactor() && ! $this->service->verifySecondFactor($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            RateLimiter::hit($key, 60);
            AuditLog::record('user.password_reset_2fa_failed', $user);

            throw ValidationException::withMessages(['code' => __('messages.two_factor.invalid_code')]);
        }

        RateLimiter::clear($key);
        $this->service->reset($user, $data['password']);

        return redirect()->route('login')->with('status', __('messages.auth.reset_done'));
    }
}
