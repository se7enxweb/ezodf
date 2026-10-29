<?php
/**
 * File containing the expOdfImportDocumentFeedSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * "Documents of a feed" of 7x SQLI Import: an RSS or Atom feed whose items link to documents (enclosures, or links
 * ending in a document extension: a publications list, a press release feed, a podcast's transcripts). Once per run
 * the feed and each document are downloaded (SSRF-safe, see expOdfRemoteFetcher) and every document is a record
 * with the item's title, link, date and summary next to the document's own fields (item_title, item_link,
 * item_date, item_summary). With a schedule only what is new or changed is imported again (the record id is the
 * document address).
 */
class expOdfImportDocumentFeedSource extends expOdfImportDocumentUrlsSource
{
    public static function identifier()
    {
        return 'document_feed';
    }

    public static function label()
    {
        return 'Documents of a feed (RSS, Atom)';
    }

    public static function description()
    {
        return 'An RSS or Atom feed whose items link to documents (enclosures or links to .pdf, .docx, .odt ...): every '
             . 'document becomes a record with its own fields and the item\'s title, link, date and summary.';
    }

    public static function schema()
    {
        return array(
            'feed' => array( 'type' => 'url', 'label' => 'Feed address', 'required' => true ),
            'max_items' => array( 'type' => 'int', 'label' => 'Items', 'default' => 50, 'min' => 1, 'max' => 1000, 'help' => 'The newest items read per run' ),
        ) + expImportAbstractSource::commonSchema();
    }

    public static function fetch( array $options, $target, $limit )
    {
        $dir = dirname( $target ) . '/downloads';
        expOdfPaths::ensure( $dir );
        $feed = expOdfRemoteFetcher::fetch( (string)$options['feed'], $dir );
        $items = self::items( (string)file_get_contents( $feed['file'] ), isset( $options['max_items'] ) ? (int)$options['max_items'] : 50 );
        $docs = self::documentsOf( $items, $dir );
        self::writeSnapshot( $docs, $target, $limit );
    }

    /**
     * The items of a feed with the document addresses they link to.
     * @return array list of array( title, link, date, summary, documents => string[] )
     */
    public static function items( $xml, $max = 50 )
    {
        $dom = new DOMDocument();
        if ( !@$dom->loadXML( preg_replace( '/<!DOCTYPE[^>]*>/i', '', (string)$xml ), LIBXML_NONET ) )
            throw new expImportSourceException( 'the feed is not valid XML' );
        $exts = expOdfImportDocumentSource::documentExtensions();
        $isDoc = function ( $url ) use ( $exts ) {
            $ext = strtolower( pathinfo( (string)parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
            return in_array( $ext, $exts, true );
        };
        $out = array();
        $xp = new DOMXPath( $dom );
        $xp->registerNamespace( 'a', 'http://www.w3.org/2005/Atom' );
        $nodes = $xp->query( '//item | //a:entry' );
        foreach ( $nodes as $item )
        {
            $get = function ( $q ) use ( $xp, $item ) { $n = $xp->query( $q, $item ); return $n->length ? trim( $n->item( 0 )->textContent ) : ''; };
            $docs = array();
            foreach ( $xp->query( 'enclosure/@url | a:link[@rel="enclosure"]/@href', $item ) as $a )
                $docs[] = trim( $a->value );
            $link = $get( 'link' ) ?: ( ( $l = $xp->query( 'a:link[not(@rel) or @rel="alternate"]/@href', $item ) ) && $l->length ? trim( $l->item( 0 )->value ) : '' );
            if ( $link !== '' && $isDoc( $link ) )
                $docs[] = $link;
            $docs = array_values( array_unique( array_filter( $docs, $isDoc ) ) );
            if ( !$docs )
                continue;
            $out[] = array( 'title' => $get( 'title' ) ?: $get( 'a:title' ), 'link' => $link,
                            'date' => $get( 'pubDate' ) ?: ( $get( 'a:updated' ) ?: $get( 'a:published' ) ),
                            'summary' => $get( 'description' ) ?: $get( 'a:summary' ), 'documents' => $docs );
            if ( count( $out ) >= $max )
                break;
        }
        return $out;
    }

    /** @return Generator */
    protected static function documentsOf( array $items, $dir )
    {
        foreach ( $items as $item )
            foreach ( $item['documents'] as $url )
            {
                $extra = array( 'item_title' => $item['title'], 'item_link' => $item['link'], 'item_date' => $item['date'],
                                'item_summary' => trim( strip_tags( $item['summary'] ) ), 'source_url' => $url, 'id' => sha1( $url ) );
                try
                {
                    $f = expOdfRemoteFetcher::fetch( $url, $dir );
                    yield array( 'file' => $f['file'], 'path' => $f['name'] ) + $extra;
                }
                catch ( expOdfConverterException $e )
                {
                    yield array( 'path' => basename( (string)parse_url( $url, PHP_URL_PATH ) ) ?: $url, 'error' => $url . ': ' . $e->getMessage() ) + $extra;
                }
            }
    }
}
