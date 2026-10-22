---
title: Import documents
---
# Import documents

**Documents > Import documents** (`ezodf/import`, right `ezodf/import`); from an item: its menu **Documents >
Import documents here**.

1. **Where**: the folder the documents go into (**Choose a folder**). You need the right to create items there.
2. **What**: drop files or folders on the area, or choose **Files or ZIP archives…** or **A folder…**, and/or enter
   web addresses of documents, one per line. Up to 500 files and 500 MB in one import (Document converter > Limits).
3. **How**:
   - **Folders become folders**: the tree of a chosen folder or of a ZIP archive is rebuilt below the target (an
     existing folder of the same name is used again).
   - **Update what was imported before from the same file and place**: the second import of a file into the same
     place makes a new version of the item instead of a second item.
   - **Import images and other files as image and file items**.
   - The language of the new items, and a name for this import.
4. **Import**. The files are uploaded one by one with a progress bar; then the page of the import opens.

Each document is converted and published by a background job of its own; nothing waits on the page. A document
becomes an article (or the class `odf.ini [ODFImport] DefaultImportClass` names; documents with sections named like
the attributes of a registered class become that class): headings, paragraphs, bold and italic, lists, tables,
links and images. A spreadsheet becomes a table per sheet, a presentation a section per slide, a PDF its text.

## The page of an import {#runs}

It shows every document with its state (queued, running, done, failed, skipped), a link to the new item, its class
and the converter that read it, live while the import runs. **Retry the failed documents** queues them again (after
you installed LibreOffice, for example); **Retry** does it for one; **Cancel** stops the documents that have not
started. **Document imports** (`ezodf/runs`) lists your imports; users with `ezodf/converter` see everybody's.

## Web addresses

Only `http` and `https` addresses of the public internet are downloaded: addresses of the local network, the server
itself and reserved ranges are refused and listed as refused. Each document is downloaded by its job with a size
and time limit (Document converter > Limits), following at most five redirects, each checked again.

## Without JavaScript

The form sends the chosen files at once (at most `max_file_uploads` of PHP, 40 here); everything else is the same.

## The single-document import of 6.x

A new version of an item from a document (the item's menu: **Documents > Replace with a document**) still works as
before, now with every format the converter reads.
