---
title: Hinweise zum Hosting des Dokumentkonverters
---
# Hinweise zum Hosting

## Was der Server braucht

- Linux auf x86-64 für die Installation mit einem Klick; `tar` und `rpm2cpio` + `cpio` (RPM-basierte Systeme) oder
  `ar` (Debian und Ubuntu), um den Build zu entpacken; `gpg`, um seine Signatur zu prüfen (ohne `gpg` wird nur die
  Prüfsumme geprüft).
- `bwrap` (bubblewrap) für die Sandbox, `timeout` und `prlimit` (util-linux) für die Grenzwerte. Ohne bubblewrap
  läuft der Konverter nur mit den Grenzwerten, und die Seite sagt das.
- Etwa 2 GB freier Platz unterhalb von `var/` für die Installation (700 MB pro Build).
- Die PHP-Funktion `proc_open` und die curl-Erweiterung.
- Der Cronjob, den jede Installation hat: `php runcronjobs.php -q -s <admin siteaccess> expqueue` jede Minute. Er
  führt die Hintergrundjobs von ezodf aus und hält den Konvertierungsserver am Laufen.

## Speicher und CPU

Ein LibreOffice braucht beim Konvertieren etwa 150 bis 300 MB Speicher, mehr bei großen Tabellen oder
Präsentationen; die Grenze pro LibreOffice wird auf der Seite Dokumentkonverter eingestellt (Vorgabe 4 GB
Adressraum). Der Konvertierungsserver hält ein LibreOffice pro „gleichzeitiger Konvertierung“; zwei oder drei
reichen für die meisten Sites. Eine Konvertierung dauert mit dem Server 0,2 bis 1 Sekunde, ohne ihn 1,5 bis 3
Sekunden.

## Stattdessen LibreOffice aus den Systempaketen

Die Administration kann ein LibreOffice verwenden, das das System hat (Schaltfläche **Das installierte
verwenden**):

- RHEL, AlmaLinux, Rocky 9: `dnf install libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress
  libreoffice-draw libreoffice-pdfimport libreoffice-filters libreoffice-pyuno liberation-fonts poppler-utils`
  (EL9 liefert LibreOffice 7.1; ein eigenes Headless-Paket gibt es nicht)
- Debian und Ubuntu: `apt install libreoffice-core-nogui libreoffice-writer-nogui libreoffice-calc-nogui
  libreoffice-impress-nogui python3-uno fonts-liberation poppler-utils`

Der Konvertierungsserver braucht das uno-Modul des LibreOffice, das er verwendet (`libreoffice-pyuno` /
`python3-uno`; der von der Seite installierte Build bringt sein eigenes mit).

## Ein Konvertierungsdienst an anderer Stelle

Eine Site, die LibreOffice nicht selbst betreiben kann, kann **unoserver** (`pip install unoserver` auf einer
anderen Maschine, oder sein Container, dann `unoserver --interface 0.0.0.0`) oder **Gotenberg**
(`docker run gotenberg/gotenberg:8`, nur PDF) verwenden und dessen Adresse auf der Seite Dokumentkonverter unter
**Einen entfernten Server verwenden** eintragen.

## Kommandozeile (optional)

Alles oben Genannte funktioniert in der Administration. Für die Automatisierung:
`php extension/ezodf/bin/php/ezodfjob.php --job=<id>` führt einen wartenden ezodf-Job auf einer Site ohne
Job-Warteschlange aus; die eigenen Befehle der Job-Warteschlange (`./console ext:expqueue:work`) führen die Jobs von
ezodf wie jeden anderen Job aus.
