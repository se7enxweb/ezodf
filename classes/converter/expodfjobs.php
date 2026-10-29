<?php
/**
 * File containing the expOdfJobs class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * ezodf's background work, started from the administration and never run inside a web request:
 *
 *   ezodf.install    install, update or remove a downloaded LibreOffice   payload: action, version
 *   ezodf.server     start, stop or restart the conversion server          payload: action
 *   ezodf.test       the test conversions of the Document converter page    payload: samples
 *   ezodf.import     one document of a batch (expOdfBatch)                   payload: batch, item
 *   ezodf.export     an export of a subtree as a ZIP                         payload: node, format
 *
 * With expqueue (the job queue every Exponential 6 installation ships) the jobs are queued there (class
 * "interactive" for the converter's own work, "bulk" for documents) and the cron job every installation runs each
 * minute starts them; the Document converter page shows a notice and a "Run pending jobs now" button when that cron
 * job has not run. Without expqueue the job runs in a PHP process started for it (bin/php/ezodfjob.php).
 */
class expOdfJobs
{
    const TYPES = array( 'ezodf.install', 'ezodf.server', 'ezodf.test', 'ezodf.import', 'ezodf.export' );

    /** @return bool whether expqueue can take ezodf's jobs */
    public static function queueAvailable()
    {
        return class_exists( 'expQueue' ) && expQueue::installed();
    }

    /**
     * Starts a job.
     *
     * @param string $type one of TYPES
     * @param array $payload
     * @param array $options group (expQueueGroup), class, resource, idempotency_key
     * @return string the job's reference (expqueue uuid, or "process:<id>")
     */
    public static function start( $type, array $payload, array $options = array() )
    {
        if ( !in_array( $type, self::TYPES, true ) )
            throw new InvalidArgumentException( "unknown ezodf job $type" );
        $ref = '';
        if ( self::queueAvailable() )
        {
            $options += array( 'class' => in_array( $type, array( 'ezodf.import', 'ezodf.export' ), true ) ? 'bulk' : 'interactive',
                               'max_attempts' => in_array( $type, array( 'ezodf.import', 'ezodf.export' ), true ) ? 2 : 1,
                               'timeout' => $type === 'ezodf.install' ? 3600 : 900 );
            $job = expQueue::enqueue( $type, $payload, $options );
            $ref = $job->attribute( 'uuid' );
        }
        else
        {
            $ref = 'process:' . bin2hex( random_bytes( 8 ) );
            $file = expOdfPaths::dir( 'jobs' ) . '/' . substr( $ref, 8 ) . '.json';
            expOdfPaths::writeJson( $file, array( 'type' => $type, 'payload' => $payload, 'state' => 'queued', 'created' => time(),
                                                  'user_id' => class_exists( 'eZUser' ) ? (int)eZUser::currentUserID() : 0 ) );
            self::spawnRunner( array( 'extension/ezodf/bin/php/ezodfjob.php', '--job=' . substr( $ref, 8 ) ) );
        }
        self::remember( $type, $ref, $payload );
        return $ref;
    }

    /** Notes the last job of each kind for the status panels. */
    protected static function remember( $type, $ref, array $payload )
    {
        $file = expOdfPaths::base() . '/jobs.json';
        $data = expOdfPaths::readJson( $file );
        $key = $type . ( isset( $payload['action'] ) ? '.' . $payload['action'] : '' );
        $data[$type] = array( 'ref' => $ref, 'time' => time(), 'action' => isset( $payload['action'] ) ? $payload['action'] : '' );
        $data[$key] = $data[$type];
        expOdfPaths::writeJson( $file, $data );
    }

