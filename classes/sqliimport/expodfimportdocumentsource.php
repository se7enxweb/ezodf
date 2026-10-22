<?php
/**
 * File containing the expOdfImportDocumentSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The document formats of 7x SQLI Import (sqliimport 2.x), declared by ezodf in its kernelservices.ini with the tag
 * sqliimport.source; loaded only by sqliimport's registry, where expImportJsonSource exists. ezodf converts, 7x SQLI
 * Import maps: a document becomes a record whose fields a mapping puts into any class, with sqliimport's dry run,
 * undo, schedules, statistics and run history.
 *
 * This class is "Documents (one file or a folder as ZIP)": every document of the upload (Word, OpenDocument,
 * OpenOffice.org 1.x and StarOffice, RTF, PDF, Markdown, HTML, text, spreadsheets, presentations) is one record, and
 * with the option folders every folder of a ZIP is a record of its own (kind folder), so a mapping can rebuild the
 * tree (the parent of a record is parent_path; folders come before their documents).
 *
 * A document record:
 *   id, kind (document | folder), path, folder, parent_path, name, title, summary, html (the body; images as
 *   <img src="run:docs/..."> files the run keeps), text, headings, images (list of run: references), keywords,
 *   author, created, modified, language, pages, format, size, file (the original: "source:<path>" in an uploaded
 *   ZIP, "run:<path>" for a file the source downloaded), source_url, converter
 * A document that cannot be read is a record with _error: a failed row of the run, the others go on.
 * The remote kinds (URLs, feed, WebDAV and Nextcloud, Google Drive, OneDrive, mailbox) extend this class and read
 * their documents themselves (origin remote), writing the same records into the run's snapshot.
 */
class expOdfImportDocumentSource extends expImportJsonSource
{
    public static function identifier()
    {
        return 'documents';
    }

    public static function label()
    {
        return 'Documents (one file or a folder as ZIP)';
    }

    public static function extensions()
    {
        return array_values( array_unique( array_merge( array( 'zip' ), self::documentExtensions() ) ) );
    }

    public static function group()
    {
        return 'content';
    }

    public static function origins()
    {
        return array( 'upload', 'url', 'path' );
    }

    public static function description()
    {
        return 'Word, OpenDocument, older OpenOffice.org and StarOffice files, RTF, PDF, Markdown, HTML, spreadsheets and '
             . 'presentations, one file or a whole folder as ZIP. Every document is a record with its title, summary, body '
             . 'as HTML with its images, keywords, author and dates; every folder of the ZIP is a record too, so a mapping '
             . 'can rebuild the tree. Converted by ezodf (LibreOffice in its sandbox where installed).';
    }

    public static function examples()
    {
        return array();
    }

    public static function schema()
    {
        return array(
            'folders' => array( 'type' => 'bool', 'label' => 'Folders as records', 'default' => true,
                                'help' => 'Every folder of a ZIP is a record (kind folder) before its documents' ),
            'skip' => array( 'type' => 'string', 'label' => 'Leave out', 'default' => '',
                             'help' => 'Extensions to leave out, comma separated (e.g. txt, csv)' ),
        ) + expImportAbstractSource::commonSchema();
    }

    public static function preset()
    {
        return array(
            'class' => 'article',
            'match' => array( 'type' => 'remote_id', 'pattern' => 'doc-{id}' ),
            'fields' => array(
                array( 'source' => 'title', 'attribute' => 'title' ),
                array( 'source' => 'summary', 'attribute' => 'intro', 'transforms' => array( array( 'name' => 'richtext' ) ) ),
                array( 'source' => 'html', 'attribute' => 'body', 'transforms' => array( array( 'name' => 'richtext' ) ) ),
                array( 'source' => 'modified', 'attribute' => 'publish_date', 'transforms' => array( array( 'name' => 'date' ) ), 'datatypes' => array( 'ezdate', 'ezdatetime' ) ),
                array( 'source' => 'keywords', 'attribute' => 'tags', 'transforms' => array( array( 'name' => 'join', 'args' => array( 'separator' => ', ' ) ) ), 'datatypes' => array( 'ezkeyword', 'eztags' ) ),
            ),
            'location' => array( 'parent_from' => 'parent_path', 'parent_prefix' => 'doc-' ),
        );
    }

    protected function format()
    {
        return 'ndjson';
    }

