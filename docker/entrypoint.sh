#!/bin/sh
# RE-FiX – Rechnungsprogramm
# Copyright (C) 2026 Martin Adirsch
#
# This program is free software; you can redistribute it and/or modify it under the terms of
# version 2 of the GNU General Public License as published by the Free Software Foundation.
#
# This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
# without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
# See the GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License along with this program
# (file LICENSE). If not, see <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>.
#
# SPDX-License-Identifier: GPL-2.0-only
set -e
cd /var/www/html

mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Ohne APP_KEY in der Umgebung erzeugt der App-Container beim ersten Start einen Schlüssel und legt ihn im
# Storage ab (config/app.php liest ihn von dort). Der Worker wartet darauf. Der Schlüssel muss mitgesichert werden.
if [ -z "$APP_KEY" ]; then
    KEYFILE=storage/app/.app_key
    if [ "$RUN_MIGRATIONS" = "true" ] && [ ! -s "$KEYFILE" ]; then
        php -r 'echo "base64:".base64_encode(random_bytes(32));' > "$KEYFILE"
        chmod 600 "$KEYFILE"
        echo "Neuer APP_KEY wurde erzeugt und in $KEYFILE gespeichert."
    fi

    waited=0
    while [ ! -s "$KEYFILE" ] && [ "$waited" -lt 60 ]; do
        sleep 2
        waited=$((waited + 1))
    done

    if [ ! -s "$KEYFILE" ]; then
        echo "FEHLER: Kein APP_KEY vorhanden und keiner erzeugt worden." >&2
        exit 1
    fi
fi

# Zwischengespeicherte (kompilierte) Seiten verwerfen: Sie liegen im Storage-Volume und überleben ein Update.
# Haben die neuen Dateien einen älteren Zeitstempel als der Zwischenspeicher, würde sonst die alte Seite gezeigt.
rm -f storage/framework/views/*.php

chown -R www-data:www-data storage bootstrap/cache

php artisan package:discover --ansi || true

if [ "$RUN_MIGRATIONS" = "true" ]; then
    echo "Warte auf die Datenbank und führe Migrationen aus ..."
    until php artisan migrate --force; do
        echo "Datenbank noch nicht bereit, neuer Versuch in 3 Sekunden ..."
        sleep 3
    done

    # Optional: Admin aus der .env anlegen (nur wenn noch kein Benutzer existiert).
    if [ -n "$ADMIN_EMAIL" ]; then
        php artisan user:create-admin --from-env --if-none || true
    fi
fi

# Logdateien, die als root entstanden sind, dem Webserver-Benutzer übergeben.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