    /**
     * The state of a job.
     * @return array( 'state' => queued|running|done|failed|cancelled|unknown, 'message' => string, 'result' => array )
     */
    public static function status( $ref )
    {
        if ( strpos( (string)$ref, 'process:' ) === 0 )
        {
            $data = expOdfPaths::readJson( expOdfPaths::base() . '/jobs/' . preg_replace( '/[^a-f0-9]/', '', substr( $ref, 8 ) ) . '.json' );
            return array( 'state' => isset( $data['state'] ) ? $data['state'] : 'unknown', 'message' => isset( $data['message'] ) ? $data['message'] : '',
                          'result' => isset( $data['result'] ) ? $data['result'] : array() );
        }
        if ( !self::queueAvailable() || $ref === '' )
            return array( 'state' => 'unknown', 'message' => '', 'result' => array() );
        $job = expQueue::fetch( $ref );
        if ( !$job )
            return array( 'state' => 'unknown', 'message' => '', 'result' => array() );
        $state = (string)$job->attribute( 'state' );
        $map = array( 'ready' => 'queued', 'queued' => 'queued', 'retry' => 'queued', 'held' => 'queued', 'deferred' => 'queued',
                      'running' => 'running', 'done' => 'done', 'dead' => 'failed', 'failed' => 'failed', 'cancelled' => 'cancelled' );
        $result = json_decode( (string)$job->attribute( 'result' ), true );
        return array( 'state' => isset( $map[$state] ) ? $map[$state] : $state, 'message' => (string)$job->attribute( 'last_error' ),
                      'result' => is_array( $result ) ? $result : array(), 'progress' => (string)$job->attribute( 'progress_message' ),
                      'done' => (int)$job->attribute( 'progress_done' ), 'total' => (int)$job->attribute( 'progress_total' ) );
    }

    /** @return array the last job of a kind ( ref, time, action ) plus its status, or array() */
    public static function last( $key )
    {
        $data = expOdfPaths::readJson( expOdfPaths::base() . '/jobs.json' );
        if ( !isset( $data[$key] ) )
            return array();
        return $data[$key] + self::status( $data[$key]['ref'] );
    }

    /**
     * Whether the installation's cron job runs the queue: expqueue's note of its last cron run.
     * @return array( 'ok' => bool, 'last' => int|0, 'minutes' => int|null, 'pending' => int )
     */
    public static function cronState()
    {
        $pending = 0;
        $last = 0;
        if ( self::queueAvailable() )
        {
            try
            {
                $status = expQueueStats::cronStatus();
                $last = isset( $status['last'] ) ? (int)$status['last'] : ( isset( $status['time'] ) ? (int)$status['time'] : 0 );
                foreach ( self::TYPES as $type )
                    $pending += expQueue::count( array( 'type' => $type, 'state' => 'ready' ) );
                if ( !empty( expQueueWorker::live() ) )
                    return array( 'ok' => true, 'last' => $last, 'minutes' => $last ? (int)floor( ( time() - $last ) / 60 ) : null, 'pending' => $pending, 'worker' => true );
            }
            catch ( Exception $e )
            {
            }
        }
        else
        {
            foreach ( glob( expOdfPaths::base() . '/jobs/*.json' ) ?: array() as $file )
            {
                $data = expOdfPaths::readJson( $file );
                if ( isset( $data['state'] ) && $data['state'] === 'queued' )
                    $pending++;
            }
            return array( 'ok' => true, 'last' => time(), 'minutes' => 0, 'pending' => $pending, 'worker' => false, 'process' => true );
        }
        $minutes = $last ? (int)floor( ( time() - $last ) / 60 ) : null;
        return array( 'ok' => $last > 0 && $minutes <= 5, 'last' => $last, 'minutes' => $minutes, 'pending' => $pending, 'worker' => false );
    }

    /**
     * Runs the due jobs now in a PHP process of its own (the button "Run pending jobs now"): expqueue's cron part for
     * the current siteaccess. Returns at once.
     * @return bool
     */
    public static function runPendingNow()
    {
        if ( !self::queueAvailable() )
        {
            foreach ( glob( expOdfPaths::base() . '/jobs/*.json' ) ?: array() as $file )
            {
                $data = expOdfPaths::readJson( $file );
                if ( isset( $data['state'] ) && $data['state'] === 'queued' )
                    self::spawnRunner( array( 'extension/ezodf/bin/php/ezodfjob.php', '--job=' . basename( $file, '.json' ) ) );
            }
            return true;
        }
        $siteaccess = isset( $GLOBALS['eZCurrentAccess']['name'] ) ? (string)$GLOBALS['eZCurrentAccess']['name'] : '';
        $args = array( 'runcronjobs.php', '-q' );
        if ( preg_match( '/^[A-Za-z0-9_-]+$/', $siteaccess ) )
            array_push( $args, '-s', $siteaccess );
        $args[] = 'expqueue';
        return self::spawnRunner( $args );
    }

