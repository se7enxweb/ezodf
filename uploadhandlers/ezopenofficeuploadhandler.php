<?php
//
// Definition of eZOpenofficehandler class
//
// Created on: <27-Jul-2005 14:52:11 bf>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ Publish
// SOFTWARE RELEASE: 3.9.x
// COPYRIGHT NOTICE: Copyright (C) 1998 - 2026 7x & Exponential Foundation
// COPYRIGHT NOTICE: Copyright (C) 1999-2014 eZ Systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*! \file ezopenofficehandler.php
*/

/*!
  \class eZOpenofficehandler ezopenofficehandler.php
  \brief The class eZOpenofficehandler handles uploads of ODF files

*/

class eZOpenofficeUploadHandler extends eZContentUploadHandler
{
    /*!
     Constructor. eZContentUpload creates the handler with "new $handlerClass()"; under PHP 8 the former
     eZOpenofficeUploadHandler() constructor was not called, and eZContentUploadHandler::__construct() ended the
     upload with an ArgumentCountError.
    */
    function __construct()
    {
        parent::__construct( 'OOo file handling', 'openoffice' );
    }

    /*!
     \deprecated Use eZOpenofficeUploadHandler::__construct() instead
    */
    function eZOpenofficeUploadHandler()
    {
        self::__construct();
    }

    /*!
      Handling the uploading of OpenOffice.org docuemnt.
      \return true when the document was imported, false with the reason in $result['errors'] otherwise
    */
    function handleFile( &$upload, &$result,
                         $filePath, $originalFilename, $mimeinfo,
                         $location, $existingNode )
    {
        // The upload is read where it is: import() extracts it into a directory of its own. A copy named after
        // the browser's file name was shared by every upload of a file with that name.
        $originalFilename = basename( $originalFilename );

        $import = $this->createImport();
        $tmpResult = $import->import( $filePath, $location, $originalFilename, 'import', $upload );

        if ( !is_array( $tmpResult ) || !isset( $tmpResult['Object'] ) || !is_object( $tmpResult['Object'] ) )
        {
            $result['errors'][] = array( 'description' => $import->getErrorMessage() );
            return false;
        }

        $result['contentobject'] = $tmpResult['Object'];
        $result['contentobject_main_node'] = $tmpResult['MainNode'];

        return true;
    }

    /*!
     The importer handleFile() uses.
    */
    protected function createImport()
    {
        return new eZOOImport();
    }

}
?>
