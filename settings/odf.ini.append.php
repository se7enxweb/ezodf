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
# ConvertTypes are those documents which can be converted by oo and then it will publish it automatically.
# They need [ODFImport] ConversionDaemon=enabled.
ConvertTypes[]
ConvertTypes[]=doc

# OO Menu related setting
[OOMenuSettings]
# Name of top nodes.
TopNodeNameList[]
TopNodeNameList[]=RootNode
TopNodeNameList[]=UserRootNode
TopNodeNameList[]=MediaRootNode

*/ ?>
