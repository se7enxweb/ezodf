<?php
/**
 * ezodf/exportstatus (policy ezodf/export): whether one of the user's folder exports still runs, as JSON (the export
 * page reloads itself when none does).
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
$busy = false;
foreach ( expOdfExporter::listExports() as $e )
    if ( in_array( $e['state'], array( 'queued', 'running' ), true ) )
        $busy = true;
while ( @ob_end_clean() );
header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );
echo json_encode( array( 'busy' => $busy ) );
eZExecution::cleanExit();
