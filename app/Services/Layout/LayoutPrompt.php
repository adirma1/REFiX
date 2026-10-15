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

namespace App\Services\Layout;

use App\Models\LayoutVersion;

/** Baut die Anweisung (Prompt), die der Anwender an eine externe KI gibt, damit diese ein Layout liefert. */
class LayoutPrompt
{
    public const DEFAULT_WISHES = 'Keine besonderen Wünsche: ein sauberes, sachliches und gut lesbares Layout nach DIN 5008 mit dezenten Linien.';

    public static function build(?string $wishes, string $company, string $kind = LayoutVersion::KIND_INVOICE): string
    {
        $dunning = $kind === LayoutVersion::KIND_DUNNING;
        $wishes = trim((string) $wishes) !== '' ? trim((string) $wishes) : self::DEFAULT_WISHES;
        [$referenceBody, $referenceFooter] = LayoutService::standardTemplate($kind);

        $quote = $kind === LayoutVersion::KIND_QUOTE;
        $subject = $dunning ? 'die Zahlungserinnerungen und Mahnschreiben' : ($quote ? 'die Angebote' : 'die Rechnungen und Gutschriften');
        $tableRule = $dunning
            ? '6. Die Forderungstabelle ist kurz (offene Rechnung, optional Gebühr und Zinsen, Gesamtforderung). Gesamtforderung und Zahlungsblock nicht zerreißen lassen: <table nobr="true">. Der Brieftext {{doc.text}} ist fertig formuliert (Anrede und Schlussformel enthalten); nur mit | br ausgeben, nichts hinzufügen oder ändern.'
            : '6. Die Positionstabelle kann über mehrere Seiten laufen (Titelzeile in <thead>). Summen- und Zahlungsblock nicht zerreißen lassen: <table nobr="true">.';
        $required = $dunning
            ? '8. Pflicht-Platzhalter: {{doc.text}}, {{invoice.number}} und {{dunning.total}}.'
            : '8. Pflicht-Platzhalter: {{doc.number}}, {{#each items}} … {{/each}} und {{totals.gross_total}}.';
        $loops = $dunning
            ? ''
            : "  {{#each liste}} … {{/each}}          Schleife; innerhalb stehen die Felder des Eintrags direkt zur Verfügung, z. B. {{title}}\n";

        // Angebote: kein Zahlungsblock, dafür die Gültigkeit.
        $quoteNote = $quote
            ? "\n   Hier geht es um ANGEBOTE: kein Zahlungsblock, keine Skonto-Zeilen, keine Rechnungsserie. Stattdessen {{doc.valid_until}} (Gültig bis, mit {{labels.valid_until}}) deutlich zeigen. Die Seite hat keinen GiroCode."
            : '';

        $fields = '';
        foreach (DocumentModel::referenceFor($kind) as $path => $description) {
            $fields .= '  '.str_pad('{{'.$path.'}}', 34).' '.$description."\n";
        }

        return <<<TXT
Du bist ein erfahrener Layouter für Geschäftsdokumente. Erstelle die Vorlage für {$subject} der Firma {$company} als HTML mit CSS. Die Vorlage wird von der Software mPDF (PHP) zu einem PDF im Format A4 umgewandelt und mit den echten Daten gefüllt.

GESTALTUNGSWÜNSCHE
{$wishes}

TECHNISCHE REGELN (unbedingt einhalten, sonst wird die Vorlage abgelehnt)
1. Liefere nur HTML mit einem <style>-Block am Anfang. Keine Elemente <html>, <head> oder <body>. Kein JavaScript, keine Formulare, kein <svg>, kein <link>, kein <meta>.
2. mPDF kennt nur ein älteres CSS: Raster und Spalten mit Tabellen bauen; kein Flexbox, kein CSS-Grid, keine CSS-Variablen, kein calc(). Einheiten: mm, pt, %. position:absolute ist erlaubt und bezieht sich auf die Seite.
3. Schrift: nur dejavusans (Standard), dejavuserif oder dejavusansmono. Keine Web-Fonts, kein @import, kein @font-face.
4. Keine externen Ressourcen: keine Bilder aus dem Internet, kein url(...) außer eingebetteten data:image-Bildern. Das Firmenlogo und der GiroCode (QR) kommen ausschließlich über die Platzhalter {{tenant.logo}} und {{payment.qr}} und nur als src eines <img>-Elements. Prüfe jeweils mit {{#if tenant.logo}} bzw. {{#if payment.has_qr}}, ob sie vorhanden sind.{$quoteNote}
5. Die Seitenränder setzt die Software: oben 20 mm, rechts 20 mm, unten 32 mm, links 20 mm; die Fußzeile liegt im unteren Rand. Das Anschriftenfeld nach DIN 5008 Form B beginnt 45 mm von oben und 20 mm von links und ist 85 mm breit.
{$tableRule}
7. Alle Beträge, Mengen und Datumsangaben sind fertig formatierte Texte. Nichts nachrechnen und nichts umformatieren. Alle Texte sind Deutsch; Beschriftungen kommen aus {{labels.…}}.
{$required}

PLATZHALTER-SPRACHE (genau so verwenden, nichts erfinden)
  {{pfad.zum.wert}}                    gibt einen Wert aus (wird automatisch gegen HTML-Sonderzeichen geschützt)
  {{pfad | br}}                        wie oben, Zeilenumbrüche werden zu <br>   (für mehrzeilige Texte)
  {{pfad | upper}}                     GROSSBUCHSTABEN
  {{#if pfad}} … {{else}} … {{/if}}    nur anzeigen, wenn der Wert vorhanden/wahr ist ({{else}} ist optional)
  {{#unless pfad}} … {{/unless}}       nur anzeigen, wenn der Wert fehlt
{$loops}
VERFÜGBARE PLATZHALTER
{$fields}
AUSGABEFORMAT
Antworte mit genau einem Codeblock. Zuerst das Layout (<style> und HTML für den Seiteninhalt), dann eine eigene Zeile mit
===FOOTER===
und danach die Fußzeile als HTML (sie wiederholt sich auf jeder Seite; Seitenzahl mit {PAGENO} und {nbpg}, einfache geschweifte Klammern). Außerhalb des Codeblocks keine Erklärungen.

REFERENZ-LAYOUT (so sieht die Vorlage heute aus; funktioniert vollständig, als Ausgangspunkt für die Wünsche verwenden)
```html
{$referenceBody}
===FOOTER===
{$referenceFooter}
```
TXT;
    }
}
