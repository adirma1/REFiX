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

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Mail\MailSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Passwort vergessen: Link per E-Mail (einmalig, zeitlich begrenzt) und Zurücksetzen.
 * Der Link geht über den E-Mail-Zugang eines Mandanten, dem der Benutzer angehört. Hat der Benutzer die Zwei-Faktor-Anmeldung,
 * wird beim Zurücksetzen zusätzlich ein Code verlangt – der Zugriff auf das Postfach allein genügt dann nicht.
 */
class PasswordResetService
{
    /** Gültigkeit des Links in Minuten. */
    public const TTL_MINUTES = 60;

    public function __construct(private MailSender $sender) {}

    /**
     * Schickt den Link, falls es den Benutzer gibt und ein E-Mail-Zugang eingerichtet ist. Tut nach außen immer dasselbe,
     * damit niemand herausfinden kann, welche Adressen es gibt.
     */
    public function request(string $email): void
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! $user->active) {
            return;
        }

        $tenant = $user->tenants()->whereNotNull('mail_account_id')->with('mailAccount')->orderBy('tenants.id')->first();
        $account = $tenant?->mailAccount;

        if (! $account) {
            AuditLog::record('user.password_reset_no_mail_account', $user);

            return;
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => hash('sha256', $token), 'created_at' => now()]
        );

        $url = route('password.reset', ['token' => $token, 'email' => $user->email]);

        $body = "Guten Tag {$user->name},\n\n"
            ."für Ihr Konto bei ".config('app.brand')." wurde das Zurücksetzen des Passworts angefordert.\n\n"
            ."Über diesen Link können Sie ein neues Passwort vergeben (gültig ".self::TTL_MINUTES." Minuten, nur einmal verwendbar):\n\n"
            ."{$url}\n\n"
            ."Wenn Sie das nicht angefordert haben, ignorieren Sie diese Nachricht. Ihr Passwort bleibt dann unverändert.\n";

        $message = (new Email)
            ->from(new Address($account->from_address, (string) $account->from_name))
            ->to(new Address($user->email))
            ->subject('Passwort zurücksetzen – '.config('app.brand'))
            ->text($body);

        try {
            $this->sender->send($account, $message);
            AuditLog::record('user.password_reset_requested', $user);
        } catch (Throwable $e) {
            AuditLog::record('user.password_reset_mail_failed', $user, ['error' => mb_substr(trim($e->getMessage()) ?: $e::class, 0, 300)]);
        }
    }

    /** Liefert den Benutzer zu einem gültigen, nicht abgelaufenen Link; sonst null. */
    public function findValid(?string $email, ?string $token): ?User
    {
        if (! filled($email) || ! filled($token)) {
            return null;
        }

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (! $row || ! $row->created_at || ! hash_equals((string) $row->token, hash('sha256', $token))) {
            return null;
        }

        if (Carbon::parse($row->created_at)->addMinutes(self::TTL_MINUTES)->isPast()) {
            return null;
        }

        $user = User::where('email', $email)->first();

        return $user && $user->active ? $user : null;
    }

    /** Prüft den zweiten Faktor: Code aus der App oder einen Wiederherstellungscode (wird dabei verbraucht). */
    public function verifySecondFactor(User $user, ?string $code, ?string $recovery): bool
    {
        $code = preg_replace('/\s+/', '', (string) $code);
        $recovery = trim((string) $recovery);

        if ($code !== '') {
            $timestamp = app(Google2FA::class)->verifyKeyNewer($user->two_factor_secret, $code, (int) $user->two_factor_last_ts);

            if ($timestamp === false) {
                return false;
            }

            $user->forceFill(['two_factor_last_ts' => $timestamp])->save();

            return true;
        }

        if ($recovery !== '') {
            $codes = $user->two_factor_recovery_codes ?? [];

            foreach ($codes as $index => $stored) {
                if (hash_equals($stored, Str::lower($recovery))) {
                    unset($codes[$index]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    AuditLog::record('login.recovery_code_used', $user);

                    return true;
                }
            }
        }

        return false;
    }

    /** Setzt das neue Passwort, macht den Link ungültig und beendet alle Sitzungen des Benutzers. */
    public function reset(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'must_change_password' => false,
            'remember_token' => Str::random(60),
        ])->save();

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        $this->endSessions($user);

        AuditLog::record('user.password_reset', $user);
    }

    public function endSessions(User $user): void
    {
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
