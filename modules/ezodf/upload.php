<?php
/**
 * ezodf/upload (policy ezodf/import): the uploads of the Import documents page with JavaScript, one request per step,
 * answered as JSON:
 *   Action=create  NodeID, options   -> { batch }
 *   Action=file    BatchID, Path, File -> { item } (one file of the selection or the folder, with its relative path)
 *   Action=urls    BatchID, URLs       -> { items, errors }
 *   Action=start   BatchID             -> { url } (the batch page)
 * Every request is a POST with the form token (ezformtoken), sent by ezodf_admin.js from the page's form.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
$http = eZHTTPTool::instance();
$t = 'extension/ezodf/import';
$answer = function ( array $data, $status = 200 )
{
    while ( @ob_end_clean() );
    if ( $status !== 200 )
        header( 'HTTP/1.1 ' . $status . ( $status === 403 ? ' Forbidden' : ' Bad Request' ) );
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Cache-Control: no-store' );
    header( 'X-Content-Type-Options: nosniff' );
    echo json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    eZExecution::cleanExit();
};
if ( strtoupper( (string)eZSys::serverVariable( 'REQUEST_METHOD', true ) ) !== 'POST' )
    $answer( array( 'error' => 'POST only' ), 400 );
$action = $http->hasPostVariable( 'Action' ) ? (string)$http->postVariable( 'Action' ) : '';
if ( $action === 'create' )
{
    $nodeID = (int)$http->postVariable( 'NodeID' );
    $node = eZContentObjectTreeNode::fetch( $nodeID );
    if ( !$node || !$node->canCreate() )
        $answer( array( 'error' => ezpI18n::tr( $t, 'You may not create items in this folder.' ) ), 403 );
    $batch = expOdfBatch::create( expOdfDocumentsAdmin::optionsFromPost( $http, $nodeID ) );
    $answer( array( 'batch' => $batch->id ) );
}
$batch = expOdfBatch::fetch( $http->hasPostVariable( 'BatchID' ) ? (string)$http->postVariable( 'BatchID' ) : '' );
if ( !$batch || !$batch->visibleTo() )
    $answer( array( 'error' => 'no such batch' ), 400 );
$run = $batch->run();
if ( (int)$run['user_id'] !== (int)eZUser::currentUserID() )
    $answer( array( 'error' => 'not your batch' ), 403 );
switch ( $action )
{
    case 'file':
        $files = expOdfDocumentsAdmin::uploadedFiles( 'File' );
        if ( !$files )
            $answer( array( 'error' => ezpI18n::tr( $t, 'The file did not arrive (too large for the server?).' ) ), 400 );
        $path = $http->hasPostVariable( 'Path' ) && trim( (string)$http->postVariable( 'Path' ) ) !== '' ? (string)$http->postVariable( 'Path' ) : $files[0]['path'];
        $item = $batch->addFile( $files[0]['tmp_name'], $path );
        $answer( isset( $item['error'] ) ? array( 'error' => $item['error'] ) : array( 'item' => $item['id'], 'path' => $item['path'] ), isset( $item['error'] ) ? 400 : 200 );
    case 'urls':
        list( $items, $errors ) = $batch->addUrls( (string)$http->postVariable( 'URLs' ) );
        $answer( array( 'items' => count( $items ), 'errors' => $errors ) );
    case 'start':
        $n = $batch->start();
        $run = $batch->run();
        // a batch of refused addresses only has no job, but its page says why
        $answer( array( 'jobs' => $n, 'url' => eZSys::indexDir() . '/ezodf/batch/' . $batch->id ), $run['items'] ? 200 : 400 );
}
$answer( array( 'error' => 'unknown action' ), 400 );
