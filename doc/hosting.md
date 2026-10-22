---
title: Hosting notes for the document converter
---
# Hosting notes

## What the server needs

- Linux on x86-64 for the one-click installation; `tar` and `rpm2cpio` + `cpio` (RPM-based systems) or `ar`
  (Debian and Ubuntu) to unpack the build; `gpg` to check its signature (without it only the checksum is checked).
- `bwrap` (bubblewrap) for the sandbox, `timeout` and `prlimit` (util-linux) for the limits. Without bubblewrap the
  converter runs with the limits only, and the page says so.
- About 2 GB free below `var/` for the installation (700 MB per build).
- The PHP functions `proc_open` and the curl extension.
- The cron job every installation has: `php runcronjobs.php -q -s <admin siteaccess> expqueue` every minute. It runs
  ezodf's background jobs and keeps the conversion server running.

## Memory and CPU

One LibreOffice takes about 150 to 300 MB of memory while it converts, more for large spreadsheets or presentations;
the limit per LibreOffice is set on the Document converter page (default 4 GB of address space). The conversion
server keeps one LibreOffice per "conversion at the same time"; two or three are plenty for most sites. A conversion
takes 0.2 to 1 second with the server, 1.5 to 3 seconds without it.

## LibreOffice from the system packages instead

The administration can use a LibreOffice the system has (button **Use the installed one**):

- RHEL, AlmaLinux, Rocky 9: `dnf install libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress
  libreoffice-draw libreoffice-pdfimport libreoffice-filters libreoffice-pyuno liberation-fonts poppler-utils`
  (EL9 ships LibreOffice 7.1; there is no separate headless package)
- Debian and Ubuntu: `apt install libreoffice-core-nogui libreoffice-writer-nogui libreoffice-calc-nogui
  libreoffice-impress-nogui python3-uno fonts-liberation poppler-utils`

The conversion server needs the uno module of the LibreOffice it uses (`libreoffice-pyuno` / `python3-uno`; the
build installed from the page brings its own).

## A conversion service elsewhere

A site without the means to run LibreOffice can use **unoserver** (`pip install unoserver` on another machine, or its
container, then `unoserver --interface 0.0.0.0`) or **Gotenberg** (`docker run gotenberg/gotenberg:8`, PDF only) and
set its address on the Document converter page under **Use a remote server**.

## Command line (optional)

Everything above works in the administration. For automation: `php extension/ezodf/bin/php/ezodfjob.php --job=<id>`
runs a queued ezodf job on a site without the job queue; the job queue's own commands (`./console ext:expqueue:work`)
run ezodf's jobs like every other job.
