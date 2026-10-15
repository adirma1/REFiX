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
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        return view('profile.show', ['user' => $request->user()]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $data['password'],
            'must_change_password' => false,
        ])->save();

        // Andere Sitzungen dieses Benutzers beenden.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        AuditLog::record('user.password_changed', $user);

        return redirect()->route('profile.show')->with('status', __('messages.profile.password_changed'));
    }

    /** Der Benutzer ändert seine E-Mail-Adresse selbst; zur Sicherheit mit dem aktuellen Passwort. */
    public function updateEmail(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'email_password' => ['required', 'current_password'],
        ]);

        $old = $user->email;
        $user->forceFill(['email' => $data['email']])->save();

        // Offene Links zum Zurücksetzen gehören zur alten Adresse und werden ungültig.
        DB::table('password_reset_tokens')->where('email', $old)->delete();

        return redirect()->route('profile.show')->with('status', __('messages.profile.email_changed'));
    }

    public function updatePerPage(Request $request): RedirectResponse
    {
        $data = $request->validate(['per_page' => ['required', 'integer', 'in:'.implode(',', PerPage::OPTIONS)]]);

        $request->user()->forceFill(['per_page' => (int) $data['per_page']])->save();

        // Zurück zur selben Liste (Filter bleiben), aber auf Seite 1, da sich die Seitenaufteilung ändert.
        $previous = url()->previous();
        $parts = parse_url($previous);
        $sameHost = ($parts['host'] ?? null) === $request->getHost();
        if (! $sameHost) {
            return redirect()->route('dashboard');
        }
        parse_str($parts['query'] ?? '', $query);
        unset($query['page']);

        return redirect(($parts['path'] ?? '/').($query ? '?'.http_build_query($query) : ''));
    }

    public function updateLocale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', config('invoice.locales'))]]);

        $request->user()->forceFill(['locale' => $data['locale']])->save();
        $request->session()->put('locale', $data['locale']);

        return redirect()->route('profile.show')->with('status', __('messages.saved'));
    }
}
