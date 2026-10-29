<?php
/**
 * File containing the expOdfQueueHandler class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The expqueue handler of ezodf's jobs (expqueue.ini [JobType_ezodf.*]); the work itself is in expOdfJobs::run().
 * Loaded only by an expqueue worker, where the interface exists.
 */
class expOdfQueueHandler extends expQueueHandlerBase
{
    public function run( expQueueJob $job, expQueueContext $context )
    {
        $payload = $context->payload();
        $type = (string)$job->attribute( 'type' );
        $last = 0;
        $progress = function ( $done, $total = 0, $message = '' ) use ( $context, &$last )
        {
            if ( time() === $last && $message === '' )
                return;
            $last = time();
            $context->progress( (int)$done, $total ? (int)$total : null, (string)$message );
        };
        try
        {
            return expOdfJobs::run( $type, $payload, $progress );
        }
        catch ( expOdfConverterException $e )
        {
            // a conversion or installation that failed for a reason a retry does not change
            if ( in_array( $e->code(), array( 'too_large', 'unsupported', 'install', 'server', 'no_backend', 'convert' ), true ) )
                throw new expQueuePermanentException( $e->getMessage() );
            throw $e;
        }
    }

    public function describe( expQueueJob $job )
    {
        $p = $job->payloadArray();
        $type = (string)$job->attribute( 'type' );
        switch ( $type )
        {
            case 'ezodf.install':
                return 'LibreOffice: ' . ( isset( $p['action'] ) ? $p['action'] : 'install' ) . ( !empty( $p['version'] ) ? ' ' . $p['version'] : '' );
            case 'ezodf.server':
                return 'Conversion server: ' . ( isset( $p['action'] ) ? $p['action'] : 'start' );
            case 'ezodf.test':
                return 'Test conversions';
            case 'ezodf.import':
                return 'Document import, batch ' . ( isset( $p['batch'] ) ? $p['batch'] : '' ) . ( isset( $p['name'] ) ? ': ' . $p['name'] : '' );
            case 'ezodf.export':
                return 'Document export of node ' . ( isset( $p['node'] ) ? (int)$p['node'] : 0 );
        }
        return '';
    }
}
