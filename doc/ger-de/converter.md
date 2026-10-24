---
title: Dokumentkonverter
---
# Der Dokumentkonverter

**Einrichtung > System > Dokumentkonverter** (`ezodf/converter`, Recht `ezodf/converter`) richtet das LibreOffice
ein, das Dokumente konvertiert, und zeigt, was es gerade tut. Lange Aktionen (die Installation, der Start des
Servers, die Testkonvertierungen) laufen als Hintergrundjobs; die Seite zeigt ihren Fortschritt, und Sie können sie
verlassen.

## Der Status

Die Kennzahlen oben zeigen das aktive LibreOffice und woher es stammt (von dieser Seite oder vom System
installiert), ob der Konvertierungsserver läuft, das Ergebnis des letzten Tests, die Sandbox (isoliert: kein
Netzwerk, nur das eigene Verzeichnis des Jobs ist beschreibbar; nur Grenzwerte: Der Host hat kein bubblewrap) und
die Zahl der Filter.

Wenn der Cronjob, der die Hintergrundjobs ausführt, eine Weile nicht gelaufen ist, sagt die Seite das und bietet
**Wartende Jobs jetzt ausführen** an: Damit starten die wartenden Jobs in einem eigenen Prozess. Bitten Sie Ihren
Hoster, `runcronjobs.php` jede Minute auszuführen (siehe [hosting.md](hosting.md)); bis dahin tut die Schaltfläche
dasselbe.

## LibreOffice mit einem Klick installieren

**LibreOffice installieren** lädt den neuesten Build von The Document Foundation für Linux (etwa 250 MB, 700 MB auf
der Festplatte) in das eigene Verzeichnis `var/<site>/ezodf/libreoffice/` der Installation. Bevor er verwendet
wird,

1. muss seine SHA-256-Prüfsumme mit der auf download.documentfoundation.org veröffentlichten übereinstimmen (nie mit
   der eines Spiegelservers), und
2. muss seine OpenPGP-Signatur für den Build-Schlüssel von LibreOffice gültig sein, den ezodf mitbringt
   (`C283 9ECA D940 8FBE 9531 C3E9 F434 A1EF AFEE AEA3`).

Eine Datei, die eine der beiden Prüfungen nicht besteht, wird gelöscht, und die Seite sagt, warum. Danach wird der
Build entpackt (ohne root), einmal in der Sandbox gestartet, um ihn zu prüfen, und wird zum aktiven Build. Das
Protokoll der Installation steht auf der Seite.

- **Nach einer neuen Version suchen** fragt die Download-Seite; **Auf … aktualisieren** installiert den neueren
  Build auf dieselbe Weise und entfernt den älteren, sobald der neue seine Prüfung bestanden hat.
- **Das installierte verwenden** wechselt zum LibreOffice des Systems (den Paketen Ihrer Distribution oder einer
  Installation unter /opt/libreoffice). **Diesen Build verwenden** wechselt zurück.
- **Entfernen** löscht einen Build, der von dieser Seite installiert wurde. Systempakete werden nie angefasst.

## Der Konvertierungsserver

**Einschalten** hält LibreOffice gestartet und lässt es ein Dokument nach dem anderen konvertieren: einen
Bruchteil einer Sekunde pro Dokument statt mehrerer Sekunden. Er läuft als Benutzer des Webservers, in der Sandbox,
mit einem eigenen Profil. Er wird nach einem Absturz neu gestartet, nach einer Konvertierung, die länger als das
Zeitlimit dauert, und alle N Konvertierungen (Grenzwerte). Der Cronjob startet ihn nach einem Neustart des Servers
wieder, solange er eingeschaltet ist. **Ausschalten** beendet ihn; Konvertierungen starten LibreOffice dann für
jedes Dokument. **Neu starten** tut beides.

Die PHP-FPM-Seiten, Velocity und die Hintergrundjobs verwenden alle denselben Server; er braucht keinen
Netzwerkport.

## Testkonvertierung

**Testkonvertierungen ausführen** konvertiert die Beispiele, die mit ezodf geliefert werden (Word, RTF, Excel,
PowerPoint, OpenDocument und eine Datei von OpenOffice.org 1.x), mit dem Konverter, wie er jetzt eingerichtet ist,
und listet jedes Ergebnis mit seiner Zeit und seinem Filter auf. Ein Ergebnis lässt sich herunterladen.

## Wie Dokumente konvertiert werden

- **Automatisch** (empfohlen): der entfernte Server, wenn einer eingestellt ist, sonst der Konvertierungsserver,
  wenn er läuft, sonst LibreOffice pro Dokument, sonst PHP.
- **Der Konvertierungsserver**, **LibreOffice pro Dokument**, **Nur der entfernte Server**, **Nur PHP**: dieselbe
  Kette, verkürzt.

## Grenzwerte

Zeit pro Dokument, größtes Dokument, gleichzeitige Konvertierungen (jede mit einem eigenen LibreOffice; Achtung,
Speicher!), Neustart des Servers nach N Konvertierungen, Speicher pro LibreOffice und wie lange Uploads und die
Listen der Importe aufbewahrt werden.

## Einen entfernten Server verwenden

Statt LibreOffice auf diesem Server: ein vorhandener **unoserver** (seine XML-RPC-Adresse, z. B.
`http://10.0.0.5:2003`; jedes Format) oder **Gotenberg** (`http://gotenberg:3000`; nur PDF, andere Konvertierungen
fallen auf diesen Server zurück), mit optionalem Benutzer und Passwort. **Speichern und testen** prüft, ob er
antwortet.

## KI-Unterstützung

Ist Exponential Intelligence (expai) installiert, fügt **KI-Vorschläge auf der Importseite anbieten** der Seite
Dokumente importieren die Option **Zusammenfassung, Schlüsselwörter und Bildtexte mit KI vorschlagen** hinzu. Für
jedes Dokument eines solchen Imports schlägt die KI eine Zusammenfassung für die Einleitung und Schlüsselwörter vor;
sie stehen auf der Seite des Imports und werden erst in das Objekt geschrieben (als neue Version), wenn jemand
**In das Objekt übernehmen** oder **Alle KI-Vorschläge übernehmen** wählt. Gesendet wird nur der eigene Text des
Dokuments (seine ersten 6000 Zeichen), nach den Regeln und der Datenrichtlinie von Exponential Intelligence; jeder
Aufruf steht in deren Nutzungsprotokoll. Standardmäßig ausgeschaltet.

## Protokoll, Formate, Speicherplatz

Das Protokoll des Servers, die Formate des aktiven LibreOffice (Formate von OpenOffice.org 1.x und StarOffice sind
markiert) und der Speicherplatz von Builds, Uploads und Arbeitsdateien. **Jetzt aufräumen** entfernt Arbeitsdateien,
die älter als eine Stunde sind, und Uploads, die älter als die unter Grenzwerte eingestellten Tage sind (der Cronjob
tut dasselbe jede Stunde).

## Wo die Einstellungen gespeichert werden

Was diese Seite ändert, wird in `var/<site>/ezodf/settings.json` gespeichert, dem eigenen Speicher der Erweiterung,
über den Vorgaben von `odf.ini [Converter]` (und `[BulkImport]`, `[RemoteSettings]`, `[Retention]`). Nichts wird nach
`settings/override` geschrieben. Ein Deployment, das seine Konfiguration selbst verwaltet (`site.ini
[ConfigurationSettings] ManagedByDeployment=enabled`), setzt die Werte in `odf.ini`; die Seite zeigt sie dann nur an.
