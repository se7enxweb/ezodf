<?php
//
// Definition of eZOpenofficehandler class
//
// Created on: <27-Jul-2005 14:52:11 bf>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ publish
// SOFTWARE RELEASE: 3.9.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2006 eZ systems AS
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
  \brief The class eZOpenofficehandler does

*/

include_once( "kernel/classes/ezcontentuploadhandler.php" );
include_once( "extension/ezodf/modules/ezodf/ezooimport.php" );
include_once( "lib/ezutils/classes/ezsys.php" );

class eZOpenofficeUploadHandler extends eZContentUploadHandler
{
    function eZOpenofficeUploadHandler()
    {
        $this->eZContentUploadHandler( 'OOo file handling', 'openoffice' );
    }

    /*!
      Handling the uploading of OpenOffice.org docuemnt.
    */
    function handleFile( &$upload, &$result,
                         $filePath, $originalFilename, $mimeinfo,
                         $location, $existingNode )
    {
        //$ooINI = eZINI::instance( 'odf.ini' );
        //$tmpDir = $ooINI->variable( 'ODFSettings', 'TmpDir' );
        $tmpDir = getcwd() . "/" . eZSys::cacheDirectory();

        $originalFilename = basename( $originalFilename );
        $tmpFile = $tmpDir . "/" . $originalFilename;
        copy( $filePath, $tmpFile );

        $import = new eZOOImport();
        $tmpResult = $import->import( $tmpFile, $location, $originalFilename, 'import', $upload );

        $result['contentobject'] = $tmpResult['Object'];
        $result['contentobject_main_node'] = $tmpResult['MainNode'];
        unlink( $tmpFile );


        return true;
    }

}
?>
