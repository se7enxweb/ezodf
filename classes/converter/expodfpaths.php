<?php
/**
 * File containing the expOdfPaths class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Where ezodf 7 keeps its files, all below the site's var directory (var/<site>/ezodf/):
 *
 *   settings.json          the settings the administration changed (expOdfSettings)
 *   libreoffice/<version>/ a LibreOffice build installed from the admin; libreoffice/install.json its state
 *   server/                the conversion server: heartbeat.json, server.log, sandbox.json, profile/
 *   spool/<job>/           conversions handed to the server
 *   work/<job>/            conversions of the per-call mode, and the import's work directories
 *   batches/<id>/          uploaded batches (files, folders, ZIPs) and their run file
 *   jobs.json              the last background job of each kind (for the status panels)
 *
 * Every directory is created with the group of the site and setgid, so the web server (php-fpm), Velocity (root) and
 * the job worker can all write where they have to.
 */
class expOdfPaths
{
    /** @var string|null a base directory set by tests */
    public static $baseOverride = null;

    /** @return string the absolute base directory */
    public static function base()
    {
        if ( self::$baseOverride !== null )
            return rtrim( self::$baseOverride, '/' );
        $var = class_exists( 'eZSys' ) ? eZSys::varDirectory() : 'var';
        $dir = $var[0] === '/' ? $var : self::root() . '/' . $var;
        return rtrim( $dir, '/' ) . '/ezodf';
    }

    /** @return string the installation's root directory */
    public static function root()
    {
        if ( class_exists( 'eZSys' ) && method_exists( 'eZSys', 'rootDir' ) )
        {
            $root = eZSys::rootDir();
            if ( $root )
                return rtrim( $root, '/' );
        }
        return rtrim( getcwd(), '/' );
    }

    /** @return string the extension's directory */
    public static function extension()
    {
        return dirname( __DIR__, 2 );
    }

    /** @return string a sub directory of the base, created */
    public static function dir( $name )
    {
        $dir = self::base() . '/' . trim( $name, '/' );
        self::ensure( $dir );
        return $dir;
    }

    /** Creates a directory (and its parents) writable for the site's group. */
    public static function ensure( $dir, $mode = 02775 )
    {
        if ( is_dir( $dir ) )
            return true;
        $old = umask( 0002 );
        $ok = @mkdir( $dir, $mode, true ) || is_dir( $dir );
        umask( $old );
        if ( $ok )
            @chmod( $dir, $mode );
        return $ok;
    }

    /** @return string a new, empty directory for one job below $name (work, spool, batches ...) */
    public static function jobDir( $name, $mode = 02775 )
    {
        $dir = self::dir( $name ) . '/' . date( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 8 ) );
        self::ensure( $dir, $mode );
        return $dir;
    }

    /**
     * Removes a directory below the base and everything in it (never a path outside the base, never by a symlink).
     * @return bool
     */
    public static function remove( $dir )
    {
        $base = realpath( self::base() );
        $real = realpath( $dir );
        if ( $base === false || $real === false || strpos( $real . '/', $base . '/' ) !== 0 || $real === $base )
            return false;
        if ( is_link( $dir ) || !is_dir( $real ) )
            return @unlink( $dir );
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
                                                RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $items as $item )
        {
            if ( $item->isDir() && !$item->isLink() )
                @rmdir( $item->getPathname() );
            else
                @unlink( $item->getPathname() );
        }
        return @rmdir( $real );
    }

    /** @return int the bytes a directory takes (files only) */
    public static function size( $dir )
    {
        if ( !is_dir( $dir ) )
            return 0;
        $total = 0;
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) ) as $item )
            if ( $item->isFile() && !$item->isLink() )
                $total += $item->getSize();
        return $total;
    }

    /** Writes a JSON file atomically (readable by the site's group). */
    public static function writeJson( $file, array $data )
    {
        self::ensure( dirname( $file ) );
        $tmp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
        file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        @chmod( $tmp, 0664 );
        return rename( $tmp, $file );
    }

    /** @return array the JSON of a file, array() when missing or broken */
    public static function readJson( $file )
    {
        // written by another process (the conversion server, a job): in a Velocity worker an earlier answer about the
        // file is remembered for the request until clearstatcache()
        clearstatcache( true, $file );
        if ( !is_file( $file ) )
            return array();
        $data = json_decode( (string)@file_get_contents( $file ), true );
        return is_array( $data ) ? $data : array();
    }
}
