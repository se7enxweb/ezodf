<?php /* #?ini charset="utf-8"?

# The Word and OpenDocument import and export in Exponential's transfer registry (kernel 6.0.15 and later,
# settings/kernelservices.ini; the kernel's doc/eng-us/features/6.0/transfer-registry.md). The kernel lists the tool in
# the "Which import or export tool do I need?" help page, the "Looking for…?" boxes of the other tools, the "Import
# and export" lists, an item's Import/Export menu and the administration's search. Older kernels ignore this file.

[Service_transfer.ezodf]
Class=expKernelTransferTool
Factory=expKernelTransferTool::fromSettings
Arguments[]=transfer.ezodf
Scope=worker
Tags[]=transfer.tool
Description=Bring Word, OpenDocument, PDF, spreadsheets, presentations and older OpenOffice.org and StarOffice files in as items, many at once with their folder tree, also from ZIP archives and web addresses; download any item as DOCX, PDF or ODT, or a folder as a ZIP.
Name=Documents (Word, OpenDocument, PDF …)
Direction=both
Scopes[]=object
Scopes[]=subtree
Formats[]=DOCX
Formats[]=DOC
Formats[]=ODT
Formats[]=PDF
Formats[]=RTF
Formats[]=XLSX
Formats[]=ODS
Formats[]=PPTX
Formats[]=ODP
Formats[]=Markdown
Formats[]=HTML
Formats[]=SXW
Formats[]=ZIP
EntryURL=ezodf/import
Permission=ezodf/import|ezodf/export|ezodf/converter
HelpPage=ext/ezodf/index.md
RoundTrip[]=transfer.ezodf
Keywords[]=word document
Keywords[]=word processor
Keywords[]=libreoffice
Keywords[]=Textverarbeitung
Keywords[]=Word-Dokument
Keywords[]=pdf
Keywords[]=docx
Keywords[]=upload a folder
Keywords[]=zip
Keywords[]=powerpoint
Keywords[]=openoffice
Keywords[]=Ordner hochladen
Keywords[]=Dokumente
Actions[]=container|import|Import documents here (files, a folder, a ZIP)…|ezodf/import/(node_id)/%nodeID%|ezodf/import
Actions[]=node|export|Download as Word, PDF or OpenDocument…|ezodf/export/(node_id)/%nodeID%|ezodf/export
Weight=50

*/ ?>
