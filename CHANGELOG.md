# Änderungsverlauf

Versionsnummer: Haupt.Neben.Korrektur (steht in `config/app.php` unter `version`).

Update: Dateien ins Verzeichnis kopieren, neu bauen (`docker compose build`) und starten. Änderungen an der Datenbank werden beim Start automatisch eingespielt.

## 1.5.0 – 2026-10-18
- Alle Tabellen sind sortierbar: Ein Klick auf den Spaltenkopf sortiert die ganze Liste (nicht nur die sichtbare Seite) aufsteigend, ein weiterer Klick absteigend im Wechsel. Ein Pfeil zeigt die aktive Spalte.
  Gilt für Rechnungen, Angebote, Wiederkehrend, Kunden, Artikel, Offene Posten, Mahnwesen (Vorschläge, Mahnungen, Sperren), Bank (je Reiter), Protokoll, Benutzer, Mandanten, Mail-Zugänge, Nummernkreise, Backups und Layout-Versionen.
- Die gewählte Sortierung wird je Benutzer und Liste gemerkt und beim nächsten Öffnen wieder verwendet; Suche und Filter bleiben beim Sortieren erhalten, die Seitenzahl beginnt wieder bei 1.
  Ohne Klick gilt die bisherige Reihenfolge. Neue Datenbankspalte `users.list_sorts` (wird beim Start automatisch angelegt).
- Kunden: Neuer Filter „Ohne Tag“ in der Kundenliste; die Zeile „ohne Tag“ in der Dashboard-Kundenkarte ist jetzt ein Link darauf (aktive Kunden ohne Tag).
- Kundenliste kompakter: Spalten „Kunden-Nr.“ (schmal, einzeilig), „Firma/Name“ und „E-Mail“ (kürzer, darf umbrechen); „Bearbeiten“ und „Löschen“ stehen übereinander; neue sortierbare Spalte „Kunde bis“ vor „Status“ (das Datum steht nicht mehr unter dem Status).
- Aktionen in Tabellenzeilen als Icon-Buttons (Stift = Bearbeiten, Papierkorb = Löschen, Pfeil = Herunterladen) mit Hinweistext: Kunden, Mandanten, E-Mail-Zugänge, Backups und Artikelpreise. Die Rückfrage vor dem Löschen bleibt.
- Rechnungen und Angebote: gleicher aufklappbarer Zeitraum-Filter für das Datum (Dieser/Letzter Monat, Dieses/Letztes Quartal, Dieses/Letztes Jahr, letzte 30 Tage oder eigener Zeitraum).
- Angebote: zusätzlicher Zeitraum-Filter „Gültig bis“; Offene Posten: Zeitraum-Filter „Fällig am“ (Heute, Diese Woche, Nächste 7/30 Tage, Monat, Quartal oder eigener Zeitraum).
- Protokoll: Export als CSV (für Excel, Semikolon, UTF-8) oder Textdatei, es gelten die gesetzten Filter; „Protokoll löschen“ leert das gesamte Protokoll des Mandanten nach Rückfrage (es bleibt kein Eintrag zurück, daher vorher exportieren).
- Kunden: Filter „Kunde bis“ als Zeitraum (von/bis, auch nur eine Grenze) mit Schnellwahlen (Jahr, Quartal, nächste 30 Tage, bereits abgelaufen, ohne Enddatum) in einem aufklappbaren Knopf „Kunde bis“; der Filter gilt auch für den Kunden-Export.
- Knopfleisten über den Listen stehen rechtsbündig, auch wenn sie in eine zweite Zeile umbrechen. Die Aktionsknöpfe (Kunden: Exportieren/Importieren/Neuer Kunde, Artikel: Importieren/Neuer Artikel, Rechnungen: Neue Rechnung/Neue Gutschrift, Angebote: Neues Angebot, Wiederkehrend: Fällige Entwürfe erzeugen/Neue wiederkehrende Rechnung, Protokoll: Export/Löschen; Offene Posten/Bank/Mahnwesen: die drei Umschaltknöpfe) stehen rechtsbündig auf Höhe der Seitenüberschrift und sparen so eine Zeile. Die Tabelle „Wiederkehrend“ ist schmaler und passt jetzt auch auf das iPad.
- Nicht sortierbar bleiben Aktionsspalten sowie Positionstabellen in Belegen und Formularen.
- Docker: Beim Start des Containers werden die zwischengespeicherten Seiten (`storage/framework/views`) geleert. Dadurch erscheinen geänderte Seiten nach einem Update sofort und nicht erst, wenn der alte Zwischenspeicher zufällig älter ist.
- Docker: Beim Bauen des Images werden die Dateirechte vereinheitlicht (Lesen für alle). Dateien, die auf dem NAS mit eingeschränkten Rechten entpackt wurden, sind damit für den Webserver lesbar; neue oder geänderte Seiten und Stile erscheinen dadurch zuverlässig nach dem Update.

## 1.4.0 – 2026-10-17
- Mandanten: Der Satz „Zahlbar bis … ohne Abzug.“ im Zahlungsblock lässt sich je Mandant ausblenden (Mandanten → Rechnungseinstellungen, Standard: an). Fälligkeitsdatum, Bankdaten und GiroCode bleiben; die Einstellung gilt für alle Layouts des Mandanten.
- Mahnwesen je Mandant abschaltbar (Einstellungen → Mahnwesen, Standard: an): Ohne Haken gibt es keine Mahnvorschläge, keine neuen Mahnungen, keine Mahnsperren und keine Dashboard-Kachel; bereits erstellte Mahnungen und Sperren bleiben sichtbar (nur lesen, PDF abrufbar).
- Nummernkreise: „Angebot“ wird bei englischer Oberfläche jetzt mit „Quote“ übersetzt (die PDFs bleiben deutsch).
- Mandanten: Kundennummer und Leistungsdatum im Belegkopf (PDF) sind je Mandant ausblendbar (Mandanten → Rechnungseinstellungen, Standard: an). Die Kundennummer entfällt auch auf Angeboten und Mahnungen;
  die E-Rechnung (ZUGFeRD) bleibt unverändert. Eigene Layouts: `customer.number` ist dann leer, `doc.service_period` und `labels.service_date` ebenfalls (im Standard-Layout verschwindet die Zeile).

