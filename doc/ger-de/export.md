---
title: Dokumente exportieren
---
# Dokumente exportieren

**Dokumente > Dokumente exportieren** (`ezodf/export`, Recht `ezodf/export`); von einem Objekt aus: in seinem Menü
**Dokumente > Als OpenDocument (ODT) / PDF / Word (DOCX) exportieren**.

- **Dieses Objekt herunterladen als**: ODT, DOCX, PDF, RTF, DOC, XHTML, EPUB oder reiner Text. Das Objekt wird als
  OpenDocument-Text geschrieben (seine Textattribute mit Überschriften, Listen, Tabellen, Links und Bildern) und in
  das gewählte Format konvertiert. Eine Klasse, die in `odf.ini` aufgeführt ist (`[<class>] Attribute[]`), exportiert
  die dort genannten Attribute; jede andere Klasse exportiert alle ihre Textattribute.
- **Dieser Ordner mit allem darin**: ein Dokument pro Objekt mit Text, im Ordnerbaum der Site, als ZIP. Es wird im
  Hintergrund geschrieben; **Ordnerexporte** listet es mit einem Download-Link auf, sobald es fertig ist. Objekte,
  die nicht exportiert werden konnten, sind in `not exported.txt` im ZIP genannt.

Ohne LibreOffice (Dokumentkonverter) kann nur ODT geschrieben werden.