    /** @return string the PHP command line binary (php-fpm's PHP_BINARY is the FPM daemon) */
    public static function phpBinary()
    {
        $candidates = array();
        if ( PHP_SAPI === 'cli' && PHP_BINARY !== '' )
            $candidates[] = PHP_BINARY;
        $candidates[] = PHP_BINDIR . '/php';
        $candidates[] = dirname( PHP_BINDIR ) . '/bin/php';
        $candidates[] = '/usr/bin/php';
        foreach ( $candidates as $c )
            if ( is_file( $c ) && is_executable( $c ) && strpos( basename( $c ), 'fpm' ) === false )
                return $c;
        return '';
    }

    /** Starts a PHP script of the installation in the background (fixed scripts only). */
    protected static function spawnRunner( array $args )
    {
        $php = self::phpBinary();
        if ( $php === '' )
            return false;
        $allowed = array( 'runcronjobs.php', 'extension/ezodf/bin/php/ezodfjob.php' );
        if ( !in_array( $args[0], $allowed, true ) )
            return false;
        $command = array_merge( array( $php ), $args );
        if ( function_exists( 'posix_geteuid' ) && posix_geteuid() === 0 )
            $command[] = '--allow-root-user';
        $log = expOdfPaths::dir( 'jobs' ) . '/runner.log';
        $cwd = getcwd();
        chdir( expOdfPaths::root() );
        $ok = expOdfSandbox::spawn( $command, $log );
        chdir( $cwd );
        return $ok;
    }

    /**
     * Runs one job (the expqueue handler and bin/php/ezodfjob.php call this).
     *
     * @param string $type
     * @param array $payload
     * @param callable|null $progress fn( done, total, message )
     * @return array the result
     */
    public static function run( $type, array $payload, $progress = null )
    {
        $progress = $progress ?: function () {};
        $action = isset( $payload['action'] ) ? (string)$payload['action'] : '';
        switch ( $type )
        {
            case 'ezodf.install':
                $installer = new expOdfInstaller();
                $installer->onProgress = function ( $s ) use ( $progress ) {
                    $progress( isset( $s['bytes'] ) ? $s['bytes'] : 0, isset( $s['total'] ) ? $s['total'] : 0, isset( $s['message'] ) ? $s['message'] : '' );
                };
                if ( $action === 'remove' )
                    $state = $installer->remove( isset( $payload['version'] ) ? (string)$payload['version'] : '' );
                else
                    $state = $installer->install( isset( $payload['version'] ) ? (string)$payload['version'] : '' );
                // a running server keeps the old build until it restarts
                if ( $state['state'] === 'done' && $action !== 'remove' && expOdfServer::enabled() )
                    expOdfServer::restart();
                if ( $state['state'] !== 'done' )
                    throw new expOdfConverterException( 'install', $state['message'] );
                return array( 'message' => $state['message'] );
            case 'ezodf.server':
                $say = function ( $line ) use ( $progress ) { $progress( 0, 0, $line ); };
                if ( $action === 'stop' )
                    $r = expOdfServer::stop( $say );
                elseif ( $action === 'restart' )
                    $r = expOdfServer::restart( $say );
                else
                    $r = expOdfServer::start( $say );
                expOdfPaths::writeJson( expOdfPaths::base() . '/server/last-action.json', $r + array( 'action' => $action ?: 'start', 'time' => time() ) );
                if ( !$r['ok'] )
                    throw new expOdfConverterException( 'server', $r['message'] );
                return $r;
            case 'ezodf.test':
                return expOdfConverterTest::run( isset( $payload['samples'] ) ? (array)$payload['samples'] : array(), $progress );
            case 'ezodf.import':
                return expOdfBatch::runItem( (string)$payload['batch'], (string)$payload['item'], $progress );
            case 'ezodf.export':
                return expOdfExporter::runSubtreeJob( $payload, $progress );
        }
        throw new InvalidArgumentException( "unknown ezodf job $type" );
    }
}
