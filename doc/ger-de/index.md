---
title: Dokumente (ezodf 7)
---
# Dokumente: Import und Export von Word, OpenDocument, PDF und mehr

ezodf bringt Dokumente nach Exponential und gibt Objekte als Dokumente heraus. Alles, was Site-Betreiber oder
Redakteure damit tun, geschieht in der Administration; die Kommandozeile wird nicht gebraucht.

English: [../index.md](../index.md).

| Ich möchte … | Seite | Kapitel |
|---|---|---|
| LibreOffice, den Konverter und seine Grenzwerte einrichten | Einrichtung > System > **Dokumentkonverter** (`ezodf/converter`) | [converter.md](converter.md) |
| Dateien, einen ganzen Ordner, ein ZIP oder Webadressen übernehmen | Dokumente > **Dokumente importieren** (`ezodf/import`), oder im Menü eines Objekts: Dokumente > Dokumente hier importieren | [import.md](import.md) |
| sehen, was ein Import getan hat, und Fehlgeschlagenes erneut versuchen | Dokumente > **Dokumentimporte** (`ezodf/runs`) | [import.md](import.md#runs) |
| ein Objekt als DOCX, PDF, ODT … oder einen Ordner als ZIP herunterladen | Dokumente > **Dokumente exportieren** (`ezodf/export`), oder im Menü eines Objekts: Dokumente > Als … exportieren | [export.md](export.md) |
| Dokumente nach Zeitplan importieren, aus einem Feed, WebDAV/Nextcloud, Google Drive, OneDrive oder einem Postfach, mit Zuordnung, Probelauf und Rückgängig | Importe (7x SQLI Import), Formate „Documents …“ | [sources.md](sources.md) |
| wissen, was der Server braucht: Speicher, die Systempakete, ein entfernter Konverter | – | [hosting.md](hosting.md) |
| die Rechte vergeben | Rollen: `ezodf/import`, `ezodf/export`, `ezodf/converter` | [permissions.md](permissions.md) |

## Formate

Jedes Format, das LibreOffice liest, ist ein vollwertiges Format: Word (DOCX, DOC), OpenDocument (ODT, OTT, FODT),
RTF, HTML, reiner Text, WordPerfect, Works, Lotus Word Pro, AbiWord, Apple Pages, EPUB, Markdown, PDF, Tabellen
(XLSX, XLS, ODS, CSV, Numbers …: eine Tabelle pro Blatt), Präsentationen (PPTX, PPT, ODP, Keynote …: ein Abschnitt
pro Folie) und die Formate von OpenOffice.org 1.x und StarOffice (SXW, STW, SXG, SDW, SXC, STC, SDC, SXI, STI, SDD,
SXD, STD, SDA). Die Seite Dokumentkonverter listet die Formate des verwendeten LibreOffice auf, aus der eigenen
Filterliste dieses LibreOffice.

Ohne LibreOffice liest ezodf selbst OpenDocument-Text, Text von OpenOffice.org 1.x, DOCX, Tabellen (XLSX, ODS,
CSV), Präsentationen (PPTX, ODP), Markdown, HTML, reinen Text und den Text von PDF-Dateien, und es exportiert
OpenDocument-Text.

## Was sich in 7.0 geändert hat

- LibreOffice wird mit einem Klick aus der Administration installiert (ohne root, ohne Systempakete), oder das
  LibreOffice des Systems wird verwendet; es läuft in einer Sandbox ohne Netzwerkzugang, auf Wunsch als dauerhaft
  laufender Konvertierungsserver.
- Import vieler Dokumente auf einmal: Dateien, ein Ordner mit seinem Baum, ZIP-Archive, Webadressen, als
  Hintergrundjobs, mit einer Live-Seite pro Import und erneutem Versuch für fehlgeschlagene Dokumente.
- Export jedes Objekts als DOCX, PDF, ODT, RTF, DOC, XHTML, EPUB oder Text, und eines Ordners als ZIP.
- Dokumentformate für 7x SQLI Import (Zuordnung, Probelauf, Rückgängig, Zeitpläne, Statistik): ein Upload oder
  ZIP, Webadressen, Feeds, WebDAV und Nextcloud, Google Drive, OneDrive, Postfächer.
- Der Konvertierungs-Daemon von 4.x wird nicht mehr gebraucht; seine Einstellungen funktionieren weiter für Sites,
  die ihn betreiben.