## 1.3.0 – 2026-10-16
- Mandant ohne Umsatzsteuer (z. B. Verein, Kleinunternehmer): Unter Mandanten gibt es den Schalter „Dieser Mandant weist keine Umsatzsteuer aus“ mit einem
  Hinweistext (Vorschläge: § 19 UStG, § 4 UStG, nicht steuerbar; frei änderbar). Neue Rechnungen, Angebote und wiederkehrende Rechnungen
  dieses Mandanten haben dann den Steuerfall „Ohne Umsatzsteuer“ (Steuersatz 0 %, nicht änderbar). Der Hinweis steht unter den Summen im PDF und in der Belegansicht;
  die E-Rechnung (ZUGFeRD) meldet die Kategorie E mit dem Hinweis als Befreiungsgrund und verlangt keine USt-IdNr. (die Steuernummer genügt).
  Der Text wird beim Beleg festgehalten: freigegebene Belege ändern sich nicht, Gutschriften folgen der Rechnung.

## 1.2.0 – 2026-10-15
- Kunden: neue Felder „Kunde seit“ (Vorbelegung heute, änderbar), „Kunde bis“, Häkchen „Aktiv“ und Tags (30 Zeichen). Liste mit Status-Spalte,
  Filtern für Status und Tag und Suche in Tags.
- Sperre: Inaktive Kunden und Kunden nach ihrem „bis“-Datum bekommen keine neuen Rechnungen (Anlegen und Freigeben, auch wiederkehrend
  und aus Angeboten); Gutschriften und Angebote bleiben möglich. Gesperrte Kunden erscheinen nicht in der Kundenauswahl.
- Dashboard: neue Kundenkarte mit Gesamt / Aktiv / Inaktiv (mit Prozent) und den aktiven Kunden je Tag als Balken (die 8 häufigsten Tags, klickbar in die gefilterte Kundenliste).
- „Aktiv“ wird am Tag nach dem „bis“-Datum automatisch abgeschaltet (täglicher Job `customers:deactivate-expired`, 04:30 Uhr).
- Kunden: optionale Felder „Geburtstag“ und „Homepage“ (ohne Schema wird https:// ergänzt; in der Liste als Link).
- Kunden-Export als CSV (Kunden → Exportieren): aktuelle Listenauswahl oder alle Kunden, Felder frei wählbar (Gruppen mit „Alle/Keine“), Trennzeichen, Zeichensatz und Datumsformat wählbar; Einstellungen werden je Benutzer gemerkt, Export wird protokolliert, Schutz vor Formeleinschleusung. Spalten wie in der Importvorlage, so lässt sich eine Datei wieder importieren. Nicht für den Lesezugriff.
- Kundenimport: optionale Spalten Kunde seit / bis / Aktiv / Tags / Geburtstag / Homepage.
- Kundennummern werden automatisch vergeben: Das Formular schlägt die nächste freie Nummer vor. Ist eine eingetragene Nummer schon
  vergeben (auch bei gelöschten Kunden) oder bleibt das Feld leer, bekommt der Kunde die nächste freie Nummer. Der Zählerstand steht
  unter Nummernkreise („Kundennummern“) und startet hinter der höchsten vorhandenen Zahl. Beim Bearbeiten bleibt die Nummer eindeutig.

## 1.1.0 – 2026-10-14
- Lizenz: GPL-2.0-only (Datei `LICENSE`, GPL-Hinweis in allen Quelldateien, Urheber Martin Adirsch). Das Logo ist ausgenommen und urheberrechtlich geschützt.
- Zwei-Faktor-Anmeldung per Schiebeschalter im Profil: Jeder Benutzer entscheidet selbst, ob er sie verwendet (Standard: an;
  Ausschalten mit aktuellem Passwort). `REQUIRE_2FA` in der `.env` ist nur noch der Standard für Benutzer ohne eigene Entscheidung.
- Benutzername: Der Admin kann jedem Benutzer einen Benutzernamen vergeben (Einstellungen → Benutzer). Die Anmeldung geht mit
  Benutzername **oder** E-Mail-Adresse. Der erste Admin heißt „Admin“. Benutzer ändern ihre E-Mail-Adresse selbst im Profil
  (mit aktuellem Passwort). `user:reset-password` und `user:reset-2fa` akzeptieren auch den Benutzernamen.
  Bestehende Installationen: der älteste Benutzer wird „Admin“.
- Artikelimport aus CSV (Artikel → Importieren), wahlweise mit Aktualisieren vorhandener Artikel.

## 1.0.0 – 2026-10-14
Erster Stand mit dieser Zählung. Enthält Rechnungen, Gutschriften, Teil-/Schlussrechnungen, E-Rechnung (ZUGFeRD),
Angebote, wiederkehrende Rechnungen, Zahlungen und Bankimport, Mahnwesen, E-Mail-Versand, Backup, Kundenimport,
Mandanten und Rollen, Zwei-Faktor-Anmeldung, „Passwort vergessen?“, IBAN-Prüfung und Filter in den Listen.
