<?php /* #?ini charset="utf-8"?

# The left menu of the Documents pages (design:ezodf/parts/menu.tpl); the pages mark the Setup tab.
[Leftmenu_ezodf]
Name=Documents
Links[]
Links[import]=ezodf/import
Links[runs]=ezodf/runs
Links[converter]=ezodf/converter
Links[queue]=expqueue/dashboard
PolicyList_import[]=ezodf/import
PolicyList_converter[]=ezodf/converter
PolicyList_runs[]=ezodf/import
PolicyList_queue[]=expqueue/view

# The Document converter in the Setup menu (group System)
[Leftmenu_setup]
Links[document_converter]=ezodf/converter
PolicyList_document_converter[]=ezodf/converter
GroupLinks_system[]=document_converter
LinkNames[document_converter]=Document converter
LinkIcons[document_converter]=file-earmark-break
LinkDescriptions[document_converter]=LibreOffice for importing and exporting documents: install, conversion server, tests

*/ ?>
