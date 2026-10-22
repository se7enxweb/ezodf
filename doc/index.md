---
title: Documents (ezodf 7)
---
# Documents: import and export of Word, OpenDocument, PDF and more

ezodf brings documents into Exponential and takes items out as documents. Everything a site owner or editor does
with it happens in the administration; nothing needs the command line.

German: [ger-de/index.md](ger-de/index.md).

| I want to … | Page | Chapter |
|---|---|---|
| set up LibreOffice, the converter, its limits | Setup > System > **Document converter** (`ezodf/converter`) | [converter.md](converter.md) |
| bring files, a whole folder, a ZIP or web addresses in | Documents > **Import documents** (`ezodf/import`), or an item's menu: Documents > Import documents here | [import.md](import.md) |
| see what an import did, retry what failed | Documents > **Document imports** (`ezodf/runs`) | [import.md](import.md#runs) |
| download an item as DOCX, PDF, ODT …, or a folder as a ZIP | Documents > **Export documents** (`ezodf/export`), or an item's menu: Documents > Export as … | [export.md](export.md) |
| import documents on a schedule, from a feed, WebDAV/Nextcloud, Google Drive, OneDrive or a mailbox, with a mapping, dry run and undo | Imports (7x SQLI Import), formats "Documents …" | [sources.md](sources.md) |
| know what the server needs, memory, the system packages, a remote converter | – | [hosting.md](hosting.md) |
| set the rights | Roles: `ezodf/import`, `ezodf/export`, `ezodf/converter` | [permissions.md](permissions.md) |

## Formats

Every format LibreOffice reads is a first-class format: Word (DOCX, DOC), OpenDocument (ODT, OTT, FODT), RTF, HTML,
plain text, WordPerfect, Works, Lotus Word Pro, AbiWord, Apple Pages, EPUB, Markdown, PDF, spreadsheets (XLSX, XLS,
ODS, CSV, Numbers …: a table per sheet), presentations (PPTX, PPT, ODP, Keynote …: a section per slide), and the
formats of OpenOffice.org 1.x and StarOffice (SXW, STW, SXG, SDW, SXC, STC, SDC, SXI, STI, SDD, SXD, STD, SDA).
The Document converter page lists the formats of the LibreOffice it uses, from that LibreOffice's own filter list.

Without LibreOffice, ezodf reads OpenDocument text, OpenOffice.org 1.x text, DOCX, spreadsheets (XLSX, ODS, CSV),
presentations (PPTX, ODP), Markdown, HTML, plain text and the text of PDF files itself, and exports OpenDocument
text.

## What changed in 7.0

- LibreOffice is installed with one click from the administration (no root, no system packages), or the system's is
  used; it runs in a sandbox without network access, optionally as a long-lived conversion server.
- Import of many documents at once: files, a folder with its tree, ZIP archives, web addresses, as background jobs
  with a live page per import and retry of failed documents.
- Export of any item as DOCX, PDF, ODT, RTF, DOC, XHTML, EPUB or text, and of a folder as a ZIP.
- Document formats for 7x SQLI Import (mapping, dry run, undo, schedules, statistics): an upload or ZIP, web
  addresses, feeds, WebDAV and Nextcloud, Google Drive, OneDrive, mailboxes.
- The 4.x conversion daemon is no longer needed; its settings still work for sites that run it.
