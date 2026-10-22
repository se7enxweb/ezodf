<?php
/**
 * ezodf/batch/<id> (policy ezodf/import): one import of documents, each document with its state, live while it runs;
 * Retry failed, Retry (one), Cancel. A batch is shown to the user who started it and to users with ezodf/converter.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$batch = expOdfBatch::fetch( (string)$Params['BatchID'] );
if ( !$batch || !$batch->visibleTo() )
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
$t = 'extension/ezodf/import';
if ( strtoupper( (string)eZSys::serverVariable( 'REQUEST_METHOD', true ) ) === 'POST' )
{
    $message = null;
    if ( $http->hasPostVariable( 'RetryFailedButton' ) )
        $message = array( 'kind' => 'ok', 'message' => ezpI18n::tr( $t, '%n document(s) queued again.', null, array( '%n' => $batch->retryFailed() ) ) );
    elseif ( $http->hasPostVariable( 'RetryItem' ) )
        $message = array( 'kind' => 'ok', 'message' => ezpI18n::tr( $t, '%n document(s) queued again.', null, array( '%n' => $batch->retryFailed( array( (string)$http->postVariable( 'RetryItem' ) ) ) ) ) );
    elseif ( $http->hasPostVariable( 'CancelButton' ) )
    {
        $batch->cancel();
        $message = array( 'kind' => 'ok', 'message' => ezpI18n::tr( $t, 'The documents that had not started are cancelled.' ) );
    }
    if ( $message )
        $http->setSessionVariable( 'EzodfImportFlash', $message );
    return $Module->redirectTo( '/ezodf/batch/' . $batch->id );
}
$flash = $http->hasSessionVariable( 'EzodfImportFlash' ) ? $http->sessionVariable( 'EzodfImportFlash' ) : null;
$http->removeSessionVariable( 'EzodfImportFlash' );
$tpl = eZTemplate::factory();
$tpl->setVariable( 'flash', $flash );
$tpl->setVariable( 'b', expOdfDocumentsAdmin::batchData( $batch ) );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:ezodf/batch.tpl' );
$Result['navigation_part'] = 'ezsetupnavigationpart';
$Result['left_menu'] = 'design:ezodf/parts/menu.tpl';
$Result['path'] = array( array( 'url' => '/ezodf/runs', 'text' => ezpI18n::tr( $t, 'Document imports' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( $t, 'Batch %id', null, array( '%id' => substr( $batch->id, 0, 15 ) ) ) ) );
