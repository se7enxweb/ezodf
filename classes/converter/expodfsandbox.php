<?php
/**
 * File containing the expOdfSandbox class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Runs LibreOffice (and ezodf's worker script) in a sandbox:
 *
 *   bubblewrap (bwrap) --unshare-all --die-with-parent --new-session: no network, own PID/IPC/UTS namespaces;
 *     read-only /usr, /etc/fonts and the LibreOffice installation; a private tmpfs /tmp and $HOME; only the
 *     directories of the job bound writable. Nothing else of the installation is visible.
 *   timeout -s KILL and prlimit (address space, file size, processes, CPU) around it.
 *
 * Without bwrap on the host the same command runs with timeout and prlimit only, and status() says that the
 * network is not isolated. Commands are argument lists (proc_open without a shell); every executable is a fixed
 * system tool, the LibreOffice binary of a detected or verified installation, or the installation's Python with
 * ezodf's own worker script.
 */
class expOdfSandbox
{
    const BWRAP = '/usr/bin/bwrap';

    /** @var array|null the result of the bwrap probe of this process */
    protected static $probe = null;

    /** @return string the path of a system tool, '' when missing */
    public static function tool( $name )
    {
        foreach ( array( '/usr/bin/', '/bin/', '/usr/sbin/', '/usr/local/bin/' ) as $dir )
            if ( is_file( $dir . $name ) && is_executable( $dir . $name ) )
                return $dir . $name;
        return '';
    }

    /**
     * Whether bwrap can build a sandbox on this host for this user (probed once, cached for an hour).
     * @return array( 'available' => bool, 'isolated' => bool, 'message' => string )
     */
    public static function probe( $force = false )
    {
        if ( self::$probe !== null && !$force )
            return self::$probe;
        $cache = expOdfPaths::base() . '/server/sandbox-' . self::userName() . '.json';
        $data = expOdfPaths::readJson( $cache );
        if ( !$force && $data && isset( $data['time'] ) && $data['time'] > time() - 3600 )
            return self::$probe = $data;
        $bwrap = self::tool( 'bwrap' );
        if ( $bwrap === '' )
            $data = array( 'available' => false, 'isolated' => false, 'message' => 'bubblewrap (bwrap) is not installed: conversions run with time and memory limits, but not isolated from the network.' );
        else
        {
            $true = self::tool( 'true' );
            $r = self::run( array_merge( array( $bwrap ), self::bwrapBase( array(), array() ), array( $true ) ), 10 );
            $data = $r['code'] === 0
                  ? array( 'available' => true, 'isolated' => true, 'message' => 'bubblewrap: no network, own process space, only the job directory writable.' )
                  : array( 'available' => false, 'isolated' => false, 'message' => 'bubblewrap cannot create a sandbox here (' . trim( $r['stderr'] ) . '): conversions run with time and memory limits only.' );
        }
        $data['time'] = time();
        expOdfPaths::writeJson( $cache, $data );
        return self::$probe = $data;
    }

    /** @return string the user this process runs as */
    public static function userName()
    {
        if ( function_exists( 'posix_geteuid' ) && function_exists( 'posix_getpwuid' ) )
        {
            $pw = posix_getpwuid( posix_geteuid() );
            if ( $pw && isset( $pw['name'] ) )
                return preg_replace( '/[^a-z0-9_.-]/i', '', $pw['name'] );
        }
        return 'user';
    }

    /**
     * The bwrap arguments: the read-only system, a private /tmp and $HOME, and the given directories.
     *
     * @param string[] $readOnly directories visible read-only at the same path
     * @param string[] $writable directories visible writable at the same path
     * @return string[]
     */
    public static function bwrapBase( array $readOnly, array $writable )
    {
        $args = array( '--ro-bind', '/usr', '/usr' );
        foreach ( array( 'lib64', 'lib', 'bin', 'sbin' ) as $link )
            if ( is_link( '/' . $link ) )
                array_push( $args, '--symlink', 'usr/' . $link, '/' . $link );
            elseif ( is_dir( '/' . $link ) )
                array_push( $args, '--ro-bind', '/' . $link, '/' . $link );
        foreach ( array( '/etc/fonts', '/etc/alternatives', '/etc/ld.so.cache', '/etc/localtime', '/etc/pki/tls/certs', '/etc/ssl/certs' ) as $path )
            if ( file_exists( $path ) )
                array_push( $args, '--ro-bind', $path, $path );
        foreach ( array_unique( $readOnly ) as $dir )
            if ( $dir !== '' && file_exists( $dir ) && strpos( $dir . '/', '/usr/' ) !== 0 )
                array_push( $args, '--ro-bind', $dir, $dir );
        array_push( $args, '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--dir', '/tmp/home',
                    '--setenv', 'HOME', '/tmp/home', '--setenv', 'TMPDIR', '/tmp', '--unsetenv', 'DISPLAY',
                    '--setenv', 'SAL_USE_VCLPLUGIN', 'svp' );
        foreach ( array_unique( $writable ) as $dir )
            if ( $dir !== '' && is_dir( $dir ) )
                array_push( $args, '--bind', $dir, $dir );
        array_push( $args, '--unshare-all', '--die-with-parent', '--new-session' );
        return $args;
    }

