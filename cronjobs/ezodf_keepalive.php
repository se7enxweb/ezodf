<?php
/**
 * Cron part of ezodf: keeps the conversion server running when the administration turned it on (after a reboot or
 * a crash), and removes work files, uploaded batches and test results older than retention.keep_days.
 * Runs in the expqueue cron group every minute (cronjob.ini [CronjobPart-expqueue]) and in ezodf's own part.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
$say = function ( $line ) use ( $cli, $isQuiet ) { if ( !$isQuiet ) $cli->output( 'ezodf: ' . $line ); };
try
{
    $say( 'conversion server: ' . expOdfServer::keepalive() );
    $removed = expOdfCleanup::run( false, 3600 );
    if ( $removed['items'] )
        $say( sprintf( 'cleanup: %d item(s), %.1f MB', $removed['items'], $removed['bytes'] / 1048576 ) );
}
catch ( Exception $e )
{
    $cli->error( 'ezodf: ' . $e->getMessage() );
}
