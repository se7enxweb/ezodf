<?php
/**
 * File containing the expOdfServer class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The conversion server: one or more long-lived headless LibreOffice processes, each supervised by ezodf's worker
 * script (bin/ezodfworker.py) inside the sandbox, serving the spool directory (see the worker's description).
 *
 *   start()       starts converter.max_parallel supervisors (from a background job, never in a web request)
 *   stop()        asks them to stop (a stop file), then ends them
 *   status()      running, the supervisors' heartbeats, conversions, last error, the log
 *   keepalive()   the cron part: starts the server again when it is turned on and not running (after a reboot or a
 *                 crash), at most three times in ten minutes
 *
 * PHP-FPM, Velocity and the job worker all hand conversions to it the same way, through files below var/, so no
 * socket is shared between users and the converter needs no network.
 */
class expOdfServer
{
    /** @return string the server's state directory */
    public static function stateDir()
    {
        return expOdfPaths::dir( 'server' );
    }

    /** @return string the spool directory */
    public static function spoolDir()
    {
        return expOdfPaths::dir( 'spool' );
    }

    /** @return string the directory of supervisor $n */
    protected static function slotDir( $n )
    {
        return expOdfPaths::dir( 'server/slot' . (int)$n );
    }

    /** @return string the worker script */
    public static function workerScript()
    {
        return expOdfPaths::extension() . '/bin/ezodfworker.py';
    }

    /** @return bool whether the administration turned the server on */
    public static function enabled()
    {
        return (bool)expOdfSettings::get( 'converter.server_enabled' );
    }

    /**
     * @return array( 'running' => bool, 'slots' => list of heartbeats, 'conversions', 'failures', 'version', 'since',
     *                'last_error', 'enabled', 'spooled' )
     */
    public static function status()
    {
        $slots = array();
        $running = 0;
        $conversions = $failures = 0;
        $lastError = '';
        $since = 0;
        $version = '';
        $dirs = glob( expOdfPaths::base() . '/server/slot*', GLOB_ONLYDIR ) ?: array();
        foreach ( $dirs as $dir )
        {
            $hb = expOdfPaths::readJson( $dir . '/heartbeat.json' );
            $info = expOdfPaths::readJson( $dir . '/info.json' );
            if ( !$hb )
                continue;
            $alive = isset( $hb['time'] ) && $hb['time'] > time() - 15 && !in_array( $hb['status'], array( 'stopped' ), true )
                   && ( empty( $info['child-pid'] ) || is_dir( '/proc/' . (int)$info['child-pid'] ) );
            $hb['alive'] = $alive;
            $hb['slot'] = basename( $dir );
            $hb['outer_pid'] = isset( $info['child-pid'] ) ? (int)$info['child-pid'] : 0;
            $slots[] = $hb;
            if ( $alive )
            {
                $running++;
                $conversions += (int)$hb['conversions'];
                $failures += (int)$hb['failures'];
                $since = $since ? min( $since, (int)$hb['started'] ) : (int)$hb['started'];
                $version = $hb['version'];
            }
            if ( !empty( $hb['last_error'] ) )
                $lastError = $hb['last_error'];
        }
        $spooled = count( glob( expOdfPaths::base() . '/spool/*/request.json' ) ?: array() );
        return array( 'running' => $running > 0, 'running_slots' => $running, 'slots' => $slots, 'conversions' => $conversions,
                      'failures' => $failures, 'since' => $since, 'version' => $version, 'last_error' => $lastError,
                      'enabled' => self::enabled(), 'spooled' => $spooled, 'starts' => self::recentStarts() );
    }

    /** @return bool whether at least one supervisor is alive */
    public static function running()
    {
        $s = self::status();
        return $s['running'];
    }

