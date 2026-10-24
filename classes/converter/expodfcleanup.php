<?php
/**
 * File containing the expOdfCleanup class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Removes what ezodf leaves behind: work and spool directories of conversions (older than an hour: a conversion
 * never takes that long), uploaded batches and their runs older than retention.keep_days, and the files of the
 * background jobs of installations without expqueue. The button "Clean up now" and the cron part call it.
 */
class expOdfCleanup
{
    /**
     * @param bool $dryRun only count
     * @param int $minInterval seconds since the last cleanup before it runs again (0 = always)
     * @return array( 'items' => int, 'bytes' => int, 'skipped' => bool )
     */
    public static function run( $dryRun = false, $minInterval = 0 )
    {
        $stamp = expOdfPaths::base() . '/cleanup.json';
        $last = expOdfPaths::readJson( $stamp );
        if ( $minInterval && isset( $last['time'] ) && $last['time'] > time() - $minInterval )
            return array( 'items' => 0, 'bytes' => 0, 'skipped' => true );
        $items = $bytes = 0;
        $keep = expOdfSettings::get( 'retention.keep_days' ) * 86400;
        $rules = array( 'work' => 3600, 'spool' => 3600, 'batches' => $keep, 'exports' => $keep );
        foreach ( $rules as $dir => $age )
        {
            foreach ( glob( expOdfPaths::base() . '/' . $dir . '/*', GLOB_ONLYDIR ) ?: array() as $sub )
            {
                if ( filemtime( $sub ) > time() - $age || ( $dir === 'batches' && self::batchActive( $sub ) ) )
                    continue;
                $items++;
                $bytes += expOdfPaths::size( $sub );
                if ( !$dryRun )
                    expOdfPaths::remove( $sub );
            }
        }
        foreach ( glob( expOdfPaths::base() . '/jobs/*.json' ) ?: array() as $file )
        {
            if ( filemtime( $file ) > time() - $keep )
                continue;
            $items++;
            $bytes += filesize( $file );
            if ( !$dryRun )
                @unlink( $file );
        }
        if ( !$dryRun )
            expOdfPaths::writeJson( $stamp, array( 'time' => time(), 'items' => $items, 'bytes' => $bytes ) );
        return array( 'items' => $items, 'bytes' => $bytes, 'skipped' => false );
    }

    /** @return bool whether a batch still has documents waiting or running */
    protected static function batchActive( $dir )
    {
        $run = expOdfPaths::readJson( $dir . '/run.json' );
        return isset( $run['state'] ) && in_array( $run['state'], array( 'queued', 'running', 'uploading' ), true );
    }

    /** @return array what a cleanup would remove now, and the space ezodf takes */
    public static function usage()
    {
        $out = array();
        foreach ( array( 'libreoffice', 'batches', 'work', 'spool', 'test', 'server', 'exports' ) as $dir )
            $out[$dir] = expOdfPaths::size( expOdfPaths::base() . '/' . $dir );
        $out['pending'] = self::run( true );
        return $out;
    }
}
