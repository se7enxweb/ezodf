<?php
/**
 * Entry point of ezodf/import.
 *
 * ezodf 7: the Import documents page (files, a folder, ZIP archives, URLs into a folder, as background jobs) is
 * modules/ezodf/documents.php. The single-document flow of 6.x (extension/ezodf/classes/runnable/views/ezodf/import.php)
 * still answers what it always answered: a new version of an item (import_type/replace), its upload form (oo_file),
 * and its browse step (OOPlace), so bookmarks, the context menu's "Replace" and REST clients keep working.
 *
 * @copyright Copyright (C) 1998 - 2026 7x and the Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
$http = eZHTTPTool::instance();
$legacy = ( isset( $Params['ImportType'] ) && $Params['ImportType'] === 'replace' )
       || eZHTTPFile::canFetch( 'oo_file' )
       || $http->hasPostVariable( 'ImportType' )
       || ( $http->hasPostVariable( 'BrowseActionName' ) && $http->postVariable( 'BrowseActionName' ) === 'OOPlace' );
if ( !$legacy )
    return include __DIR__ . '/documents.php';
return \Exponential\View\Extension\Ezodf\Ezodf\Import::main( __FILE__, get_defined_vars() );
