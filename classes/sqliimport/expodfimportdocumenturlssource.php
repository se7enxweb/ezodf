<?php
/**
 * File containing the expOdfImportDocumentUrlsSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * "Documents from URLs" of 7x SQLI Import: a list of addresses of documents (one per line). Once per run the source
 * downloads each document (public http and https addresses only, the checked address pinned, size and time limits:
 * expOdfRemoteFetcher) and converts it into a document record (expOdfImportDocumentSource), with source_url.
 * An address that is refused or cannot be read is a failed row of the run.
 */
class expOdfImportDocumentUrlsSource extends expOdfImportDocumentSource
{
    public static function identifier()
    {
        return 'document_urls';
    }

    public static function label()
    {
        return 'Documents from URLs';
    }

    public static function extensions()
    {
        return array();
    }

    public static function origins()
    {
        return array( 'remote' );
    }

    public static function description()
    {
        return 'Documents on the web, one address per line: each is downloaded once per run (public addresses only, with '
             . 'size and time limits) and becomes a record with its title, summary, body as HTML with its images, and '
             . 'source_url. With a schedule a changed document is imported again.';
    }

    public static function schema()
    {
        return array(
            'urls' => array( 'type' => 'text', 'label' => 'Addresses', 'required' => true, 'help' => 'One http or https address of a document per line' ),
        ) + expImportAbstractSource::commonSchema();
    }

    public static function preset()
    {
        $p = parent::preset();
        unset( $p['location'] );
        $p['match'] = array( 'type' => 'remote_id', 'pattern' => 'docurl-{id}' );
        return $p;
    }

    public static function fetch( array $options, $target, $limit )
    {
        $urls = array();
        foreach ( preg_split( '/[\r\n]+/', isset( $options['urls'] ) ? (string)$options['urls'] : '' ) as $line )
            if ( ( $u = trim( $line ) ) !== '' && $u[0] !== '#' )
                $urls[] = $u;
        self::writeSnapshot( self::downloads( $urls, dirname( $target ) . '/downloads' ), $target, $limit );
    }

    /** @return Generator of documents (file, path, source_url) or errors */
    protected static function downloads( array $urls, $dir )
    {
        expOdfPaths::ensure( $dir );
        foreach ( $urls as $url )
        {
            try
            {
                $f = expOdfRemoteFetcher::fetch( $url, $dir );
                yield array( 'file' => $f['file'], 'path' => $f['name'], 'source_url' => $url, 'id' => sha1( $url ) );
            }
            catch ( expOdfConverterException $e )
            {
                yield array( 'path' => basename( (string)parse_url( $url, PHP_URL_PATH ) ) ?: $url, 'source_url' => $url, 'id' => sha1( $url ), 'error' => $url . ': ' . $e->getMessage() );
            }
        }
    }
}
