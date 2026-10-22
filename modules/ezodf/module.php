<?php
/**
 * The module ezodf: documents in and out.
 *
 *   import          Import documents: files, a folder (its tree), ZIP archives and URLs into a folder, as background
 *                   jobs; the 6.x single-document flow (a new version: import_type/replace) is kept
 *   upload          the uploads of the import page with JavaScript (JSON)
 *   batch           one import of documents with each document's state, retry, cancel
 *   batchstatus     the live state of a batch as JSON
 *   runs            the imports of documents
 *   export          one item as ODT (6.x, kept)
 *   converter       Document converter: the LibreOffice installation (one-click install, the installed one, update,
 *                   remove), the conversion server (on, off, restart, status, log), test conversions, a remote server,
 *                   the limits, the formats, cleanup; policy ezodf/converter
 *   converterstatus the live state of the converter page as JSON (polled by ezodf_admin.js)
 *   converterfile   a result of the test conversions, as a download
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @copyright Copyright (C) 1999-2014 eZ Systems AS
 * @license GNU General Public License v2.0 (or any later version)
 */

$Module = array( 'name' => 'eZODF',
                 'variable_params' => true );

$ViewList = array();
$ViewList['import'] = array(
    'script' => 'import.php',
    'params' => array(),
    'functions' => array( 'import' ),
    'post_actions' => array( 'BrowseActionName' ),
    'unordered_params' => array( 'node_id' => 'NodeID',
                                 'import_type' => 'ImportType' ) );

$ViewList['export'] = array(
    'script' => 'export.php',
    'params' => array(),
    'functions' => array( 'export' ),
    'post_actions' => array( 'BrowseActionName' ),
    'unordered_params' => array( 'node_id' => 'NodeID',
                                 'export_type' => 'ExportType' ) );

$ViewList['upload'] = array(
    'script' => 'upload.php',
    'params' => array(),
    'functions' => array( 'import' ) );

$ViewList['batch'] = array(
    'script' => 'batch.php',
    'params' => array( 'BatchID' ),
    'functions' => array( 'import' ),
    'default_navigation_part' => 'ezsetupnavigationpart' );

$ViewList['batchstatus'] = array(
    'script' => 'batchstatus.php',
    'params' => array( 'BatchID' ),
    'functions' => array( 'import' ) );

$ViewList['runs'] = array(
    'script' => 'runs.php',
    'params' => array(),
    'functions' => array( 'import' ),
    'default_navigation_part' => 'ezsetupnavigationpart' );

$ViewList['converter'] = array(
    'script' => 'converter.php',
    'params' => array(),
    'functions' => array( 'converter' ),
    'default_navigation_part' => 'ezsetupnavigationpart' );

$ViewList['converterstatus'] = array(
    'script' => 'converterstatus.php',
    'params' => array(),
    'functions' => array( 'converter' ) );

$ViewList['converterfile'] = array(
    'script' => 'converterfile.php',
    'params' => array( 'Name' ),
    'functions' => array( 'converter' ) );

$FunctionList = array();
$FunctionList['import'] = array();
$FunctionList['export'] = array();
// sets up and runs the document converter (install LibreOffice, the conversion server, limits, remote server)
$FunctionList['converter'] = array();

?>
