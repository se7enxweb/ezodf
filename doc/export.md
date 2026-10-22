---
title: Export documents
---
# Export documents

**Documents > Export documents** (`ezodf/export`, right `ezodf/export`); from an item: its menu **Documents >
Export as OpenDocument (ODT) / PDF / Word (DOCX)**.

- **Download this item as**: ODT, DOCX, PDF, RTF, DOC, XHTML, EPUB or plain text. The item is written as an
  OpenDocument text (its text attributes with headings, lists, tables, links and images) and converted to the format
  chosen. A class listed in `odf.ini` (`[<class>] Attribute[]`) exports the attributes listed there; any other class
  exports all its text attributes.
- **This folder and everything in it**: one document per item with text, in the folder tree of the site, as a ZIP.
  It is written in the background; **Folder exports** lists it with a download link when it is ready. Items that
  could not be exported are named in `not exported.txt` in the ZIP.

Without LibreOffice (Document converter) only ODT can be written.
