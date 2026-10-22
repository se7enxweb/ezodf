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
Description=Turn a Word or OpenDocument text into an item (or a translation of one), or download an item as a document to edit in a word processor.
Name=Word and OpenDocument
Direction=both
Scopes[]=object
Formats[]=DOCX
Formats[]=DOC
Formats[]=ODT
EntryURL=ezodf/import
Permission=ezodf/import|ezodf/export
HelpPage=ext/ezodf/README
RoundTrip[]=transfer.ezodf
Keywords[]=word document
Keywords[]=word processor
Keywords[]=libreoffice
Keywords[]=Textverarbeitung
Keywords[]=Word-Dokument
Actions[]=container|import|Import a Word or OpenDocument file here…|ezodf/import/(node_id)/%nodeID%|ezodf/import
Actions[]=node|export|Download as an OpenDocument text…|ezodf/export/(node_id)/%nodeID%|ezodf/export
Weight=50

*/ ?>
