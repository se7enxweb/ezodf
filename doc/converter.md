---
title: Document converter
---
# The Document converter

**Setup > System > Document converter** (`ezodf/converter`, right `ezodf/converter`) sets up the LibreOffice that
converts documents, and shows what it is doing. Long actions (installing, starting the server, the test
conversions) run as background jobs; the page shows their progress and you can leave it.

## The status

The figures at the top show the active LibreOffice and where it comes from (installed from this page or by the
system), whether the conversion server runs, the result of the last test, the sandbox (isolated: no network, only
the job's own directory writable; limits only: the host has no bubblewrap), and the number of filters.

If the cron job that runs background jobs has not run for a while, the page says so and offers **Run pending jobs
now**: it starts the waiting jobs in a process of their own. Ask your hosting to run `runcronjobs.php` every minute
(see [hosting.md](hosting.md)); until then, the button does the same.

## Installing LibreOffice with one click

**Install LibreOffice** downloads the newest build of The Document Foundation for Linux (about 250 MB, 700 MB on
disk) into the installation's own `var/<site>/ezodf/libreoffice/`. Before it is used:

1. its SHA-256 checksum must match the one published on download.documentfoundation.org (never a mirror's), and
2. its OpenPGP signature must be valid for the LibreOffice build key that ezodf ships
   (`C283 9ECA D940 8FBE 9531 C3E9 F434 A1EF AFEE AEA3`).

A file that fails either check is deleted and the page says why. The build is then unpacked (no root needed), started
once in the sandbox to check it, and becomes the active one. The log of the installation is on the page.

- **Check for a new version** asks the download site; **Update to …** installs the newer build the same way and
  removes the older one once the new one passed its check.
- **Use the installed one** switches to the LibreOffice of the system (the packages of your distribution, or an
  /opt/libreoffice installation). **Use this build** switches back.
- **Remove** deletes a build installed from this page. System packages are never touched.

## The conversion server

**Turn on** keeps LibreOffice started and lets it convert one document after the other: a fraction of a second per
document instead of several seconds. It runs as the web server's user, in the sandbox, with its own profile. It is
restarted after a crash, after a conversion that takes longer than the time limit, and every N conversions (Limits).
The cron job brings it up again after a restart of the server, as long as it is turned on. **Turn off** stops it;
conversions then start LibreOffice for each document. **Restart** does both.

The PHP-FPM pages, Velocity and the background jobs all use the same server; it needs no network port.

## Test conversion

**Run the test conversions** converts the samples that come with ezodf (Word, RTF, Excel, PowerPoint, OpenDocument
and an OpenOffice.org 1.x file) with the converter as it is set up now, and lists each result with its time and
filter. A result can be downloaded.

## How documents are converted

- **Automatic** (recommended): the remote server when one is set, else the conversion server when it runs, else
  LibreOffice per document, else PHP.
- **The conversion server**, **LibreOffice per document**, **Only the remote server**, **Only PHP**: the same chain,
  shortened.

## Limits

Time per document, largest document, conversions at the same time (each one a LibreOffice of its own; memory!),
restart of the server after N conversions, memory per LibreOffice, and how long uploads and lists of imports are kept.

## Use a remote server

Instead of LibreOffice on this server: an existing **unoserver** (its XML-RPC address, e.g. `http://10.0.0.5:2003`;
every format) or **Gotenberg** (`http://gotenberg:3000`; PDF only, other conversions fall back to this server), with
an optional user and password. **Save and test** checks that it answers.

## AI assist

With Exponential Intelligence (expai) installed, **Offer AI suggestions on the import page** adds the option
**Suggest a summary, keywords and image texts with AI** to Import documents. For each document of such an import the
AI suggests a summary for the intro and keywords; they are listed on the page of the import and written into the item
(a new version) only when someone clicks **Apply to the item** or **Apply all AI suggestions**. Only the document's
own text (its first 6000 characters) is sent, under the gate and the data policy of Exponential Intelligence, and
every call is in its usage log. Off by default.

## Log, formats, disk use

The server's log, the formats of the active LibreOffice (OpenOffice.org 1.x and StarOffice formats are marked), and
the disk space of builds, uploads and work files. **Clean up now** removes work files older than an hour and uploads
older than the days set under Limits (the cron job does the same every hour).

## Where the settings are kept

What this page changes is stored in `var/<site>/ezodf/settings.json`, the extension's own store, over the defaults
of `odf.ini [Converter]` (and `[BulkImport]`, `[RemoteSettings]`, `[Retention]`). Nothing is written to
`settings/override`. A deployment that manages its configuration (`site.ini [ConfigurationSettings]
ManagedByDeployment=enabled`) sets the values in `odf.ini`; the page then shows them read-only.
