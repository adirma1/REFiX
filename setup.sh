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
# Erzeugt die .env mit zufälligen Schlüsseln und Datenbank-Passwörtern.
set -e
cd "$(dirname "$0")"

if [ -f .env ]; then
    echo ".env existiert bereits, nichts geändert."
    exit 0
fi

rand() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32; }

cp .env.example .env
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rand)|" .env
sed -i "s|^DB_ROOT_PASSWORD=.*|DB_ROOT_PASSWORD=$(rand)|" .env
chmod 600 .env

echo ".env wurde erzeugt. Bitte APP_URL und APP_PORT prüfen, dann: docker compose up -d --build"
echo "WICHTIG: Sichere die .env (APP_KEY!). Ohne APP_KEY sind verschlüsselte Daten (z. B. 2FA-Schlüssel) nicht mehr lesbar."
