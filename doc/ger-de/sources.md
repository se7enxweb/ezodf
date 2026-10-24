---
title: Dokumentformate von 7x SQLI Import
---
# Dokumente in 7x SQLI Import

Mit 7x SQLI Import (dem Reiter Importe) sind Dokumente ein Format wie CSV oder ein Feed: Eine Zuordnung bringt die
Felder eines Dokuments in eine beliebige Klasse, mit Probelauf, Zeitplänen, Rückgängig, Statistik und dem Verlauf
der Läufe des Werkzeugs Importe. ezodf konvertiert; 7x SQLI Import ordnet zu. Wählen Sie das Format unter
**Importe > New import**:

| Format | Woher die Dokumente kommen |
|---|---|
| Documents (one file or a folder as ZIP) | ein Upload, eine Adresse oder eine Datei auf dem Server; jeder Ordner eines ZIP ist ebenfalls ein Datensatz, so lässt sich der Baum nachbauen |
| Documents from URLs | eine Liste von Webadressen |
| Documents of a feed (RSS, Atom) | die Dokumente, auf die die Einträge eines Feeds verlinken |
| Documents in a WebDAV or Nextcloud folder | ein Ordner eines WebDAV-Servers oder von Nextcloud/ownCloud, mit seinen Unterordnern |
| Documents in a Google Drive folder | ein Drive-Ordner, der für ein Dienstkonto freigegeben ist; Google Docs, Sheets und Slides werden als DOCX, XLSX, PPTX exportiert |
| Documents in a OneDrive or SharePoint folder | ein Ordner eines Laufwerks, gelesen mit einer App-Registrierung |
| Documents attached to e-mails (IMAP) | die Anhänge der Nachrichten eines Postfachs, die zu einer Suche passen |

Passwörter und Schlüssel werden nie in einen Import eingetippt: Ein Import nennt ein **Secret** (Importe >
Settings), wie die anderen Formate von 7x SQLI Import auch.

## Die Felder eines Dokuments

`title` (der Titel des Dokuments, sonst seine erste Überschrift, sonst der Dateiname), `summary` (seine
Beschreibung, sonst sein erster Absatz), `html` (der Inhalt; ordnen Sie ihn mit der Umwandlung `richtext` zu),
`text`, `headings`, `images`, `keywords`, `author`, `created`, `modified`, `language`, `pages`, `format`, `size`,
`file` (das Original, für ein Dateiattribut), `path`, `folder`, `parent_path`, `kind` (Dokument oder Ordner) und
`source_url`; der Feed fügt Titel, Link, Datum und Zusammenfassung des Eintrags hinzu, das Postfach Betreff und
Datum der Nachricht.

Die vorgegebene Zuordnung bringt den Titel, die Zusammenfassung und den Inhalt in einen Artikel und legt jedes
Dokument unterhalb des Ordners seines Pfads ab.
