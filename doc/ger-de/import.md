---
title: Dokumente importieren
---
# Dokumente importieren

**Dokumente > Dokumente importieren** (`ezodf/import`, Recht `ezodf/import`); von einem Objekt aus: in seinem Menü
**Dokumente > Dokumente hier importieren**.

1. **Wohin**: der Ordner, in den die Dokumente kommen (**Ordner wählen**). Sie brauchen das Recht, dort Objekte
   anzulegen.
2. **Was**: Ziehen Sie Dateien oder Ordner auf die Fläche, oder wählen Sie **Dateien oder ZIP-Archive …** oder
   **Einen Ordner …**, und/oder geben Sie Webadressen von Dokumenten ein, eine pro Zeile. Bis zu 500 Dateien und
   500 MB in einem Import (Dokumentkonverter > Grenzwerte).
3. **Wie**:
   - **Ordner werden zu Ordnern**: Der Baum eines gewählten Ordners oder eines ZIP-Archivs wird unterhalb des Ziels
     nachgebaut (ein vorhandener Ordner mit demselben Namen wird wiederverwendet).
   - **Aktualisieren, was zuvor aus derselben Datei an dieselbe Stelle importiert wurde**: Der zweite Import einer
     Datei an dieselbe Stelle erzeugt eine neue Version des Objekts statt eines zweiten Objekts.
   - **Bilder und andere Dateien als Bild- und Dateiobjekte importieren**.
   - Die Sprache der neuen Objekte und ein Name für diesen Import.
4. **Import**. Die Dateien werden eine nach der anderen mit einem Fortschrittsbalken hochgeladen; danach öffnet sich
   die Seite des Imports.

Jedes Dokument wird von einem eigenen Hintergrundjob konvertiert und veröffentlicht; nichts wartet auf der Seite.
Ein Dokument wird zu einem Artikel (oder zu der Klasse, die `odf.ini [ODFImport] DefaultImportClass` nennt;
Dokumente mit Abschnitten, die wie die Attribute einer registrierten Klasse heißen, werden zu dieser Klasse):
Überschriften, Absätze, fett und kursiv, Listen, Tabellen, Links und Bilder. Eine Tabellenkalkulation wird zu einer
Tabelle pro Blatt, eine Präsentation zu einem Abschnitt pro Folie, ein PDF zu seinem Text.

## Die Seite eines Imports {#runs}

Sie zeigt jedes Dokument mit seinem Zustand (in der Warteschlange, läuft, fertig, fehlgeschlagen, übersprungen),
einen Link zum neuen Objekt, seine Klasse und den Konverter, der es gelesen hat, live, solange der Import läuft.
**Fehlgeschlagene Dokumente erneut versuchen** stellt sie wieder in die Warteschlange (zum Beispiel, nachdem Sie
LibreOffice installiert haben); **Erneut versuchen** tut das für ein einzelnes; **Abbrechen** stoppt die Dokumente,
die noch nicht begonnen haben. **Dokumentimporte** (`ezodf/runs`) listet Ihre Importe auf; Benutzer mit
`ezodf/converter` sehen die Importe aller Benutzer.

## Webadressen

Heruntergeladen werden nur `http`- und `https`-Adressen des öffentlichen Internets: Adressen des lokalen Netzes,
des Servers selbst und reservierter Bereiche werden abgelehnt und als abgelehnt aufgeführt. Jedes Dokument wird von
seinem Job mit einem Größen- und Zeitlimit heruntergeladen (Dokumentkonverter > Grenzwerte), wobei höchstens fünf
Weiterleitungen gefolgt wird, von denen jede erneut geprüft wird.

## Ohne JavaScript

Das Formular sendet die gewählten Dateien auf einmal (höchstens `max_file_uploads` von PHP, hier 40); alles andere
ist gleich.

## Der Einzeldokument-Import von 6.x

Eine neue Version eines Objekts aus einem Dokument (im Menü des Objekts: **Dokumente > Durch ein Dokument
ersetzen**) funktioniert weiter wie bisher, jetzt mit jedem Format, das der Konverter liest.