    /**
     * Starts the supervisors that are not running (from a job or the cron part, never in a web request).
     *
     * @param callable|null $say receives progress lines
     * @return array( 'ok' => bool, 'message' => string )
     */
    public static function start( $say = null )
    {
        $say = $say ?: function ( $line ) {};
        $installation = expOdfInstallation::active();
        if ( !$installation )
            return array( 'ok' => false, 'message' => 'No LibreOffice installation: install one or choose the installed one first.' );
        if ( $installation->python === '' )
            return array( 'ok' => false, 'message' => 'The installation has no Python with the uno module (system packages: install libreoffice-pyuno); the per-call mode is used instead.' );
        $lock = fopen( expOdfPaths::base() . '/server/start.lock', 'c' );
        if ( !$lock || !flock( $lock, LOCK_EX | LOCK_NB ) )
            return array( 'ok' => false, 'message' => 'The server is being started already.' );
        try
        {
            self::noteStart();
            $wanted = (int)expOdfSettings::get( 'converter.max_parallel' );
            $started = 0;
            for ( $n = 1; $n <= $wanted; $n++ )
            {
                $dir = self::slotDir( $n );
                $hb = expOdfPaths::readJson( $dir . '/heartbeat.json' );
                $info = expOdfPaths::readJson( $dir . '/info.json' );
                if ( $hb && $hb['time'] > time() - 15 && $hb['status'] !== 'stopped' && !empty( $info['child-pid'] ) && is_dir( '/proc/' . (int)$info['child-pid'] ) )
                    continue;
                @unlink( $dir . '/stop' );
                @unlink( $dir . '/heartbeat.json' );
                $say( "starting supervisor $n with " . $installation->version );
                $command = self::command( $installation, $dir );
                $infoFile = $dir . '/info.json';
                @unlink( $infoFile );
                if ( !expOdfSandbox::spawn( $command, $dir . '/stdout.log', $infoFile ) )
                    return array( 'ok' => false, 'message' => 'The server could not be started.' );
                $started++;
            }
            // wait until every slot reports a heartbeat (soffice takes a few seconds the first time)
            $deadline = time() + 90;
            do
            {
                usleep( 500000 );
                $s = self::status();
                $idle = 0;
                foreach ( $s['slots'] as $slot )
                    if ( $slot['alive'] && in_array( $slot['status'], array( 'idle', 'converting' ), true ) && (int)substr( $slot['slot'], 4 ) <= $wanted )
                        $idle++;
            } while ( $idle < $wanted && time() < $deadline );
            // supervisors above the wanted number (max_parallel was lowered) stop
            for ( $n = $wanted + 1; $n <= 8; $n++ )
                if ( is_dir( expOdfPaths::base() . '/server/slot' . $n ) )
                    touch( expOdfPaths::base() . '/server/slot' . $n . '/stop' );
            if ( $idle < $wanted )
            {
                $err = $s['last_error'] !== '' ? ': ' . $s['last_error'] : '';
                return array( 'ok' => $idle > 0, 'message' => "$idle of $wanted converter processes are ready$err" );
            }
            return array( 'ok' => true, 'message' => $started ? "The conversion server is running ($wanted process" . ( $wanted > 1 ? 'es' : '' ) . ', ' . $installation->version . ').' : 'The conversion server was running already.' );
        }
        finally
        {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /**
     * The command of one supervisor: prlimit + bwrap + the installation's Python + the worker script.
     * @return string[]
     */
    public static function command( expOdfInstallation $installation, $slotDir )
    {
        $script = self::workerScript();
        $readOnly = array( $installation->root, dirname( $script ) );
        // a system installation's Python reads its uno module below /usr (already visible)
        $writable = array( $slotDir, self::spoolDir() );
        $worker = array( $installation->python, $script, 'serve', '--spool', self::spoolDir(), '--state', $slotDir,
                         '--soffice', $installation->soffice,
                         '--timeout', (string)expOdfSettings::get( 'converter.timeout' ),
                         '--restart-after', (string)expOdfSettings::get( 'converter.restart_after' ) );
        return expOdfSandbox::command( $worker, $readOnly, $writable, array(
            'seconds' => 0, 'memory' => expOdfSettings::get( 'converter.memory_megabytes' ),
            'fsize' => max( 256, expOdfSettings::get( 'converter.max_megabytes' ) * 8 ), 'nproc' => 1024, 'info_fd' => 3 ) );
    }

    /**
     * Stops every supervisor: a stop file first (the running conversion finishes), then SIGTERM, then SIGKILL.
     * @return array( 'ok' => bool, 'message' => string )
     */
    public static function stop( $say = null )
    {
        $say = $say ?: function ( $line ) {};
        $dirs = glob( expOdfPaths::base() . '/server/slot*', GLOB_ONLYDIR ) ?: array();
        foreach ( $dirs as $dir )
            touch( $dir . '/stop' );
        $deadline = time() + 20;
        while ( time() < $deadline && self::running() )
            usleep( 500000 );
        $killed = 0;
        foreach ( $dirs as $dir )
        {
            $info = expOdfPaths::readJson( $dir . '/info.json' );
            $pid = isset( $info['child-pid'] ) ? (int)$info['child-pid'] : 0;
            if ( $pid && expOdfSandbox::isOurs( $pid, $dir ) )
            {
                expOdfSandbox::signal( $pid, $dir, 15 );
                usleep( 500000 );
                if ( expOdfSandbox::isOurs( $pid, $dir ) )
                    expOdfSandbox::signal( $pid, $dir, 9 );
                $killed++;
            }
            $hb = expOdfPaths::readJson( $dir . '/heartbeat.json' );
            if ( $hb )
            {
                $hb['status'] = 'stopped';
                expOdfPaths::writeJson( $dir . '/heartbeat.json', $hb );
            }
        }
        $say( $killed ? "$killed process(es) ended" : 'stopped' );
        return array( 'ok' => !self::running(), 'message' => 'The conversion server is stopped.' );
    }

    /** @return array the outcome of a restart */
    public static function restart( $say = null )
    {
        self::stop( $say );
        return self::start( $say );
    }

    /**
     * The cron part: brings the server up when it is turned on and not running.
     * @return string what was done
     */
    public static function keepalive( $say = null )
    {
        if ( !self::enabled() )
            return 'off';
        if ( self::running() )
            return 'running';
        if ( count( self::recentStarts() ) >= 3 )
            return 'not started: three starts in ten minutes failed (see the log of the Document converter page)';
        $r = self::start( $say );
        return ( $r['ok'] ? 'started: ' : 'start failed: ' ) . $r['message'];
    }

    protected static function noteStart()
    {
        $file = expOdfPaths::base() . '/server/starts.json';
        $starts = array_values( array_filter( expOdfPaths::readJson( $file ), function ( $t ) { return $t > time() - 600; } ) );
        $starts[] = time();
        expOdfPaths::writeJson( $file, $starts );
    }

    /** @return int[] the start times of the last ten minutes */
    public static function recentStarts()
    {
        return array_values( array_filter( expOdfPaths::readJson( expOdfPaths::base() . '/server/starts.json' ), function ( $t ) { return $t > time() - 600; } ) );
    }

    /** Forgets the recent starts (an administrator pressed Start). */
    public static function clearStarts()
    {
        expOdfPaths::writeJson( expOdfPaths::base() . '/server/starts.json', array() );
    }

    /** @return string[] the last lines of the server logs, newest last */
    public static function log( $lines = 80 )
    {
        $out = array();
        foreach ( glob( expOdfPaths::base() . '/server/slot*/server.log' ) ?: array() as $file )
        {
            $slot = basename( dirname( $file ) );
            foreach ( array_slice( file( $file, FILE_IGNORE_NEW_LINES ) ?: array(), -$lines ) as $line )
                $out[] = substr( $line, 0, 19 ) . ' ' . $slot . substr( $line, 19 );
        }
        sort( $out );
        return array_slice( $out, -$lines );
    }
}