    /**
     * The command line of one sandboxed call.
     *
     * @param string[] $command the program and its arguments
     * @param string[] $readOnly
     * @param string[] $writable
     * @param array $limits seconds, memory (MB), fsize (MB), nproc, cpu (s); seconds 0 = no time limit (the server)
     * @return string[]
     */
    public static function command( array $command, array $readOnly, array $writable, array $limits = array() )
    {
        $limits += array( 'seconds' => 90, 'memory' => 4096, 'fsize' => 1024, 'nproc' => 512, 'cpu' => 0 );
        $out = array();
        $timeout = self::tool( 'timeout' );
        if ( $limits['seconds'] > 0 && $timeout !== '' )
            array_push( $out, $timeout, '-s', 'KILL', (string)(int)$limits['seconds'] );
        $prlimit = self::tool( 'prlimit' );
        if ( $prlimit !== '' )
        {
            $out[] = $prlimit;
            $out[] = '--as=' . ( (int)$limits['memory'] * 1048576 );
            $out[] = '--fsize=' . ( (int)$limits['fsize'] * 1048576 );
            $out[] = '--nproc=' . (int)$limits['nproc'];
            if ( $limits['cpu'] > 0 )
                $out[] = '--cpu=' . (int)$limits['cpu'];
            $out[] = '--';
        }
        $probe = self::probe();
        if ( !empty( $probe['available'] ) )
        {
            $out[] = self::tool( 'bwrap' );
            if ( isset( $limits['info_fd'] ) )
                array_push( $out, '--info-fd', (string)(int)$limits['info_fd'] );
            $out = array_merge( $out, self::bwrapBase( $readOnly, $writable ) );
        }
        return array_merge( $out, $command );
    }

    /**
     * Runs a command without a shell and waits for it.
     *
     * @param string[] $command
     * @param int $seconds kill it after this long (0 = wait)
     * @param string|null $cwd
     * @return array( 'code' => int, 'stdout' => string, 'stderr' => string, 'seconds' => float, 'timedout' => bool )
     */
    public static function run( array $command, $seconds = 120, $cwd = null, array $env = null )
    {
        $start = microtime( true );
        $spec = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
        $proc = @proc_open( $command, $spec, $pipes, $cwd, $env );
        if ( !is_resource( $proc ) )
            return array( 'code' => 127, 'stdout' => '', 'stderr' => 'cannot start ' . basename( (string)$command[0] ), 'seconds' => 0.0, 'timedout' => false );
        stream_set_blocking( $pipes[1], false );
        stream_set_blocking( $pipes[2], false );
        $stdout = $stderr = '';
        $timedOut = false;
        $code = -1;
        while ( true )
        {
            $read = array( $pipes[1], $pipes[2] );
            $w = $e = null;
            @stream_select( $read, $w, $e, 0, 200000 );
            $stdout .= (string)stream_get_contents( $pipes[1] );
            $stderr .= (string)stream_get_contents( $pipes[2] );
            if ( strlen( $stdout ) > 1048576 )
                $stdout = substr( $stdout, -1048576 );
            if ( strlen( $stderr ) > 1048576 )
                $stderr = substr( $stderr, -1048576 );
            $status = proc_get_status( $proc );
            if ( !$status['running'] )
            {
                $code = (int)$status['exitcode'];
                break;
            }
            if ( $seconds > 0 && microtime( true ) - $start > $seconds )
            {
                $timedOut = true;
                proc_terminate( $proc, 9 );
                usleep( 100000 );
                break;
            }
        }
        $stdout .= (string)stream_get_contents( $pipes[1] );
        $stderr .= (string)stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );
        $close = proc_close( $proc );
        if ( $code === -1 )
            $code = $timedOut ? 137 : $close;
        return array( 'code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'seconds' => round( microtime( true ) - $start, 3 ),
                      'timedout' => $timedOut || $code === 137 );
    }

    /**
     * Starts a command in a session of its own that outlives this process (the conversion server). bwrap's
     * --info-fd writes the sandbox's process id to $infoFile.
     *
     * @return bool whether the command was started
     */
    public static function spawn( array $command, $logFile, $infoFile = null )
    {
        $setsid = self::tool( 'setsid' );
        if ( $setsid !== '' )
            $command = array_merge( array( $setsid, '-f' ), $command );
        $spec = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $logFile, 'a' ), 2 => array( 'file', $logFile, 'a' ) );
        if ( $infoFile !== null )
            $spec[3] = array( 'file', $infoFile, 'w' );
        $proc = @proc_open( $command, $spec, $pipes );
        if ( !is_resource( $proc ) )
            return false;
        // setsid -f returns at once; without setsid the child stays ours until it ends (the caller's process ends first)
        if ( $setsid !== '' )
            proc_close( $proc );
        return true;
    }

    /** @return bool whether a process runs and its command line contains $needle (never signals a stranger) */
    public static function isOurs( $pid, $needle )
    {
        $pid = (int)$pid;
        if ( $pid <= 1 || !is_readable( "/proc/$pid/cmdline" ) )
            return false;
        $cmd = str_replace( "\0", ' ', (string)@file_get_contents( "/proc/$pid/cmdline" ) );
        return $needle !== '' && strpos( $cmd, $needle ) !== false;
    }

    /** Sends a signal to a process that is ours. */
    public static function signal( $pid, $needle, $signal = 15 )
    {
        if ( !self::isOurs( $pid, $needle ) || !function_exists( 'posix_kill' ) )
            return false;
        return posix_kill( (int)$pid, $signal );
    }
}
