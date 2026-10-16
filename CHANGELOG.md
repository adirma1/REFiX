# Änderungsverlauf

Versionsnummer: Haupt.Neben.Korrektur (steht in `config/app.php` unter `version`).

## 1.3.0 – 2026-10-16
- Mandant ohne Umsatzsteuer (z. B. Verein, Kleinunternehmer): Unter Mandanten gibt es den Schalter „Dieser Mandant weist keine Umsatzsteuer aus“ mit einem
  Hinweistext (Vorschläge: § 19 UStG, § 4 UStG, nicht steuerbar; frei änderbar). Neue Rechnungen, Angebote und wiederkehrende Rechnungen
  dieses Mandanten haben dann den Steuerfall „Ohne Umsatzsteuer“ (Steuersatz 0 %, nicht änderbar). Der Hinweis steht unter den Summen im PDF und in der Belegansicht;
  die E-Rechnung (ZUGFeRD) meldet die Kategorie E mit dem Hinweis als Befreiungsgrund und verlangt keine USt-IdNr. (die Steuernummer genügt).
  Der Text wird beim Beleg festgehalten: freigegebene Belege ändern sich nicht, Gutschriften folgen der Rechnung. Neue Migration `add_vat_free_to_tenants_and_documents`.

## 1.2.0 – 2026-10-15
- Kunden: neue Felder „Kunde seit“ (Vorbelegung heute, änderbar), „Kunde bis“, Häkchen „Aktiv“ und Tags (30 Zeichen). Liste mit Status-Spalte,
  Filtern für Status und Tag und Suche in Tags. Neue Migration `add_lifecycle_to_customers` (bestehende Kunden: seit 01.01.2026, aktiv).
- Sperre: Inaktive Kunden und Kunden nach ihrem „bis“-Datum bekommen keine neuen Rechnungen (Anlegen und Freigeben, auch wiederkehrend
  und aus Angeboten); Gutschriften und Angebote bleiben möglich. Gesperrte Kunden erscheinen nicht in der Kundenauswahl.
- Dashboard: neue Kundenkarte mit Gesamt / Aktiv / Inaktiv (mit Prozent) und den aktiven Kunden je Tag als Balken (die 8 häufigsten Tags, klickbar in die gefilterte Kundenliste).
- „Aktiv“ wird am Tag nach dem „bis“-Datum automatisch abgeschaltet (täglicher Job `customers:deactivate-expired`, 04:30 Uhr).
- Kunden: optionale Felder „Geburtstag“ und „Homepage“ (ohne Schema wird https:// ergänzt; in der Liste als Link). Zweite Migration `add_birthday_and_website_to_customers`.
- Kunden-Export als CSV (Kunden → Exportieren): aktuelle Listenauswahl oder alle Kunden, Felder frei wählbar (Gruppen mit „Alle/Keine“), Trennzeichen, Zeichensatz und Datumsformat wählbar; Einstellungen werden je Benutzer gemerkt, Export wird protokolliert, Schutz vor Formeleinschleusung. Spalten wie in der Importvorlage, so lässt sich eine Datei wieder importieren. Nicht für den Lesezugriff. Dritte Migration `add_customer_export_prefs_to_users`.
- Kundenimport: optionale Spalten Kunde seit / bis / Aktiv / Tags / Geburtstag / Homepage.
- Kundennummern werden automatisch vergeben: Das Formular schlägt die nächste freie Nummer vor. Ist eine eingetragene Nummer schon
  vergeben (auch bei gelöschten Kunden) oder bleibt das Feld leer, bekommt der Kunde die nächste freie Nummer. Der Zählerstand steht
  unter Nummernkreise („Kundennummern“) und startet hinter der höchsten vorhandenen Zahl. Beim Bearbeiten bleibt die Nummer eindeutig.

## 1.1.0 – 2026-10-14
- Lizenz: GPL-2.0-only (Datei `LICENSE`, GPL-Hinweis in allen Quelldateien, Urheber Martin Adirsch). Das Logo ist ausgenommen und urheberrechtlich geschützt.
- Zwei-Faktor-Anmeldung per Schiebeschalter im Profil: Jeder Benutzer entscheidet selbst, ob er sie verwendet (Standard: an;
  Ausschalten mit aktuellem Passwort). `REQUIRE_2FA` in der `.env` ist nur noch der Standard für Benutzer ohne eigene Entscheidung.
  Neue Migration `add_two_factor_preference_to_users`.
- Benutzername: Der Admin kann jedem Benutzer einen Benutzernamen vergeben (Einstellungen → Benutzer). Die Anmeldung geht mit
  Benutzername **oder** E-Mail-Adresse. Der erste Admin heißt „Admin“. Benutzer ändern ihre E-Mail-Adresse selbst im Profil
  (mit aktuellem Passwort). `user:reset-password` und `user:reset-2fa` akzeptieren auch den Benutzernamen. Neue Migration
  `add_username_to_users`; bestehende Installationen: der älteste Benutzer wird „Admin“.
- Artikelimport aus CSV (Artikel → Importieren), wahlweise mit Aktualisieren vorhandener Artikel.

## 1.0.0 – 2026-10-14
Erster Stand mit dieser Zählung. Enthält Rechnungen, Gutschriften, Teil-/Schlussrechnungen, E-Rechnung (ZUGFeRD),
Angebote, wiederkehrende Rechnungen, Zahlungen und Bankimport, Mahnwesen, E-Mail-Versand, Backup, Kundenimport,
Mandanten und Rollen, Zwei-Faktor-Anmeldung, „Passwort vergessen?“, IBAN-Prüfung und Filter in den Listen.
