# RE-FiX – Rechnungserstellung mit ZUGFeRD 

Selbst gehostete, **mandantenfähige Web-Anwendung für Rechnungen** mit **ZUGFeRD/Factur-X-E-Rechnung**. Läuft als Docker-Stack (Laravel 12, PHP 8.3, MariaDB) und ist für kleine Unternehmen und Selbstständige gedacht, die ihre Rechnungsdaten auf dem eigenen Server 
(z. B. NAS) behalten wollen. Für **OpenMediaVault** gibt es eine fertige Compose-Konfiguration (`docker-compose.yml` und `omv.env.example`), da ich diese NAS-Software selbst nutze.

Version 1.2.0 · Lizenz GPL-2.0 · Autor Martin Adirsch

## Inhalt

- [Funktionen](#funktionen)
- [Installation](#installation)
- [Bedienung und Funktionen im Detail](#bedienung-und-funktionen-im-detail)
- [Betrieb: Sicherung, Wiederherstellung, Update](#betrieb-sicherung-wiederherstellung-update)
- [Technik und Entwicklung](#technik-und-entwicklung)
- [Lizenz](#lizenz) · [Spenden](#spenden)

## Funktionen

- **Rechnungen und Gutschriften** mit Entwurf → Freigabe, lückenloser Nummerierung, PDF (DIN 5008) und GiroCode; Teil- und Schlussrechnungen
- **E-Rechnung:** ZUGFeRD / Factur-X (EN 16931) als PDF/A-3 mit eingebetteter XML
- **Angebote** mit Umwandlung in Rechnungen und **wiederkehrende Rechnungen** (täglich automatisch als Entwurf)
- **Zahlungen und offene Posten**, Bankimport (CAMT, CSV) mit Zuordnungsvorschlägen, **Mahnwesen** mit Gebühren, Zinsen und Mahnsperre
- **E-Mail-Versand** der Belege per SMTP, mit Nachweis
- **Kunden und Artikel** mit Preishistorie, Sonderpreisen, Fremdwährungen, CSV-Import und Filtern
- **Layouts** für Rechnung, Angebot und Mahnung: Gestaltung per KI-Prompt, versioniert und mit Vorschau
- **Mehrere Mandanten**, vier Rollen, Benutzername oder E-Mail zur Anmeldung, **Zwei-Faktor-Anmeldung** (Wahl jedes Benutzers), Protokoll, Backup in der App
- Deutsch und Englisch, bedienbar auch auf dem Tablet

## Installation

### Voraussetzungen

- Docker und Docker Compose auf dem Server
- Nginx Proxy Manager (optional, für HTTPS und den Zugriff von außen)

### Schnellstart mit Docker Compose

```sh
# 1. Archiv entpacken und in das Verzeichnis wechseln
cd rechnungsapp

# 2. .env mit zufälligen Schlüsseln und Datenbank-Passwörtern erzeugen
./setup.sh

# 3. Adresse und Port prüfen (APP_URL, APP_PORT in der .env), dann starten
docker compose up -d --build
```

Beim ersten Start lädt Docker die Pakete und führt die Datenbank-Migrationen aus. Das dauert einige Minuten.
Danach ist die App erreichbar unter `http://192.168.1.10:8383` (bzw. deiner Server-IP und dem Port aus `APP_PORT`).

**Wichtig:** Sichere die Datei `.env`. Ohne den `APP_KEY` sind die 2FA-Schlüssel nicht mehr lesbar.

### Installation mit OpenMediaVault

Hier brauchst du weder `setup.sh` noch eine `.env`-Datei. Alle Werte stehen in der Compose-Konfiguration.

1. **Projekt auf das NAS legen:** ZIP entpacken, z. B. nach `/srv/dev-disk-by-uuid-…/appdata/rechnungsapp`
   (dort muss das `Dockerfile` liegen). Den Pfad findest du in OMV unter *Speicher > Freigegebene Ordner*.
2. **Compose-Datei anlegen:** *Dienste > Compose > Dateien > Erstellen*. Name z. B. `rechnung`.
   Inhalt der Datei `docker-compose.yml` aus dem Archiv einfügen.
3. **Umgebung eintragen:** Im Feld *Umgebung* den Inhalt von `omv.env.example` einfügen und anpassen:
   - `APP_SOURCE` auf den Pfad aus Schritt 1 setzen
   - `DB_PASSWORD` und `DB_ROOT_PASSWORD` mit langen Zufallspasswörtern füllen
   - `APP_URL` und `APP_PORT` prüfen
4. **Speichern und starten:** Datei markieren, *Hochfahren* (Up). Beim ersten Start wird das Image gebaut und die
   Datenbank eingerichtet. Das dauert einige Minuten. Den Fortschritt siehst du unter *Dienste > Compose > Dateien > Logs*.
5. Browser öffnen, Einrichtungsseite ausfüllen (siehe unten).

Hinweise:
- **APP_KEY** musst du nicht setzen. Die App erzeugt ihn beim ersten Start und legt ihn im Storage ab
  (`storage/app/.app_key`). Er gehört zur Sicherung dazu. Ohne ihn sind die 2FA-Schlüssel nicht mehr lesbar.
- **Daten auf dem NAS:** Mit `DB_DATA` und `STORAGE_DATA` legst du Ordner auf dem NAS fest. Dann liegen Datenbank und
  Dateien dort sichtbar und lassen sich mit deiner OMV-Sicherung erfassen. Ohne die Variablen nutzt Docker eigene Volumes.
- **Befehle auf dem Server** (Passwort zurücksetzen usw.) führst du per SSH im Ordner der Compose-Datei aus. OMV legt sie
  unter dem von dir gewählten Compose-Verzeichnis ab. Alternativ geht es im Docker-Container-Terminal:
  `php artisan user:reset-password admin@example.com` (im Container `app`, als Benutzer `www-data`).
- **Update:** neues Archiv über den Projektordner entpacken, in OMV die Datei erneut *Hochfahren* mit der Option zum Neu-Bauen (*Build*) bzw. per SSH `docker compose up -d --build`.

### Erster Start

1. Rufe die Adresse im Browser auf. Du landest auf der **Einrichtungsseite**.
2. Lege Firma, deinen Namen, E-Mail und Passwort (mindestens 12 Zeichen) an. Danach ist die Seite gesperrt. Dein Benutzername ist **Admin**; du meldest dich künftig mit „Admin“ oder deiner E-Mail-Adresse an.
3. Du wirst direkt zur **Zwei-Faktor-Einrichtung** geleitet: QR-Code mit einer Authenticator-App scannen,
   Code eingeben, **Wiederherstellungscodes sichern** (ausdrucken).
4. Unter *Mandanten* trägst du Firmendaten, Bankverbindung und Registerangaben ein. Sie werden für das Rechnungslayout gebraucht.

Alternativ kann der Admin vor dem ersten Start über `ADMIN_EMAIL`, `ADMIN_NAME`, `ADMIN_PASSWORD` und `ADMIN_COMPANY`
in der `.env` angelegt werden. Das Passwort muss dann beim ersten Login geändert werden.

### HTTPS über den Nginx Proxy Manager

Neuer Proxy-Host:

| Feld | Wert |
| --- | --- |
| Domain | `rechnung.example.com` |
| Scheme / Forward Host / Port | `http` / `192.168.1.10` / `8383` |
| SSL | Let's-Encrypt-Zertifikat, „Force SSL“ an |

Danach in der `.env` anpassen und neu starten:

```
APP_URL=https://rechnung.example.com
SESSION_SECURE_COOKIE=true      # nur, wenn die App ausschließlich per HTTPS genutzt wird
REQUIRE_2FA=true                # Standard für Benutzer, die nie entschieden haben; jeder ändert es selbst im Profil
```

```sh
docker compose up -d
```

Domain oder Port später zu ändern erfordert nur die `.env` (`APP_URL`, `APP_PORT`) und diesen Befehl.

## Bedienung und Funktionen im Detail

### Rechnungen und Gutschriften

- **Entwurf → Freigabe:** Rechnungen und Gutschriften werden als Entwurf angelegt. Erst die **Freigabe** vergibt die lückenlose Nummer, friert Beträge und Kundendaten ein und archiviert das PDF. Freigegebene Belege sind unveränderlich; Korrekturen laufen über eine **Gutschrift** (Button an der freigegebenen Rechnung).
  Nur die Darstellung lässt sich korrigieren: **„PDF neu erzeugen“** (Buchhaltung/Admin) ersetzt das Archiv-PDF durch eine Fassung mit dem aktuellen Layout und Logo; Beträge und Daten bleiben unverändert, die bisherige Datei liegt als Kopie unter
  `storage/app/private/invoices/<Mandant>/replaced/`, die Aktion steht im Protokoll.
- **Übersicht (Startseite):** Kacheln für **offene Posten**, davon **überfällig**, **Umsatz** (netto) im Monat und im Jahr,  **Zahlungseingang** im Monat, **Mahnvorschläge** (nur mit Zahlungsrechten) und Entwürfe. Dazu ein Balkendiagramm des  Nettoumsatzes der letzten 12 Monate und die fünf am längsten überfälligen Rechnungen. Umsatz = freigegebene Rechnungen
  abzüglich Gutschriften nach Belegdatum, ohne Testbelege; je Währung getrennt, ohne Umrechnung. (Ich weiß, das geht besser - werde darauf in den nächsten Updates zurückkommen ;-)
- **Teil- und Schlussrechnungen:** In der Rechnungsmaske wählst du die **Abrechnungsart** (Rechnung / Teilrechnung / Schlussrechnung). Jede Rechnung enthält nur die Positionen, die in diesem Schritt abgerechnet werden; es wird nichts abgezogen. Eine Teilrechnung ohne Auswahl eröffnet eine **Rechnungsserie**; weitere Teilrechnungen und die Schlussrechnung hängst du über „Zu vorheriger Teilrechnung“ an (oder per Button „Weitere Teilrechnung“ bzw.
  „Schlussrechnung erstellen“ an der freigegebenen Teilrechnung, das übernimmt Kunde, Währung und Konditionen).  Die Rechnungen laufen in der **normalen Nummernfolge**, tragen aber den Titel „1. Teilrechnung“, „2. Teilrechnung“ …  bzw. „Schlussrechnung“. Auf dem PDF steht unter den Summen eine Übersicht „Abgerechnet (Rechnungsserie)“ mit allen bisherigen Rechnungen (um freigegebene Gutschriften gemindert) und der Gesamtsumme. Kunde und Währung müssen  zusammenpassen; eine Schlussrechnung braucht eine Serie und schließt sie ab. Zahlungen, Mahnwesen und Gutschriften  funktionieren je Rechnung wie gewohnt. Die E-Rechnung (ZUGFeRD) behält den Typ 380 (Handelsrechnung).
- **Rechte:** Sachbearbeiter, Buchhaltung und Admin schreiben Entwürfe; **freigeben** dürfen nur Buchhaltung und Admin.
- **Positionen:** aus Artikeln (Preis zum Rechnungsdatum, Sonderpreis des Kunden und Währung werden **kopiert**) oder frei.  Positionsrabatt, Gesamtrabatt, mehrere Steuersätze, Skonto (2 Stufen), Reverse Charge / steuerfrei / Ausfuhr.
- **Nummernkreise:** Format je Mandant unter *Mandanten → bearbeiten* (z. B. `R-{YYYY}-{NNNN}`). Unter *Nummernkreise* trägst du **die zuletzt vergebene Nummer** aus dem Altprogramm ein, danach läuft die Nummerierung lückenlos weiter. **Testbelege** (Testmodus) haben das Präfix `TEST-` und einen eigenen Zähler.
- **PDF:** DIN-5008-Layout mit Logo, Fußzeile, Zahlungsbedingungen und **GiroCode** (nur EUR). Belege sind immer deutsch. Logo, Standard-Einleitung und -Schlusstext pflegst du am Mandanten.

### E-Rechnung und Layouts

- **ZUGFeRD / Factur-X (Profil EN 16931):** Jeder **freigegebene echte Beleg** (Rechnung und Gutschrift) wird als  **PDF/A-3** mit eingebetteter XML `factur-x.xml` archiviert. Entwürfe und Testbelege erhalten keine XML.
  Die XML wird gegen das offizielle XML-Schema und die Rechenregeln der EN 16931 geprüft. Fehlt eine Pflichtangabe  (z. B. USt-IdNr. oder Steuernummer des Mandanten, Anschrift des Kunden, USt-IdNr. des Kunden bei Reverse Charge),  wird **nicht freigegeben** und die Nummer bleibt frei; die Meldung nennt das fehlende Feld.  Die E-Mail-Adresse für die XML wird aus dem Freitext-Feld des Mandanten herausgelöst („Email: x@y.de“ → „x@y.de“).
  Ich habe meine Rechnungen bei Elster geprüft. ZUGFeRD hat funktioniert. 
- **Layouts** (*Einstellungen → Layouts*, nur Admin): Standardmäßig ist ein Layout hinterlegt. Du kannst dazu auch ein Firmen-Logo einspielen. Wenn du Änderungswünsche hast, trägst du deine Gestaltungswünsche ein, kopierst den fertig  zusammengesetzten **Prompt** in eine KI deiner Wahl und fügst die Antwort wieder ein. Die App prüft die Vorlage
  (keine Skripte, keine externen Bilder oder Schriften, nur bekannte Platzhalter, Pflichtplatzhalter vorhanden),  erzeugt probeweise ein PDF und legt sie als **neue, unveränderliche Version** ab. Jede Version hat eine **Vorschau**  mit Musterbeleg, kann **aktiviert**, als Vorlage **kopiert** oder (wenn nicht aktiv) gelöscht werden. Bereits
  freigegebene PDFs ändern sich nie. *Standard-Layout verwenden* schaltet zurück auf das eingebaute Layout.
- **Mahn-Layout:** Die Seite *Layouts* hat zwei Reiter: **Rechnung / Gutschrift** und **Zahlungserinnerung / Mahnung**. Jeder Reiter hat eigene Wünsche, einen eigenen Prompt (mit den Platzhaltern des Mahnschreibens, z. B. `{{doc.text}}`, `{{invoice.number}}`, `{{dunning.total}}`), eigene Versionen und ein eigenes aktives Layout. Das Layout gilt für
  **neu erstellte** Mahnungen; bereits erzeugte Schreiben bleiben unverändert. Ohne aktives Mahn-Layout wird das eingebaute  verwendet. *Standard-Layout als Vorlage laden* liefert die Mahn-Vorlage als Ausgangspunkt. Der Brieftext selbst stammt weiter aus *Einstellungen → Mahnwesen*.

### Angebote

Angebote sind ein eigener Belegtyp mit eigener Liste (Menü „Angebote“), eigenem Nummernkreis (Standard `A-{YYYY}-{NNNN}`, unter Einstellungen → Mandanten änderbar), eigenem Layout (Einstellungen → Layouts, dritter Reiter) und eigenen Mailtexten.

- Status: Entwurf, Offen, Angenommen, Abgelehnt; „Abgelaufen“ ergibt sich aus „Gültig bis“. Ohne Angabe gilt die beim Mandanten
  eingestellte Frist (Standard 30 Tage).
- Angebote sind keine GoBD-Belege: Offene Angebote lassen sich ändern, das PDF wird bei jedem Aufruf frisch erzeugt.
- **In Rechnung umwandeln**: erzeugt einen Rechnungsentwurf mit Kunde und Positionen; das Angebot gilt dann als angenommen.
  Auch teilweise (Positionen anhaken) und mehrfach möglich. Löschst du den Entwurf, sind die Positionen wieder frei.
  Sobald Positionen übernommen wurden, ist das Angebot nicht mehr änderbar.
- Versand per E-Mail mit PDF-Anhang (Recht „E-Mail senden“), Verlauf am Angebot.

### Wiederkehrende Rechnungen

Unter „Wiederkehrend“ legst du Vorlagen an (Kunde, Positionen, Texte, Rhythmus monatlich / quartalsweise / jährlich, Abrechnung im Voraus oder nachträglich, nächster Stichtag, optionales Enddatum). Zum Stichtag entsteht automatisch ein **Rechnungsentwurf**, freigegeben wird immer von Hand.

- **Automatik:** Der Worker-Container führt täglich um 05:00 Uhr `recurring:generate` aus. Versäumte Termine werden nachgeholt,
  jeweils mit ihrem regulären Stichtag als Rechnungsdatum und ihrem eigenen Zeitraum. Mit „Fällige Entwürfe jetzt erzeugen“
  (oder `docker exec -u www-data rechnungsapp-app-1 php artisan recurring:generate`) geht es sofort.
- **Zeitraum:** Im Voraus = ab dem Stichtag (Stichtag 01.11. monatlich → 01.11.–30.11.). Nachträglich = der Zeitraum, der am Vortag
  endet (Stichtag 01.12. → 01.11.–30.11.). Der Zeitraum steht im Leistungszeitraum der Rechnung.
- **Platzhalter** in Positions- und Rechnungstexten: `{zeitraum}`, `{zeitraum_von}`, `{zeitraum_bis}`, `{monat}`, `{quartal}`, `{jahr}`
  (bezogen auf den Beginn des Zeitraums).
- **Preise:** Stammartikel nehmen den Preis aus der Preistabelle zum Rechnungsdatum (Preiserhöhungen greifen so automatisch),
  freie Positionen behalten ihren Preis. Mengen bleiben unverändert.
- **Kündigung:** Enddatum eintragen; danach entstehen keine Entwürfe mehr. Pausieren geht über „Aktiv“.
- **Aus einer Rechnung:** In jeder Rechnung (Entwurf oder freigegeben) gibt es „Als wiederkehrende Rechnung speichern“.
  Die Vorlage startet pausiert, damit du Rhythmus und Texte (Monat durch `{monat}` ersetzen) prüfen kannst.
- Ein gelöschter Entwurf wird nicht neu erzeugt; der Stichtag gilt als abgearbeitet.

### Zahlungen, offene Posten und Bankimport

- **Zahlung buchen** (an der Rechnung, Karte „Zahlungen“): Betrag, Zahlungsdatum, Referenz. **Teilzahlungen** und Überzahlungen  sind möglich; der Status der Rechnung (*Offen, Teilweise bezahlt, Bezahlt, Überzahlt, Gutgeschrieben*) steht in der Rechnungsliste und an der Rechnung. Freigegebene **Gutschriften** zur Rechnung mindern den offenen Betrag automatisch.
- **Skonto:** Hat die Rechnung Skonto, gibt es das Häkchen **Skonto gezogen**. Der Betrag muss genau dem Skontobetrag entsprechen
  (so wie auf dem Beleg ausgewiesen); die Differenz wird als **Skontoabzug** mitgebucht, die Rechnung gilt als bezahlt. Nur möglich,
  solange noch nichts anderes gezahlt wurde. Die Skontofrist wird nicht geprüft, die Entscheidung liegt bei dir.
- **Stornieren statt löschen:** Gebuchte Zahlungen werden nie gelöscht. Du stornierst sie mit Begründung; sie bleiben sichtbar
  (durchgestrichen) und im Protokoll. Testbelege (Testmodus) können nicht bezahlt werden.
- **Offene Posten** (Menü *Zahlungen*): alle offenen Rechnungen, älteste Fälligkeit zuerst, mit Summen, Filter „nur überfällige“  und Tagen über Fälligkeit.
- **Bankimport** (*Zahlungen → Bank*): Datei hochladen, das Format wird am Inhalt erkannt:
  **CAMT.052/053/054** (XML) und **CSV**. Bei CSV ordnest du einmalig die Spalten zu (Vorschläge anhand der Überschriften;  Zeilen vor der Kopfzeile werden übersprungen; Betrag mit Vorzeichen *oder* getrennte Spalten Haben/Soll). Bewegungen, die schon  eingelesen wurden, werden übersprungen – du kannst sich überlappende Auszüge einlesen. Ausgänge werden automatisch auf „Ignoriert“  gesetzt, nur Eingänge werden zugeordnet.
- **Zuordnungsvorschläge:** Die App sucht die Rechnungsnummer im Verwendungszweck (Trennzeichen und ein fehlendes Präfix wie „RE“  stören nicht) und vergleicht den Betrag: *Nummer + Betrag* und *Nummer + Skontobetrag* sind vorausgewählt, *Nummer, aber anderer Betrag*  (Teil- oder Mehrzahlung) und *nur Betrag + Kundenname* werden vorgeschlagen, aber nicht angehakt. **Gebucht wird nur, was du anhakst**  und mit „Markierte buchen“ bestätigst. Storniert du eine Zahlung, wird die Bankbewegung wieder offen.
- **Rechte:** Zahlungen und Bank sehen und bedienen **Admin und Buchhaltung**; alle anderen Rollen sehen den Zahlungsstand an der Rechnung.
- **Grenzen:** Die Leser wurden mit selbst erstellten Beispieldateien geprüft. Banken schreiben CAMT/CSV unterschiedlich; falls deine Datei nicht sauber gelesen wird, schick mir eine anonymisierte Probe. Dieselbe Bewegung aus zwei *verschiedenen* Formaten (z. B.  CSV und CAMT) kann nicht als Dublette erkannt werden.

### Mahnwesen

- **Stufen:** Zahlungserinnerung → 1. Mahnung → 2. Mahnung (letzte Mahnung). Alles unter *Zahlungen → Mahnwesen*.
- **Nichts läuft automatisch.** Der Tab **Vorschläge** zeigt Rechnungen, bei denen jetzt die nächste Stufe ansteht (Betrag, Gebühr, Zinsen, Gesamtforderung). Du hakst an und klickst **Markierte Mahnungen erstellen**; dabei entsteht das PDF und wird archiviert.
  Versendet wird im Tab **Mahnungen**: per **E-Mail** (Dialog wie bei Rechnungen, das PDF hängt an) oder du druckst es selbst und  klickst **Als versendet markieren**. Erst „versendet“ zählt: Die nächste Stufe wird erst vorgeschlagen, wenn die Zahlungsfrist  des letzten Schreibens abgelaufen ist. Ein noch nicht versendetes Schreiben kannst du **verwerfen**; versendete bleiben stehen.
- **Einstellungen** (*Einstellungen → Mahnwesen*, nur Admin, je Mandant): Tage nach Fälligkeit bis zur Zahlungserinnerung (Standard 7),  Zahlungsfrist im Schreiben (Standard 7 Tage), **Mahngebühr** je Stufe (Standard 0 €), optional **Verzugszinsen** und die  **Texte** der drei Schreiben (mit Platzhaltern wie `{belegnummer}`, `{gesamt}`, `{frist}`).
- **Verzugszinsen:** ab der 1. Mahnung, wenn aktiviert: offener Betrag × (Basiszinssatz + Aufschlag) × Verzugstage ÷ 365. Den
  **Basiszinssatz** (ändert sich zum 1. Januar und 1. Juli, Bundesbank) trägst du selbst ein; die App kennt ihn nicht. Der Aufschlag ist 9 Prozentpunkte (Geschäftskunden, § 288 BGB), einstellbar. Gebühren und Zinsen stehen im Schreiben und in der Gesamtforderung, werden aber **nicht** als Zahlung auf die Rechnung gebucht. Zahlt der Kunde die Gesamtforderung, erscheint die Rechnung deshalb als „Überzahlt“; das ist die Mahngebühr bzw. der Zins.
- **Mahnsperre:** Bei Streit oder Reklamation sperrst du eine Rechnung (mit Grund, optional bis zu einem Datum). Gesperrte Rechnungen  tauchen nicht in den Vorschlägen auf. Setzen/Aufheben auch an der Rechnung selbst.
- Gemahnt werden nur freigegebene, echte Rechnungen mit Fälligkeitsdatum und offenem Betrag (keine Testbelege, keine Gutschriften). Teilzahlungen werden berücksichtigt: Das Schreiben nennt den dann noch offenen Betrag.
- **Rechte:** Admin und Buchhaltung; E-Mail-Versand zusätzlich nur mit dem Recht „E-Mails versenden“.
- **Hinweis:** Die Standardtexte sind sachliche Vorlagen und keine Rechtsberatung. Prüfe sie, bevor du sie für die letzte Mahnung einsetzt, und passe sie bei Bedarf in den Einstellungen an.

### E-Mail-Versand

- **Zugänge** (*Einstellungen → E-Mail-Zugänge*, nur Admin): SMTP-Server, Port, Verschlüsselung (STARTTLS, SSL/TLS oder keine),  Benutzer und Passwort, Absenderadresse und -name, optionale Antwortadresse und **BCC pro Zugang**. Das Passwort wird mit  dem `APP_KEY` verschlüsselt gespeichert, nie angezeigt und nie ins Protokoll geschrieben. Beim Bearbeiten lässt ein  leeres Passwortfeld das gespeicherte unverändert. Zugänge gelten für alle Mandanten; der Admin sieht und verwaltet alle.  **Verbindung testen** prüft Server und Anmeldung, **Test-E-Mail senden** verschickt eine echte Nachricht; Fehler des  Servers erscheinen im Klartext. Ein Zugang, der noch von einem Mandanten verwendet wird, lässt sich nicht löschen.
  Nur Benutzer/Passwort, kein OAuth (Microsoft 365 / Google). STARTTLS wird genutzt, wenn der Server es anbietet.
- **Pro Mandant** (*Einstellungen → E-Mail*, nur Admin): welcher Zugang genutzt wird, die **Rechte-Matrix** (Admin darf  immer; Buchhaltung und Sachbearbeiter lassen sich einzeln freigeben, Standard: nur Buchhaltung; Lesezugriff nie) und die  **Standardtexte** für Rechnung und Gutschrift (Betreff und Text). Platzhalter: `{anrede}`, `{belegnummer}`, `{betrag}`,
  `{faelligkeit}`, `{datum}`, `{kunde}`, `{firma}`; unbekannte Platzhalter werden beim Speichern abgelehnt. Ein geleertes  Feld stellt den mitgelieferten Text wieder her. Mails sind immer deutsch.
- **Versenden:** Am freigegebenen Beleg erscheint **Per E-Mail senden**. Der Dialog schlägt Empfänger (E-Mail des Kunden),  Betreff und Text vor; alles lässt sich vor dem Absenden ändern (bis zu 5 Empfänger). Angehängt wird genau das archivierte  PDF (mit ZUGFeRD). Der Versand läuft direkt beim Klick, ohne Warteschlange. Entwürfe lassen sich nicht senden.
- **Nachweis:** Jeder Versuch steht am Beleg (wer, wann, an wen, gesendet oder fehlgeschlagen mit Grund) und im Protokoll  (`invoice.mailed` / `invoice.mail_failed`). Fehlgeschlagene Sendungen kannst du einfach wiederholen.

### Preise und Preiserhöhungen

Am Artikel hinterlegst du Preise mit „gültig ab“-Datum. Eine Erhöhung ist ein neuer Preis mit späterem Startdatum. Der alte Preis bleibt erhalten. Sonderpreise pro Kunde und Preise in Fremdwährung sind möglich. Beim Erstellen einer Rechnung wird der gültige Preis in die Position **kopiert**, bestehende Belege ändern sich dadurch nie.

### Privatkunden

Bei Privatpersonen bleibt das Feld „Firma“ leer; Vor- und Nachname genügen. Der Name wird beim Speichern als Anschrift übernommen und steht auf Rechnung, Angebot und Mahnung als erste Zeile. Wer eine Firma einträgt, behält die Kontaktzeile wie bisher. Ohne Firma und ohne Namen lässt sich ein Kunde nicht speichern.

### Filter in den Listen

Kunden, Artikel und Wiederkehrend haben neben der Suche Auswahlfelder zum Filtern (wie bei Rechnungen und Angeboten). Die Filter bleiben beim Blättern erhalten; „Zurücksetzen“ erscheint, sobald ein Filter aktiv ist.

- **Kunden:** Suche zusätzlich in Vor-/Nachname, Straße, PLZ und Tags; Art (Firmen/Privatpersonen), Land, Währung, mit Käuferreferenz/Leitweg-ID, Status (Aktiv/Inaktiv), Tag.
- **Artikel:** Suche auch in der Beschreibung; Status, Steuersatz, Einheit, „ohne gültigen Preis“ (Standardpreis in EUR, wie die Spalte „Preis“).
- **Wiederkehrend:** Suche in Bezeichnung und Kunde; Status (Aktiv/Pausiert/Beendet), Rhythmus, Abrechnung, „mit offenen Entwürfen“.

### Artikelimport

*Artikel → Importieren*: CSV-Datei hochladen (wie beim Kundenimport), Spalten zuordnen, Vorschau prüfen, importieren. Eine Vorlage zum Herunterladen gibt es auf der ersten Seite.

- Felder: Artikelnummer, Bezeichnung*, Beschreibung, Einheit*, Steuersatz (leer = 19), Preis (netto), Währung (leer = EUR),  Gültig ab (leer = heute; `31.01.2026` oder `2026-01-31`), Aktiv (leer = ja). Zahlen wie `1.234,56` werden verstanden.
- Mit einem Preis entsteht ein Preis mit dem „Gültig ab“-Datum der Zeile.
- **Artikelnummer schon vorhanden:** wahlweise überspringen (Voreinstellung) oder aktualisieren. Beim Aktualisieren werden nur die  zugeordneten Felder geändert; ein abweichender Preis kommt als *neuer* Preis dazu, der alte bleibt erhalten. Gibt es für das
  Datum schon einen anderen Preis, bleibt er unverändert (Hinweis in der Vorschau).
- Zeilen ohne Artikelnummer werden immer neu angelegt. Der Import gilt nur für den aktuellen Mandanten.

### Kundennummern

Beim Anlegen schlägt das Formular die nächste freie Nummer vor. Trägst du eine eigene Nummer ein, bleibt sie erhalten – ist sie schon vergeben (auch bei gelöschten Kunden) oder lässt du das Feld leer, vergibt die App die nächste freie Zahl und sagt dir Bescheid. Den Zählerstand siehst und setzt du unter *Einstellungen → Nummernkreise* („Kundennummern“); ohne Eintrag startet er hinter der höchsten vorhandenen Zahl. Beim Bearbeiten muss die Nummer eindeutig bleiben.

### Kundenstatus: Kunde seit / bis, Aktiv, Tags

Optional kannst du je Kunde einen **Geburtstag** und die **Homepage** hinterlegen (Import: Spalten „Geburtstag“, „Homepage“).

Jeder Kunde hat „Kunde seit“ (wird beim Anlegen mit dem heutigen Datum vorbelegt, ist änderbar), optional „Kunde bis“, das Häkchen „Aktiv“ und ein Feld **Tags** (bis 30 Zeichen, mit Leerzeichen getrennt, z. B. `#m #w #xyz`; durchsuchbar und filterbar).

- **Sperre:** Für inaktive Kunden und für Rechnungsdaten **nach** dem „Kunde bis“-Datum lassen sich keine neuen Rechnungen anlegen oder freigeben (auch Teil-/Schlussrechnungen und Umwandlungen aus Angeboten). Am „bis“-Tag selbst ist es noch möglich. **Gutschriften** und **Angebote** bleiben erlaubt, bestehende Belege unverändert.
- **Wiederkehrende Rechnungen:** Für gesperrte Kunden entstehen keine Entwürfe; die Vorlage bleibt unverändert und zeigt „Kunde gesperrt“. Wird der Kunde wieder aktiviert, werden versäumte Termine nachgeholt.
- **Automatik:** Der Worker schaltet täglich um 04:30 Uhr (`customers:deactivate-expired`) „Aktiv“ bei Kunden aus, deren „Kunde bis“-Datum überschritten ist. Beim Speichern eines Kunden gilt dieselbe Regel. Ein Kunde mit vergangenem „bis“-Datum lässt sich nicht aktivieren – erst das Datum ändern oder leeren.
- Bestehende Kunden erhalten bei der Migration „Kunde seit“ = 01.01.2026 und sind aktiv.
- Kundenimport: optionale Spalten „Kunde seit“, „Kunde bis“ (JJJJ-MM-TT oder TT.MM.JJJJ), „Aktiv“ (ja/nein) und „Tags“.

### Kunden exportieren (CSV)

*Kunden → Exportieren* öffnet die Exportseite. Du wählst (1) die Kunden – die **aktuelle Auswahl der Liste** mit Suche und Filtern oder **alle**, optional einschließlich gelöschter –, (2) die **Felder** (in Gruppen Stammdaten / Konditionen / Status, je Gruppe „Alle“ und „Keine“) und (3) das **Format**: Trennzeichen (Semikolon, Komma, Tabulator), Zeichensatz (UTF-8 mit BOM oder Windows-1252) und Datum/Dezimalzahlen (TT.MM.JJJJ mit Komma oder JJJJ-MM-TT mit Punkt). Die Spalten stehen immer in der Reihenfolge der Importvorlage, sodass sich die Datei wieder importieren lässt. Deine letzte Auswahl wird je Benutzer gemerkt. Der Export steht Admin, Buchhaltung und Sachbearbeiter zur Verfügung (nicht dem Lesezugriff) und wird im Protokoll vermerkt. Texte, die mit `=`, `+`, `-` oder `@` beginnen, erhalten ein Hochkomma (Schutz vor Formeln in Tabellenprogrammen); Telefonnummern und Zahlen bleiben unverändert.

### Backup und Kundenimport

- **Backup** (*Einstellungen → Backup*, nur Admin): Der Button **Backup jetzt erstellen** legt eine ZIP-Datei an mit *allen* Daten (alle Mandanten, Benutzer, Belege, Einstellungen, Protokoll) und allen Dateien (archivierte PDFs, Logos). Die Dateien  liegen im Ordner `storage/app/private/backups` (also auf deinem NAS-Speicher), lassen sich herunterladen und löschen.
  Es läuft nichts automatisch. Prüfsummen im Backup erkennen beschädigte Dateien vor dem Zurückspielen.
  **Wichtig:** Der Schlüssel der Anwendung (`APP_KEY`, in der `.env` bzw. `storage/app/.app_key`) steckt bewusst *nicht* im Backup.
  Ohne ihn sind SMTP-Passwörter und Zwei-Faktor-Geheimnisse nicht lesbar. Sichere ihn getrennt. Das Backup enthält sensible  Daten und ist nicht zusätzlich verschlüsselt: bewahre es sicher auf.
- **Zurückspielen** geschieht bewusst nur auf dem Server, weil dabei *alle* aktuellen Daten ersetzt werden:
  1. Backup-Datei in den Ordner `storage/app/private/backups` kopieren (liegt sie dort schon, weil sie auf diesem Server erstellt wurde, entfällt das).
  2. `docker exec -it -u www-data rechnungsapp-app-1 php artisan backup:restore backup-JJJJMMTT-HHMMSS.zip`
  3. Der Befehl prüft die Datei, zeigt Inhalt (Tabellen und Zeilen) und fragt nach (`--force` überspringt die Frage). Danach sind Daten und Dateien so wie zum Zeitpunkt des Backups. Alle müssen sich neu anmelden.
  Der Befehl weigert sich, wenn die Datei beschädigt ist, der `APP_KEY` nicht passt (`--ignore-key` erzwingt es) oder die  Sicherung von einer *neueren* Programmversion stammt. Von einer älteren Version geht es: Tabellen, die es damals noch nicht gab, bleiben leer. Das Anlegen geht auch per Befehl: `php artisan backup:create`.
- **Kundenimport** (*Kunden → Importieren*): CSV-Datei hochladen (Semikolon, Komma oder Tabulator; UTF-8 oder Windows-Zeichensatz werden erkannt; erste Zeile = Überschriften; bis 5 MB / 5000 Zeilen), **Spalten zuordnen** (die App schlägt sie anhand der  Überschriften vor), **Vorschau** mit Zählern und Gründen, dann importieren. Es gelten dieselben Regeln wie im Kundenformular (Pflicht: Firma, Straße, PLZ, Ort, E-Mail). Fehlt das Land, wird DE gesetzt, fehlt die Währung, EUR; fehlt die Kundennummer, wird  die nächste freie vergeben. Bereits vorhandene (auch gelöschte) oder in der Datei doppelte Kundennummern werden *übersprungen*;  bestehende Kunden werden nie verändert. Eine Vorlage zum Herunterladen liegt auf der Importseite. Excel-Dateien vorher als „CSV (Trennzeichen-getrennt)“ speichern.

### Benutzername und Anmeldung

- **Anmelden** kann sich jeder Benutzer mit seinem **Benutzernamen oder seiner E-Mail-Adresse** (Groß-/Kleinschreibung egal) und dem Passwort.
- **Vergeben** wird der Benutzername vom Admin unter *Einstellungen → Benutzer* (Spalte „Benutzername“, beim Anlegen oder nachträglich;
  leer = keiner). Erlaubt sind 3–50 Zeichen: Buchstaben, Ziffern, Punkt, Unterstrich, Bindestrich, kein @; er muss in der ganzen
  Anwendung eindeutig sein. Gehört ein Benutzer mehreren Mandanten an, darf den Namen nur ein Admin ändern, der in all diesen Mandanten Admin ist.
- **Der erste Administrator** (Ersteinrichtung, `user:create-admin`, `ADMIN_EMAIL` in der `.env`) bekommt den Benutzernamen **Admin**.
- **E-Mail-Adresse ändern** kann jeder Benutzer selbst unter *Mein Profil → Konto*; zur Sicherheit mit dem aktuellen Passwort. Offene „Passwort vergessen“-Links der alten Adresse werden ungültig. Den Benutzernamen ändert nur der Admin.
- **„Passwort vergessen?“** läuft über die E-Mail-Adresse. Ist sie nicht mehr erreichbar, setzt der Admin das Passwort zurück  (Benutzer → „Passwort zurücksetzen“) oder auf dem Server per `user:reset-password <E-Mail oder Benutzername>`.

### Zwei-Faktor-Anmeldung

Jeder Benutzer schaltet die Zwei-Faktor-Anmeldung unter *Mein Profil → Zwei-Faktor-Anmeldung* mit einem **Schiebeschalter** selbst ein oder aus.

- **Standard ist an:** Wer sich neu anmeldet und nichts entschieden hat, richtet sie ein (QR-Code, Code bestätigen, Wiederherstellungscodes sichern).
- **Ausschalten:** Schalter auf Aus, aktuelles Passwort eingeben, Speichern. Schlüssel und Wiederherstellungscodes werden gelöscht; danach genügt das Passwort.
- **Wieder einschalten:** Schalter auf Ein, Speichern, dann den QR-Code einrichten.
- Wer sie eingeschaltet hat, gibt bei der Anmeldung weiterhin den Code ein. In der Benutzerliste steht bei Benutzern, die sie ausgeschaltet haben, „Aus“.
- `REQUIRE_2FA` in der `.env` ist nur der **Standard** für Benutzer, die nie entschieden haben (Vorgabe: `true`). Bei Zugriff von außen empfehle ich, sie eingeschaltet zu lassen.

### Rollen

| Rolle | Darf |
| --- | --- |
| Admin | Alles: Benutzer, Mandanten, Nummernkreise, E-Mail-Zugänge und -Einstellungen, Protokoll, Stammdaten, Belege inkl. Freigabe, Versand, Zahlungen |
| Buchhaltung | Stammdaten anlegen/ändern/löschen, Belege schreiben **und freigeben**, Zahlungen buchen, Bankdateien einlesen |
| Sachbearbeiter | Stammdaten und Beleg-Entwürfe anlegen und ändern, nicht löschen, **nicht freigeben** |
| Lesezugriff | Nur ansehen (auch PDFs) |

Das Recht **E-Mails versenden** vergibt der Admin pro Mandant an Buchhaltung und/oder Sachbearbeiter (siehe E-Mail-Versand).
Mahnungen erstellen und versenden dürfen Admin und Buchhaltung (Versand zusätzlich mit dem Recht „E-Mails versenden“); die Mahn-Einstellungen nur der Admin.

### Passwort vergessen und IBAN-Prüfung

- **Passwort vergessen?** Auf der Anmeldeseite wird ein Link per E-Mail verschickt (60 Minuten gültig, einmal verwendbar).
  Voraussetzung: Einem Mandanten des Benutzers ist ein E-Mail-Konto zugeordnet. Ist die Zwei-Faktor-Anmeldung aktiv,  wird zusätzlich der Code (oder ein Wiederherstellungscode) verlangt. Die Antwort ist für unbekannte Adressen identisch.
- **Durch einen Admin:** Unter Benutzer gibt es „Passwort zurücksetzen“. Es wird ein Einmalpasswort angezeigt, das beim nächsten Login geändert werden muss. Nicht möglich für sich selbst und für Benutzer in mehreren Mandanten.
- Die Zwei-Faktor-Anmeldung bleibt nach dem Zurücksetzen erhalten (bei verlorenem Handy: `user:reset-2fa`, siehe oben).
- **Passwörter werden nur als Hash (bcrypt) gespeichert, nie lesbar.**
- **IBAN:** Beim Mandanten wird die IBAN auf Länge und Prüfsumme (Modulo 97) geprüft; Leerzeichen werden entfernt.
- Nach der Einrichtung der Zwei-Faktor-Anmeldung führt „Weiter zur Übersicht“ von der Seite mit den Wiederherstellungscodes weiter.

### Bedienung der Listen

- **Seitenleiste:** Ab 861 px Breite steht die Navigation vertikal links (fest beim Scrollen); Mandantenwahl, Profil und Abmelden liegen unten. Die Einstellungen sind eine aufklappbare Gruppe. Auf schmalen Bildschirmen bleibt die Leiste oben.
- **Auswahl vorn, Aktionen oben:** In Offene Posten, Bank, Mahnwesen und Benutzer wählst du eine Zeile per Radio-Button (die ganze Zeile ist antippbar); die Aktionen stehen als Knöpfe über der Tabelle und gelten für die gewählte Zeile. Nicht erlaubte Aktionen sind ausgegraut.
- **Mehrfachauswahl:** Bank „Neu“ und Mahnwesen „Fällig“ haben Kontrollkästchen (mit „Alle“) und Sammelaktionen oben, z. B. „Markierte buchen (n)“, „Markierte ignorieren“, „Markierte Mahnungen erstellen (n)“, „Markierte sperren…“.
- **Tablet:** Eingabefelder haben mindestens 16 px (kein Auto-Zoom in iPadOS), der Inhaltsbereich scrollt bei zu breiten Tabellen selbst, die Seite wird nie breiter als der Bildschirm.
- Oberfläche in **Deutsch und Englisch** (umschaltbar im Profil); Belege und E-Mails sind immer deutsch.

## Betrieb: Sicherung, Wiederherstellung, Update

### Sicherung von außen (zusätzlich zum Backup in der App)

```sh
# Datenbank
docker compose exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > sicherung.sql

# Dateien (PDFs, Logos)
docker run --rm -v rechnungsapp_app_storage:/data -v "$PWD":/backup alpine tar czf /backup/storage.tgz -C /data .
```

Der Volume-Name kann je nach Verzeichnisname abweichen (`docker volume ls`). Das Backup aus der App reicht für den Alltag; wer zusätzlich eine Sicherung des ganzen Datenbank-Servers möchte, nutzt diese Befehle.

### Zugang wiederherstellen (nur auf dem Server)

Wer Zugriff auf den Docker-Host hat, kann Zugänge zurücksetzen. Über die Weboberfläche ist das nicht möglich.
Die Befehle laufen als Benutzer `www-data`, damit keine Dateien mit falschen Rechten entstehen:

```sh
# Passwort zurücksetzen (fragt das neue Passwort verdeckt ab)
docker compose exec -u www-data app php artisan user:reset-password admin@example.com

# ... oder ein Zufallspasswort erzeugen, das einmalig angezeigt wird
docker compose exec -u www-data app php artisan user:reset-password admin@example.com --random

# Zwei-Faktor-Anmeldung abschalten (Einrichtung wird beim nächsten Login neu verlangt)
docker compose exec -u www-data app php artisan user:reset-2fa admin@example.com

# Neuen Admin anlegen, falls alle Admins gesperrt oder gelöscht sind
docker compose exec -u www-data app php artisan user:create-admin
```

Nach dem Zurücksetzen muss das Passwort beim nächsten Login geändert werden, alle offenen Sitzungen werden beendet. Jeder dieser Vorgänge steht im Protokoll.

### Update

```sh
# neue Version über das Verzeichnis entpacken (die .env bleibt erhalten), dann:
docker compose up -d --build
```

Die Datenbank-Migrationen laufen beim Start automatisch. Vorher bitte eine Sicherung ziehen.

## Technik und Entwicklung

### Technik

- Laravel 12, PHP 8.3, MariaDB 11.4 (eigener Container, nicht von außen erreichbar)
- Container: `app` (Apache/PHP), `worker` (Warteschlange und Zeitplaner), `db`
- Mandantentrennung: jede Tabelle mit Fachdaten trägt eine `tenant_id`. Ein globaler Filter liefert ohne aktiven Mandanten keine Daten zurück.
- Oberfläche: Deutsch und Englisch über Sprachdateien in `lang/`

### Tests

```sh
docker compose exec -u www-data app php artisan test
```

Die Tests laufen ausschließlich gegen eine temporäre SQLite-Datenbank im Arbeitsspeicher. Eine Sperre bricht ab, falls sie versehentlich gegen die echte Datenbank laufen würden. Deine Daten sind nicht betroffen.

### Marke und Logo

Logo (`public/img/refix-logo.png`, weiß, transparent) und Name „RE-FiX“ stehen in der Seitenleiste, auf der Anmeldeseite und als Favicon. Der Anzeigename kommt aus `config('app.brand')`; `APP_NAME` (Seitentitel, Mailabsender, 2FA-App) hat den Standard „RE-FiX“. Das Logo ist urheberrechtlich geschützt und nicht Teil der GPL, siehe [Lizenz](#lizenz).

## Lizenz

Copyright (C) 2026 **Martin Adirsch** (Autor und Urheber).

Der Quelltext steht unter der **GNU General Public License, Version 2** (nur Version 2, SPDX: `GPL-2.0-only`), siehe Datei [LICENSE](LICENSE).

Du darfst ihn nutzen, ändern und weitergeben; Weitergaben, auch geänderte, müssen ebenfalls unter der GPL-2.0 stehen und den Quellcode enthalten. Es gibt keine Gewährleistung. Jede Quelldatei trägt den entsprechenden Hinweis im Kopf. Die Bibliothek mPDF, die für die
PDF-Erzeugung dient, steht ebenfalls unter GPL-2.0; die übrigen Abhängigkeiten (Laravel u. a.) haben eigene, mit der GPL-2.0 verträgliche Lizenzen.

**Ausnahme Logo:** Die Datei `public/img/refix-logo.png` (RE-FiX-Logo) ist **nicht** Teil der GPL-Lizenz, sondern urheberrechtlich geschütztes Eigentum von Martin Adirsch, alle Rechte vorbehalten (siehe `public/img/LOGO-LICENSE.txt`). Wer die Software ändert oder weitergibt,
ersetzt das Logo durch ein eigenes (PNG, weiß auf transparentem Grund, gleicher Dateiname).

## Spenden

Dieses Projekt ist in meiner Freizeit entstanden. Wenn es dir gefällt und du es unterstützen möchtest, freue ich mich über eine freiwillige Spende per **PayPal** an [martin.adirsch@gmx.de](mailto:martin.adirsch@gmx.de). Vielen Dank!
