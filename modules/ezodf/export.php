<?php
/**
 * Entry point of ezodf/export.
 *
 * ezodf 7: the Export documents page and the downloads are modules/ezodf/exportdocuments.php: one item as ODT, DOCX,
 * PDF, RTF, DOC, XHTML, EPUB or text, and a folder with everything below it as a ZIP (a background job). The 6.x
 * view (extension/ezodf/classes/runnable/views/ezodf/export.php) still answers its browse step (ExportButton,
 * OOPlace).
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
$http = eZHTTPTool::instance();
$legacy = $http->hasPostVariable( 'ExportButton' )
       || ( $http->hasPostVariable( 'BrowseActionName' ) && $http->postVariable( 'BrowseActionName' ) === 'OOPlace' );
if ( !$legacy )
    return include __DIR__ . '/exportdocuments.php';
return \Exponential\View\Extension\Ezodf\Ezodf\Export::main( __FILE__, get_defined_vars() );