    /** @return string[] the extensions ezodf imports as documents */
    public static function documentExtensions()
    {
        $out = array();
        if ( class_exists( 'eZINI' ) )
        {
            $ini = eZINI::instance( 'odf.ini' );
            $out = array_merge( (array)$ini->variable( 'DocumentType', 'AllowedTypes' ), (array)$ini->variable( 'DocumentType', 'ConvertTypes' ) );
        }
        return array_values( array_filter( array_unique( $out ), 'strlen' ) );
    }

    /**
     * The records of an uploaded document or ZIP (origin upload, url or path); the remote kinds read their
     * snapshot as NDJSON.
     */
    public function records( array $cursor = array() )
    {
        if ( in_array( 'remote', static::origins(), true ) )
        {
            foreach ( parent::records( $cursor ) as $record )
                yield $record;
            return;
        }
        $file = $this->file();
        $assets = dirname( $file ) . '/docs';
        $skip = isset( $cursor['index'] ) ? (int)$cursor['index'] : 0;
        $index = 0;
        foreach ( $this->entries( $file ) as $entry )
        {
            if ( $index < $skip )
            {
                $index++;
                continue;
            }
            if ( $entry['kind'] === 'folder' )
                $data = self::folderRecord( $entry['path'] );
            else
            {
                $local = $entry['local'];
                if ( $local === null )
                    $local = self::extractMember( $file, $entry['path'], $assets );
                $data = self::documentRecord( $local, $entry['path'], $assets, array( 'file' => $entry['ref'] ) );
            }
            yield new expImportRecord( $this->refOf( $data, $index ), $index, $data, array( 'index' => $index + 1 ) );
            $index++;
        }
    }

    public function count()
    {
        if ( in_array( 'remote', static::origins(), true ) )
            return parent::count();
        return count( $this->entries( $this->file() ) );
    }

