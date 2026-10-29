<?php
/**
 * ezodf/batchstatus/<id> (policy ezodf/import): the live state of a batch as JSON (polled by ezodf_admin.js).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
$batch = expOdfBatch::fetch( (string)$Params['BatchID'] );
while ( @ob_end_clean() );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
header( 'X-Content-Type-Options: nosniff' );
if ( !$batch || !$batch->visibleTo() )
{
    header( 'HTTP/1.1 404 Not Found' );
    echo '{"error":"no such batch"}';
    eZExecution::cleanExit();
}
$b = expOdfDocumentsAdmin::batchData( $batch );
$items = array();
foreach ( $b['items'] as $i )
    $items[] = array( 'id' => $i['id'], 'state' => $i['state'], 'state_label' => $i['state_label'], 'message' => $i['message'] );
echo json_encode( array( 'state' => $b['state'], 'busy' => $b['busy'], 'counts' => $b['counts'], 'percent' => $b['percent'], 'items' => $items ),
                  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
eZExecution::cleanExit();
