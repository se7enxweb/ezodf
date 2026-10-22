<?php
/**
 * File containing the expOdfConverterTest class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The test conversions of the Document converter page: each bundled sample (share/samples) is converted with the
 * converter as it is set up (its backends in order), the results are kept in var/<site>/ezodf/test/ for download
 * and listed with the backend, the filter, the time and the size. Runs as the job ezodf.test.
 */
class expOdfConverterTest
{
    /** sample => target; the default set covers text, spreadsheet, presentation and the OpenOffice.org 1.x format */
    const SAMPLES = array(
        'sample.odt' => 'pdf', 'sample.docx' => 'odt', 'sample.doc' => 'docx', 'sample.rtf' => 'odt',
        'sample.xlsx' => 'ods', 'sample.ods' => 'pdf', 'sample.pptx' => 'pdf', 'sample.odp' => 'pptx', 'sample.sxw' => 'odt',
    );

    /** @return string */
    public static function dir()
    {
        return expOdfPaths::dir( 'test' );
    }

    /** @return array the last results ( time, results: list ) */
    public static function last()
    {
        return expOdfPaths::readJson( self::dir() . '/results.json' );
    }

    /**
     * @param string[] $samples sample names; empty = all
     * @return array
     */
    public static function run( array $samples = array(), $progress = null )
    {
        $progress = $progress ?: function () {};
        $list = self::SAMPLES;
        if ( $samples )
            $list = array_intersect_key( $list, array_flip( $samples ) );
        $dir = self::dir();
        foreach ( glob( $dir . '/result-*' ) ?: array() as $old )
            @unlink( $old );
        $results = array();
        $n = 0;
        foreach ( $list as $sample => $target )
        {
            $n++;
            $progress( $n - 1, count( $list ), "converting $sample to $target" );
            $file = expOdfPaths::extension() . '/share/samples/' . $sample;
            $row = array( 'sample' => $sample, 'target' => $target, 'ok' => false );
            try
            {
                $r = expOdfConverter::convert( $file, $target );
                $name = 'result-' . pathinfo( $sample, PATHINFO_FILENAME ) . '-' . pathinfo( $sample, PATHINFO_EXTENSION ) . '.' . $target;
                copy( $r['file'], $dir . '/' . $name );
                $row = array( 'ok' => true, 'file' => $name, 'size' => filesize( $dir . '/' . $name ), 'backend' => $r['backend'],
                              'filter' => $r['filter'], 'seconds' => $r['seconds'], 'version' => $r['version'] ) + $row;
                expOdfConverter::cleanup( $r );
            }
            catch ( Exception $e )
            {
                $row['error'] = $e->getMessage();
                $row['code'] = $e instanceof expOdfConverterException ? $e->code() : 'error';
            }
            $results[] = $row;
        }
        $ok = count( array_filter( $results, function ( $r ) { return $r['ok']; } ) );
        $data = array( 'time' => time(), 'results' => $results, 'passed' => $ok, 'total' => count( $results ) );
        expOdfPaths::writeJson( $dir . '/results.json', $data );
        $progress( count( $list ), count( $list ), "$ok of " . count( $results ) . ' conversions passed' );
        return array( 'message' => "$ok of " . count( $results ) . ' test conversions passed', 'passed' => $ok, 'total' => count( $results ) );
    }
}