    /**
     * The entries of the upload in their order: folders (with the option) before their documents.
     * @return array list of array( kind, path, local (a file to read, or null: a ZIP member), ref )
     */
    protected function entries( $file )
    {
        $skip = array_filter( array_map( 'trim', explode( ',', strtolower( isset( $this->config['skip'] ) ? (string)$this->config['skip'] : '' ) ) ), 'strlen' );
        $docs = array_diff( self::documentExtensions(), $skip );
        if ( strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) !== 'zip' )
        {
            $name = isset( $this->config['name'] ) && $this->config['name'] !== '' ? (string)$this->config['name'] : basename( $file );
            return array( array( 'kind' => 'document', 'path' => $name, 'local' => $file, 'ref' => 'source:' ) );
        }
        $zip = new ZipArchive();
        if ( $zip->open( $file, ZipArchive::RDONLY ) !== true )
            throw new expImportSourceException( 'the ZIP archive cannot be opened' );
        if ( class_exists( 'eZOOImport' ) && eZOOImport::zipEntriesProblem( $zip ) )
        {
            $zip->close();
            throw new expImportSourceException( 'the ZIP archive has entries that cannot be read safely' );
        }
        $paths = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ )
        {
            $name = (string)$zip->getNameIndex( $i );
            if ( substr( $name, -1 ) === '/' || strpos( $name, '__MACOSX/' ) === 0 || preg_match( '#(^|/)\.#', $name ) || strpos( $name, '..' ) !== false )
                continue;
            if ( in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), $docs, true ) )
                $paths[] = $name;
        }
        $zip->close();
        sort( $paths, SORT_NATURAL | SORT_FLAG_CASE );
        $out = array();
        $seen = array();
        $folders = !isset( $this->config['folders'] ) || !empty( $this->config['folders'] );
        foreach ( $paths as $path )
        {
            if ( $folders )
            {
                $dir = dirname( $path );
                $chain = array();
                while ( $dir !== '.' && $dir !== '' && !isset( $seen[$dir] ) )
                {
                    $chain[] = $dir;
                    $seen[$dir] = true;
                    $dir = dirname( $dir );
                }
                foreach ( array_reverse( $chain ) as $folder )
                    $out[] = array( 'kind' => 'folder', 'path' => $folder, 'local' => null, 'ref' => '' );
            }
            $out[] = array( 'kind' => 'document', 'path' => $path, 'local' => null, 'ref' => 'source:' . $path );
        }
        return $out;
    }

    /** @return string a member of the ZIP as a file below $assets */
    protected static function extractMember( $zipFile, $member, $assets )
    {
        $dir = $assets . '/' . substr( sha1( $member ), 0, 16 );
        expOdfPaths::ensure( $dir );
        $target = $dir . '/' . expOdfRemoteFetcher::safeName( basename( $member ) );
        if ( is_file( $target ) )
            return $target;
        $zip = new ZipArchive();
        $zip->open( $zipFile, ZipArchive::RDONLY );
        $in = $zip->getStream( $member );
        $out = fopen( $target, 'wb' );
        $copied = $in && $out ? stream_copy_to_stream( $in, $out, expOdfSettings::get( 'converter.max_megabytes' ) * 1048576 + 1 ) : false;
        if ( $in )
            fclose( $in );
        if ( $out )
            fclose( $out );
        $zip->close();
        if ( $copied === false )
            throw new expImportSourceException( "$member cannot be read from the ZIP archive" );
        return $target;
    }

    /** @return array the record of a folder */
    public static function folderRecord( $path )
    {
        $parent = dirname( $path );
        return array( 'id' => $path, 'kind' => 'folder', 'path' => $path, 'folder' => $parent === '.' ? '' : $parent,
                      'parent_path' => $parent === '.' ? '' : $parent, 'name' => basename( $path ), 'title' => basename( $path ) );
    }

    /**
     * A document as a record (or a record with _error).
     *
     * @param string $local the file
     * @param string $path its path in the source (folders/name.ext)
     * @param string $assets the run's directory for the images and files of documents
     * @param array $extra fields to add (file, source_url, ...)
     * @return array
     */
    public static function documentRecord( $local, $path, $assets, array $extra = array() )
    {
        $name = basename( $path );
        $parent = dirname( $path );
        $base = array( 'id' => $path, 'kind' => 'document', 'path' => $path, 'folder' => $parent === '.' ? '' : $parent,
                       'parent_path' => $parent === '.' ? '' : $parent, 'name' => $name ) + $extra;
        try
        {
            return $base + self::convert( $local, $name, $assets . '/' . substr( sha1( $path . '|' . @filemtime( $local ) ), 0, 16 ) );
        }
        catch ( Exception $e )
        {
            return $base + array( '_error' => $name . ': ' . $e->getMessage() );
        }
    }

    /**
     * Converts one document to the fields of a record.
     * @return array
     */
    public static function convert( $local, $name, $dir )
    {
        $ext = expOdfConverter::extensionOf( $name );
        $family = expOdfConverter::family( $ext );
        expOdfPaths::ensure( $dir );
        $readable = $local;
        $readableExt = $ext;
        $converted = null;
        $converter = 'ezodf PHP reader';
        if ( !expOdfReaders::reads( $ext ) )
        {
            // LibreOffice turns the document into the OOXML form of its family, which the readers take apart
            $to = array( 'spreadsheet' => 'xlsx', 'presentation' => 'pptx', 'drawing' => 'pdf' );
            $target = isset( $to[$family] ) ? $to[$family] : 'docx';
            $converted = expOdfConverter::convert( $local, $target, array( 'name' => $name, 'backends' => array( 'remote', 'server', 'cli' ) ) );
            $readable = $converted['file'];
            $readableExt = $target;
            $converter = $converted['version'] . ' (' . $converted['backend'] . ')';
        }
        try
        {
            $r = expOdfReaders::toHtml( $readable, $readableExt, $dir, array( 'name' => $name ) );
        }
        finally
        {
            if ( $converted )
                expOdfConverter::cleanup( $converted );
        }
        // images: files the run keeps, named "run:<path below the run directory>"
        $runBase = dirname( dirname( $dir ) );
        $images = array();
        $html = $r['html'];
        foreach ( $r['images'] as $key => $file )
        {
            $ref = 'run:' . ltrim( substr( $file, strlen( $runBase ) ), '/' );
            $images[] = $ref;
            $html = str_replace( 'src="' . htmlspecialchars( $key, ENT_QUOTES ) . '"', 'src="' . htmlspecialchars( $ref, ENT_QUOTES ) . '"', $html );
        }
        $text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( str_replace( array( '</p>', '</h1>', '</h2>', '</h3>', '</li>', '<br />' ), "\n", $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        $headings = array();
        if ( preg_match_all( '#<h([1-6])[^>]*>(.*?)</h\1>#su', $html, $m ) )
            foreach ( $m[2] as $h )
                $headings[] = trim( html_entity_decode( strip_tags( $h ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        // a spreadsheet or presentation is named after its file (its first heading is a sheet or a slide)
        $title = in_array( $family, array( 'spreadsheet', 'presentation', 'drawing' ), true ) ? '' : trim( (string)$r['title'] );
        if ( $title === '' && !in_array( $family, array( 'spreadsheet', 'presentation', 'drawing' ), true ) )
            $title = $headings ? $headings[0] : '';
        if ( $title === '' )
            $title = ucfirst( str_replace( '_', ' ', pathinfo( $name, PATHINFO_FILENAME ) ) );
        $summary = isset( $r['meta']['description'] ) ? trim( (string)$r['meta']['description'] ) : '';
        if ( $summary === '' && preg_match( '#<p[^>]*>(.*?)</p>#su', $html, $p ) )
            $summary = trim( html_entity_decode( strip_tags( $p[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( mb_strlen( $summary ) > 400 )
            $summary = rtrim( mb_substr( $summary, 0, 397 ) ) . '…';
        $meta = $r['meta'] + array( 'author' => '', 'keywords' => array(), 'created' => 0, 'modified' => 0, 'language' => '', 'pages' => 0 );
        return array(
            'title' => $title, 'summary' => $summary, 'html' => $html, 'text' => $text, 'headings' => $headings, 'images' => $images,
            'keywords' => (array)$meta['keywords'], 'author' => (string)$meta['author'],
            'created' => $meta['created'] ? date( 'c', (int)$meta['created'] ) : '', 'modified' => $meta['modified'] ? date( 'c', (int)$meta['modified'] ) : '',
            'language' => (string)$meta['language'], 'pages' => (int)$meta['pages'], 'format' => $ext, 'family' => $family,
            'size' => (int)@filesize( $local ), 'converter' => $converter,
        );
    }

    /**
     * Writes records into a snapshot (the remote kinds): one JSON object per line.
     *
     * @param iterable $documents of array( 'file' => local file, 'path' => folders/name, + fields to add )
     * @param string $target the snapshot
     * @param int $limit 0: all
     * @param bool $folders whether folders become records before their documents
     */
    public static function writeSnapshot( $documents, $target, $limit = 0, $folders = false )
    {
        $assets = dirname( $target ) . '/docs';
        expOdfPaths::ensure( $assets );
        $tmp = $target . '.part';
        $out = fopen( $tmp, 'wb' );
        if ( !$out )
            throw new expImportSourceException( 'the snapshot cannot be written' );
        $n = 0;
        $seen = array();
        try
        {
            foreach ( $documents as $doc )
            {
                if ( $folders )
                {
                    $dir = dirname( $doc['path'] );
                    $chain = array();
                    while ( $dir !== '.' && $dir !== '' && !isset( $seen[$dir] ) )
                    {
                        $chain[] = $dir;
                        $seen[$dir] = true;
                        $dir = dirname( $dir );
                    }
                    foreach ( array_reverse( $chain ) as $folder )
                        fwrite( $out, json_encode( self::folderRecord( $folder ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
                }
                $extra = $doc;
                unset( $extra['file'], $extra['path'] );
                if ( isset( $doc['error'] ) )
                    $record = array( 'id' => $doc['path'], 'kind' => 'document', 'path' => $doc['path'], 'name' => basename( $doc['path'] ), '_error' => $doc['error'] ) + $extra;
                else
                {
                    // the original file stays with the run: a mapping can store it in a file attribute
                    $keep = $assets . '/originals/' . substr( sha1( $doc['path'] ), 0, 16 ) . '-' . expOdfRemoteFetcher::safeName( basename( $doc['path'] ) );
                    expOdfPaths::ensure( dirname( $keep ) );
                    if ( !is_file( $keep ) )
                        copy( $doc['file'], $keep );
                    $extra['file'] = 'run:' . ltrim( substr( $keep, strlen( dirname( $target ) ) ), '/' );
                    $record = self::documentRecord( $keep, $doc['path'], $assets, $extra );
                }
                fwrite( $out, json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n" );
                if ( $limit > 0 && ++$n >= $limit )
                    break;
            }
        }
        finally
        {
            fclose( $out );
        }
        if ( !rename( $tmp, $target ) )
            throw new expImportSourceException( 'the snapshot cannot be written' );
    }
}
