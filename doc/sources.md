---
title: Document formats of 7x SQLI Import
---
# Documents in 7x SQLI Import

With 7x SQLI Import (the Imports tab), documents are a format like CSV or a feed: a mapping puts the fields of a
document into any class, with a dry run, schedules, undo, statistics and the run history of the Imports tool.
ezodf converts; 7x SQLI Import maps. Choose the format in **Imports > New import**:

| Format | Where the documents come from |
|---|---|
| Documents (one file or a folder as ZIP) | an upload, an address or a file on the server; every folder of a ZIP is a record too, so the tree can be rebuilt |
| Documents from URLs | a list of web addresses |
| Documents of a feed (RSS, Atom) | the documents the items of a feed link to |
| Documents in a WebDAV or Nextcloud folder | a folder of a WebDAV server or Nextcloud/ownCloud, with its subfolders |
| Documents in a Google Drive folder | a Drive folder shared with a service account; Google Docs, Sheets and Slides are exported as DOCX, XLSX, PPTX |
| Documents in a OneDrive or SharePoint folder | a folder of a drive, read with an app registration |
| Documents attached to e-mails (IMAP) | the attachments of the messages of a mailbox that match a search |

Passwords and keys are never typed into an import: an import names a **secret** (Imports > Settings), as the other
formats of 7x SQLI Import do.

## The fields of a document

`title` (the document's title, else its first heading, else the file name), `summary` (its description, else its
first paragraph), `html` (the body; map it with the transform `richtext`), `text`, `headings`, `images`, `keywords`,
`author`, `created`, `modified`, `language`, `pages`, `format`, `size`, `file` (the original, for a file attribute),
`path`, `folder`, `parent_path`, `kind` (document or folder) and `source_url`; the feed adds the item's title, link,
date and summary, the mailbox the message's subject and date.

The preset mapping puts the title, the summary and the body into an article and places every document below the
folder of its path.
