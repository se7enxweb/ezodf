<?php /* #?ini charset="utf-8"?

# The document formats of 7x SQLI Import (sqliimport 2.x), from ezodf: ezodf converts, 7x SQLI Import maps, with its
# dry run, undo, schedules, statistics and run history. Only sqliimport reads this file; without it nothing happens.
# (Declared here rather than as kernel services, which would name sqliimport's classes on sites without it.)
[SourceSettings]
Sources[]=documents
Sources[]=document_urls
Sources[]=document_feed

[Source_documents]
Class=expOdfImportDocumentSource

[Source_document_urls]
Class=expOdfImportDocumentUrlsSource

[Source_document_feed]
Class=expOdfImportDocumentFeedSource

*/ ?>
