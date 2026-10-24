<?php /*

[ODFSettings]
# Path to zip program on windows you can use:
# http://www.info-zip.org/pub/infozip/
# enter for example c:\zip\ or /usr/local/bin/
ZipPath=
# Directory for storing temporary files during conversion (now stored in var/storage, so is deprecated?)
TmpDir=/tmp

[ODFImport]
# Default class for import
DefaultImportClass=article
# Default class for making an image
DefaultImportImageClass=image
RegisteredClassArray[]=article
RegisteredClassArray[]=folder
RegisteredClassArray[]=image
RegisteredClassArray[]=documentation_page
RegisteredClassArray[]=blog_post
ImportedImagesMediaNodeName=Imported images
PlaceImagesInMedia=false
# The document conversion daemon (scripts/daemon.php, doc/README.daemon) converts .doc uploads (ConvertTypes)
# to OpenDocument and exports to PDF and Word. It is disabled by default: then those uploads and exports report
# that conversion is not available, and nothing connects to the port below. Enable it only together with a running
# daemon, and add the Word upload handler mapping in upload.ini.
ConversionDaemon=disabled
# (ezodf 7 converts with LibreOffice in a sandbox, see [Converter]; the daemon is used only when enabled here)
OOConverterPort=9090
# Currently only localhost (127.0.0.1) is allowed
OOConverterAddress=127.0.0.1

[ODFExport]
UseTemplate=true
TemplateName=ezpublish.ott
TemplateRepository=extension/ezodf/templates

# There are two mapping options available.
# One is using sections in OO and the other is using headers to separate the input data into each attribute. 
# If you enable this setting, the header separator is used. By default, it is set to be enabled.

ClassAttributeMappingToHeader=enabled

# Map eZ Publish attributes to OpenOffice.org section definitions
#
# Format:
# [<class_identifier>]
# DefaultImportTitleAttribute=<attribute>
# DefaultImportBodyAttribute=<attribute>
# Is used to content of file into attributes if there is no known sections
# in the OO input file.
# Attribute[]= matches sections from the OO input document with eZ Publish attributes

[article]
DefaultImportTitleAttribute=title
DefaultImportBodyAttribute=body
Attribute[title]=title
Attribute[intro]=intro
Attribute[body]=body
Attribute[image]=image
Attribute[caption]=caption
Attribute[publish_date]=publish_date
Attribute[unpublish_date]=unpublish_date

[folder]
DefaultImportTitleAttribute=name
DefaultImportBodyAttribute=description
Attribute[name]=name
Attribute[short_description]=short_description
Attribute[description]=description

[image]
DefaultImportTitleAttribute=name
DefaultImportBodyAttribute=image
Attribute[name]=name
Attribute[caption]=caption
Attribute[image]=image

[documentation_page]
DefaultImportTitleAttribute=title
DefaultImportBodyAttribute=body
Attribute[title]=title
Attribute[body]=body

[blog_post]
DefaultImportTitleAttribute=title
DefaultImportBodyAttribute=body
Attribute[title]=title
Attribute[intro]=intro
Attribute[body]=body
Attribute[image]=image

# DocumentType tells which are the supported document type by oo
[DocumentType]
# AllowedTypes are those documents which are supported and published directely and need not to convert for support.
AllowedTypes[]
AllowedTypes[]=odt
# ConvertTypes are the documents the converter turns into OpenDocument text first (ezodf 7: LibreOffice in its
# sandbox, the conversion server, a remote server, or the PHP readers; see the Document converter page,
# ezodf/converter). Text: Word, RTF, HTML, plain text, WordPerfect, Works, Lotus, AbiWord, Pages, EPUB, the
# OpenDocument template and flat forms; OpenOffice.org 1.x and StarOffice (sxw, stw, sxg, sdw); spreadsheets and
# presentations become a table per sheet and a section per slide; PDF its text by page; Markdown.
ConvertTypes[]

ConvertTypes[]=doc
ConvertTypes[]=docx
ConvertTypes[]=docm
ConvertTypes[]=dot
ConvertTypes[]=dotx
ConvertTypes[]=rtf
ConvertTypes[]=txt
ConvertTypes[]=html
ConvertTypes[]=htm
ConvertTypes[]=xhtml
ConvertTypes[]=ott
ConvertTypes[]=fodt
ConvertTypes[]=wpd
ConvertTypes[]=wps
ConvertTypes[]=lwp
ConvertTypes[]=abw
ConvertTypes[]=pages
ConvertTypes[]=epub
ConvertTypes[]=hwp
ConvertTypes[]=md
ConvertTypes[]=markdown
ConvertTypes[]=pdf
ConvertTypes[]=sxw
ConvertTypes[]=stw
ConvertTypes[]=sxg
ConvertTypes[]=sdw
ConvertTypes[]=ods
ConvertTypes[]=fods
ConvertTypes[]=xlsx
ConvertTypes[]=xlsm
ConvertTypes[]=xls
ConvertTypes[]=csv
ConvertTypes[]=sxc
ConvertTypes[]=stc
ConvertTypes[]=sdc
ConvertTypes[]=numbers
ConvertTypes[]=odp
ConvertTypes[]=fodp
ConvertTypes[]=pptx
ConvertTypes[]=ppsx
ConvertTypes[]=ppt
ConvertTypes[]=pps
ConvertTypes[]=sxi
ConvertTypes[]=sti
ConvertTypes[]=sdd
ConvertTypes[]=key

# OO Menu related setting
[OOMenuSettings]
# Name of top nodes.
TopNodeNameList[]
TopNodeNameList[]=RootNode
TopNodeNameList[]=UserRootNode
TopNodeNameList[]=MediaRootNode


# ---- ezodf 7: the document converter. The Document converter page (ezodf/converter) changes these values in the
# extension's own store (var/<site>/ezodf/settings.json), which wins over the values here; set them here (or in a
# siteaccess) for a deployment that manages its configuration.
[Converter]
# auto (remote server, conversion server, LibreOffice per document, PHP), server, cli, remote or php
Mode=auto
# which LibreOffice: auto (the one installed from the admin, else the system's), downloaded, system, custom (Binary)
Installation=auto
Binary=
# keep the downloaded build on a version line (26.8) or empty for the newest
VersionPin=
DownloadURL=https://download.documentfoundation.org/libreoffice/stable/
# the long-lived conversion server (enabled/disabled); the cron job keeps it running when enabled
Server=disabled
Timeout=90
MaxMegabytes=50
MaxParallel=1
RestartAfter=200
MemoryMegabytes=4096
# a remote conversion service: unoserver (XML-RPC) or gotenberg (PDF only)
RemoteURL=
RemoteKind=unoserver
RemoteUsername=
RemotePassword=

[BulkImport]
FolderClass=folder
FileClass=file
KeepOriginal=false
MaxFiles=500
MaxBatchMegabytes=500

# documents from URLs: never private, loopback or link-local addresses unless AllowPrivateHosts=true
[RemoteSettings]
MaxMegabytes=50
Timeout=30
AllowPrivateHosts=false

[Retention]
# uploads, runs and test results are removed after this many days (work files after an hour)
KeepDays=14

# AI assist (expai): suggestions for class, folder, title, summary, keywords and alt texts; off by default
[AISettings]
Enabled=false

*/ ?>
