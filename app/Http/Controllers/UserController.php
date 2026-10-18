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
use App\Models\Tenant;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Support\ListSort;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Illuminate\Validation\ValidationException;

/**
 * Benutzerverwaltung des aktiven Mandanten. Nur für Admins.
 */
class UserController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manage-users');

        $sort = ListSort::make($request, 'users', [
            'name' => 'users.name', 'username' => 'users.username', 'email' => 'users.email',
            'role' => 'tenant_user.role', 'two_factor' => 'users.two_factor_confirmed_at',
        ], ['name', 'asc'], [['users.id', 'asc']]);

        return view('users.index', [
            'sort' => $sort,
            'users' => $this->tenant()->users()->tap(fn ($q) => $sort->apply($q))->get(),
            'roles' => config('invoice.roles'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage-users');

        $tenant = $this->tenant();
        $roles = config('invoice.roles');

        $base = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($roles)],
        ]);

        $user = User::where('email', $base['email'])->first();

        if ($user) {
            // Vorhandenen Benutzer diesem Mandanten zuordnen.
            if ($tenant->users()->whereKey($user->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('messages.users.already_member')]);
            }

            $tenant->users()->attach($user->id, ['role' => $base['role']]);
        } else {
            $new = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'username' => User::usernameRules(),
                'password' => ['required', Password::min(12)],
            ]);

            $user = User::create([
                'name' => $new['name'],
                'username' => $new['username'] ?? null,
                'email' => $base['email'],
                'password' => $new['password'],
                'locale' => 'de',
                'must_change_password' => true,
            ]);

            $tenant->users()->attach($user->id, ['role' => $base['role']]);
        }

        AuditLog::record('tenant.user_added', $user, ['role' => $base['role']]);

        return redirect()->route('users.index')->with('status', __('messages.users.added', ['name' => $user->name]));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        $tenant = $this->tenant();
        $data = $request->validate(['role' => ['required', Rule::in(config('invoice.roles'))]]);

        abort_unless($tenant->users()->whereKey($user->id)->exists(), 404);

        // Die Zeile in der Benutzerliste speichert Rolle und Benutzername zusammen; erst alles prüfen, dann speichern.
        $withUsername = $request->exists('username');

        if ($withUsername) {
            [$username, $error] = $this->checkUsername($request, $user);

            if ($error) {
                return back()->withErrors(['role' => $error]);
            }
        }

        if ($data['role'] !== 'admin' && $this->isLastAdmin($tenant, $user)) {
            return back()->withErrors(['role' => __('messages.users.last_admin')]);
        }

        $tenant->users()->updateExistingPivot($user->id, ['role' => $data['role']]);
        $user->forgetRoleCache();

        if ($withUsername && $username !== $user->username) {
            $user->forceFill(['username' => $username])->save();
        }

        AuditLog::record('tenant.user_role_changed', $user, ['role' => $data['role']]);

        return redirect()->route('users.index')->with('status', __('messages.saved'));
    }

    /**
     * Vergibt, ändert oder entfernt den Benutzernamen. Der Name gilt für die ganze Anwendung; deshalb darf ihn nur ein
     * Admin ändern, der in allen Mandanten des Benutzers Admin ist.
     */
    public function updateUsername(Request $request, User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 404);

        [$username, $error] = $this->checkUsername($request, $user);

        if ($error) {
            return back()->withErrors(['role' => $error]);
        }

        $user->forceFill(['username' => $username])->save();

        return redirect()->route('users.index')->with('status', __('messages.users.username_saved', ['name' => $user->name]));
    }

    /**
     * Setzt das Passwort eines Benutzers zurück: Es entsteht ein vorläufiges Passwort, das nur jetzt angezeigt wird.
     * Der Benutzer muss es beim nächsten Anmelden ändern; seine Sitzungen werden beendet.
     * Nur für Benutzer, die ausschließlich diesem Mandanten angehören – sonst könnte ein Admin sich Zugang zu fremden
     * Mandanten verschaffen.
     */
    public function resetPassword(User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 404);

        if ($user->id === auth()->id()) {
            return back()->withErrors(['role' => __('messages.users.reset_self')]);
        }

        if ($user->tenants()->count() > 1) {
            return back()->withErrors(['role' => __('messages.users.reset_multi')]);
        }

        // Ohne leicht verwechselbare Zeichen (0/O, 1/l/I), damit es sich gut abtippen lässt.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $user->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'remember_token' => \Illuminate\Support\Str::random(60),
        ])->save();

        app(PasswordResetService::class)->endSessions($user);

        AuditLog::record('user.password_reset_by_admin', $user);

        return redirect()->route('users.index')->with('temp_password', ['name' => $user->name, 'password' => $password]);
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('manage-users');

        $tenant = $this->tenant();
        abort_unless($tenant->users()->whereKey($user->id)->exists(), 404);

        if ($this->isLastAdmin($tenant, $user)) {
            return back()->withErrors(['role' => __('messages.users.last_admin')]);
        }

        $tenant->users()->detach($user->id);

        // Gehört der Benutzer keinem Mandanten mehr, werden seine Sitzungen beendet.
        if (! $user->tenants()->exists() && Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }

        AuditLog::record('tenant.user_removed', $user);

        return redirect()->route('users.index')->with('status', __('messages.users.removed', ['name' => $user->name]));
    }

    /**
     * Prüft den Benutzernamen aus der Anfrage. Liefert [neuer Wert|null, Fehlermeldung|null]. Fehler erscheinen oben als
     * Hinweis (wie bei den anderen Aktionen der Tabelle), nicht im Formular "Hinzufügen". Bleibt der Name unverändert,
     * ist alles erlaubt (z. B. nur die Rolle eines Benutzers mit mehreren Mandanten ändern).
     */
    private function checkUsername(Request $request, User $user): array
    {
        $validator = Validator::make($request->only('username'), ['username' => User::usernameRules($user->id)],
            [], ['username' => __('messages.users.username')]);

        if ($validator->fails()) {
            return [null, $validator->errors()->first('username')];
        }

        $username = $validator->validated()['username'] ?? null;

        if ($username === $user->username) {
            return [$username, null];
        }

        $me = $request->user();
        $foreign = $user->tenants()->pluck('tenants.id')->contains(fn ($id) => $me->roleIn((int) $id) !== 'admin');

        return $foreign ? [null, __('messages.users.username_multi')] : [$username, null];
    }

    private function tenant(): Tenant
    {
        return Tenant::findOrFail(Tenancy::id());
    }

    private function isLastAdmin(Tenant $tenant, User $user): bool
    {
        $isAdmin = $tenant->users()->whereKey($user->id)->wherePivot('role', 'admin')->exists();

        return $isAdmin && $tenant->adminCount() <= 1;
    }
}
