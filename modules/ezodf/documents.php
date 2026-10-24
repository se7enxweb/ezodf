<?php
/**
 * ezodf/import (policy ezodf/import): the Import documents page. Files, a whole folder (its tree becomes folders),
 * ZIP archives and URLs go into a target folder; the page creates a batch and every document is converted and
 * imported by a background job of its own (expOdfBatch). With JavaScript the files are uploaded one by one with
 * progress (ezodf/upload); without it the form posts them at once (up to max_file_uploads files).
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$isPost = strtoupper( (string)eZSys::serverVariable( 'REQUEST_METHOD', true ) ) === 'POST';
$t = 'extension/ezodf/import';

// the folder chosen in the browse step
if ( $http->hasPostVariable( 'BrowseActionName' ) && $http->postVariable( 'BrowseActionName' ) === 'EzodfImportTarget' )
{
    $selected = eZContentBrowse::result( 'EzodfImportTarget' );
    $nodeID = is_array( $selected ) && $selected ? (int)$selected[0] : 0;
    return $Module->redirectTo( '/ezodf/import' . ( $nodeID ? '/(node_id)/' . $nodeID : '' ) );
}
if ( $isPost && $http->hasPostVariable( 'ChooseTargetButton' ) )
{
    eZContentBrowse::browse( array( 'action_name' => 'EzodfImportTarget', 'description_template' => 'design:ezodf/browse_place.tpl',
                                    'content' => array(), 'from_page' => '/ezodf/import', 'cancel_page' => '/ezodf/import' ), $Module );
    return;
}

$nodeID = isset( $Params['NodeID'] ) && is_numeric( $Params['NodeID'] ) ? (int)$Params['NodeID'] : 0;
if ( $isPost && $http->hasPostVariable( 'NodeID' ) )
    $nodeID = (int)$http->postVariable( 'NodeID' );
$node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
$error = '';
if ( $nodeID && ( !$node || !$node->canRead() ) )
{
    $error = ezpI18n::tr( $t, 'The folder does not exist or you may not see it.' );
    $node = null;
}
elseif ( $node && !$node->canCreate() )
    $error = ezpI18n::tr( $t, 'You may not create items in "%name".', null, array( '%name' => $node->attribute( 'name' ) ) );

// the form without JavaScript: the files, the folder and the URLs at once
if ( $isPost && $http->hasPostVariable( 'StartImportButton' ) && $node && $error === '' )
{
    $batch = expOdfBatch::create( expOdfDocumentsAdmin::optionsFromPost( $http, $nodeID ) );
    $errors = array();
    foreach ( array( 'Files', 'Folder' ) as $field )
        foreach ( expOdfDocumentsAdmin::uploadedFiles( $field ) as $f )
        {
            $item = $batch->addFile( $f['tmp_name'], $f['path'] );
            if ( isset( $item['error'] ) )
                $errors[] = $f['path'] . ': ' . $item['error'];
        }
    list( , $urlErrors ) = $batch->addUrls( $http->hasPostVariable( 'URLs' ) ? (string)$http->postVariable( 'URLs' ) : '' );
    $errors = array_merge( $errors, $urlErrors );
    $started = $batch->start();
    if ( $errors )
        $http->setSessionVariable( 'EzodfImportFlash', array( 'kind' => $started ? 'warn' : 'bad', 'message' => implode( '; ', $errors ) ) );
    if ( !$started && !$errors )
        $http->setSessionVariable( 'EzodfImportFlash', array( 'kind' => 'bad', 'message' => ezpI18n::tr( $t, 'Choose files, a folder or URLs to import.' ) ) );
    $run = $batch->run();
    return $Module->redirectTo( $run['items'] ? '/ezodf/batch/' . $batch->id : '/ezodf/import/(node_id)/' . $nodeID );
}

$flash = $http->hasSessionVariable( 'EzodfImportFlash' ) ? $http->sessionVariable( 'EzodfImportFlash' ) : null;
$http->removeSessionVariable( 'EzodfImportFlash' );
$tpl = eZTemplate::factory();
$tpl->setVariable( 'node', $node );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'flash', $flash );
$tpl->setVariable( 'd', expOdfDocumentsAdmin::pageData() );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:ezodf/documents.tpl' );
$Result['navigation_part'] = 'ezsetupnavigationpart';
$Result['left_menu'] = 'design:ezodf/parts/menu.tpl';
$Result['path'] = array( array( 'url' => false, 'text' => ezpI18n::tr( 'extension/ezodf/converter', 'Documents' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( $t, 'Import documents' ) ) );
