<?php
/**
 * File containing the expOdfExporter class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Items out as documents. One item is written as an OpenDocument text by eZOOConverter (the item's text, headings,
 * lists, tables and images; a class without an odf.ini attribute list gives all its text attributes) and converted
 * to the format asked for (expOdfConverter: the conversion server, LibreOffice per call or the remote server). A
 * folder and everything below it is a background job (ezodf.export) that writes one document per item into a ZIP,
 * its folders kept, with a list of what could not be exported; the export page offers the ZIP for download.
 * Exports live in var/<site>/ezodf/exports/<id>/ and are removed after retention.keep_days.
 */
class expOdfExporter
{
    /** format => array( label, media type, needs a converter ) */
    const FORMATS = array(
        'odt' => array( 'OpenDocument text (ODT)', 'application/vnd.oasis.opendocument.text', false ),
        'docx' => array( 'Word (DOCX)', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', true ),
        'pdf' => array( 'PDF', 'application/pdf', true ),
        'rtf' => array( 'Rich Text Format (RTF)', 'application/rtf', true ),
        'doc' => array( 'Word 97-2003 (DOC)', 'application/msword', true ),
        'html' => array( 'Web page (XHTML)', 'text/html', true ),
        'epub' => array( 'E-book (EPUB)', 'application/epub+zip', true ),
        'txt' => array( 'Plain text', 'text/plain', true ),
    );

    /** @return array format => label of the formats this installation can write now */
    public static function available()
    {
        $converter = expOdfInstallation::active() !== null || expOdfSettings::get( 'converter.remote_url' ) !== '';
        $gotenberg = expOdfSettings::get( 'converter.remote_kind' ) === 'gotenberg' && expOdfInstallation::active() === null;
        $out = array();
        foreach ( self::FORMATS as $format => $f )
            if ( !$f[2] || ( $converter && ( !$gotenberg || $format === 'pdf' ) ) )
                $out[$format] = $f[0];
        return $out;
    }

    /** @return string the legacy names of the 6.x views (PDF, Word) as formats */
    public static function format( $name )
    {
        $name = strtolower( trim( (string)$name ) );
        $legacy = array( 'word' => 'docx', 'openoffice' => 'odt', 'ooo' => 'odt', 'oo' => 'odt' );
        if ( isset( $legacy[$name] ) )
            $name = $legacy[$name];
        return isset( self::FORMATS[$name] ) ? $name : '';
    }

    /**
     * One item as a document.
     * @return array( 'file' => path, 'dir' => work directory to remove, 'name' => download name, 'mime' => type, 'backend' )
     * @throws expOdfConverterException
     */
    public static function export( eZContentObjectTreeNode $node, $format )
    {
        $format = self::format( $format );
        if ( $format === '' )
            throw new expOdfConverterException( 'unsupported', 'this format cannot be written' );
        try
        {
            $generated = eZOOConverter::objectToOO( (int)$node->attribute( 'node_id' ) );
        }
        catch ( Throwable $e )
        {
            throw new expOdfConverterException( 'convert', 'the item could not be written as a document (' . $e->getMessage() . ')' );
        }
        if ( !is_string( $generated ) || !is_file( $generated ) )
            throw new expOdfConverterException( 'convert', 'the item could not be written as a document' . ( is_array( $generated ) && isset( $generated[1] ) ? ': ' . $generated[1] : '' ) );
        $dir = expOdfPaths::jobDir( 'work' );
        $odt = $dir . '/document.odt';
        copy( $generated, $odt );
        eZOOGenerator::removeDocument( $generated );
        $name = self::fileName( $node->attribute( 'name' ) ) . '.' . $format;
        if ( $format === 'odt' )
            return array( 'file' => $odt, 'dir' => $dir, 'name' => $name, 'mime' => self::FORMATS['odt'][1], 'backend' => 'php' );
        try
        {
            $r = expOdfConverter::convert( $odt, $format, array( 'name' => 'document.odt', 'backends' => array( 'remote', 'server', 'cli' ) ) );
        }
        catch ( expOdfConverterException $e )
        {
            expOdfPaths::remove( $dir );
            throw $e;
        }
        $out = $dir . '/document.' . $format;
        rename( $r['file'], $out );
        expOdfConverter::cleanup( $r );
        return array( 'file' => $out, 'dir' => $dir, 'name' => $name, 'mime' => self::FORMATS[$format][1], 'backend' => $r['backend'] );
    }

    /** @return string a file name of an item's name */
    public static function fileName( $name )
    {
        $name = trim( preg_replace( '/[\x00-\x1f\/\\\\:*?"<>|]+/u', ' ', (string)$name ) );
        $name = preg_replace( '/\s+/', ' ', $name );
        return $name !== '' ? mb_substr( $name, 0, 100 ) : 'document';
    }

    /** Sends a file as a download and ends the request. */
    public static function send( array $export )
    {
        while ( @ob_end_clean() );
        $name = $export['name'];
        $ascii = preg_replace( '/[^A-Za-z0-9 ._()-]/', '_', $name );
        header( 'Content-Type: ' . $export['mime'] );
        header( 'Content-Length: ' . filesize( $export['file'] ) );
        header( "Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode( $name ) );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Cache-Control: private, no-store' );
        readfile( $export['file'] );
        if ( !empty( $export['dir'] ) )
            expOdfPaths::remove( $export['dir'] );
        eZExecution::cleanExit();
    }

    // ---- a folder as a ZIP (job ezodf.export) ------------------------------------------------------------------

    /**
     * Starts the export of a folder.
     * @return string the export's id
     */
    public static function startSubtree( eZContentObjectTreeNode $node, $format )
    {
        $dir = expOdfPaths::jobDir( 'exports' );
        $id = basename( $dir );
        expOdfPaths::writeJson( $dir . '/export.json', array(
            'id' => $id, 'node_id' => (int)$node->attribute( 'node_id' ), 'name' => $node->attribute( 'name' ), 'format' => self::format( $format ),
            'state' => 'queued', 'created' => time(), 'user_id' => (int)eZUser::currentUserID(), 'done' => 0, 'failed' => 0, 'total' => 0, 'errors' => array() ) );
        $ref = expOdfJobs::start( 'ezodf.export', array( 'export' => $id, 'node' => (int)$node->attribute( 'node_id' ) ) );
        $data = expOdfPaths::readJson( $dir . '/export.json' );
        $data['job'] = $ref;
        expOdfPaths::writeJson( $dir . '/export.json', $data );
        return $id;
    }

    /** @return array|null an export's record */
    public static function fetchExport( $id )
    {
        if ( !preg_match( '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/', (string)$id ) )
            return null;
        $data = expOdfPaths::readJson( expOdfPaths::base() . '/exports/' . $id . '/export.json' );
        if ( !$data )
            return null;
        if ( in_array( $data['state'], array( 'queued', 'running' ), true ) && !empty( $data['job'] ) && $data['created'] < time() - 120 )
        {
            $job = expOdfJobs::status( $data['job'] );
            if ( in_array( $job['state'], array( 'failed', 'cancelled' ), true ) )
            {
                $data['state'] = 'failed';
                $data['message'] = expOdfConverter::scrub( $job['message'] );
            }
        }
        return $data;
    }

    /** @return array the exports of the current user (all with ezodf/converter), newest first */
    public static function listExports( $limit = 20 )
    {
        $all = expOdfDocumentsAdmin::can( 'converter' );
        $me = (int)eZUser::currentUserID();
        $dirs = glob( expOdfPaths::base() . '/exports/*', GLOB_ONLYDIR ) ?: array();
        rsort( $dirs );
        $out = array();
        foreach ( $dirs as $dir )
        {
            $e = self::fetchExport( basename( $dir ) );
            if ( $e && ( $all || (int)$e['user_id'] === $me ) )
                $out[] = $e;
            if ( count( $out ) >= $limit )
                break;
        }
        return $out;
    }

    /** The job ezodf.export. */
    public static function runSubtreeJob( array $payload, $progress )
    {
        $id = (string)$payload['export'];
        $dir = expOdfPaths::base() . '/exports/' . $id;
        $data = self::fetchExport( $id );
        if ( !$data )
            throw new expOdfConverterException( 'convert', "no export $id" );
        if ( !empty( $data['user_id'] ) && eZUser::currentUserID() != $data['user_id'] && ( $user = eZUser::fetch( (int)$data['user_id'] ) ) )
            $user->loginCurrent();
        $root = eZContentObjectTreeNode::fetch( (int)$data['node_id'] );
        if ( !$root || !$root->canRead() )
            throw new expOdfConverterException( 'convert', 'the folder does not exist or may not be read' );
        $data['state'] = 'running';
        $data['started'] = time();
        expOdfPaths::writeJson( $dir . '/export.json', $data );
        $nodes = array_merge( array( $root ), $root->subTree( array( 'Depth' => 6, 'Limit' => expOdfSettings::get( 'import.max_files' ), 'SortBy' => array( 'path', true ) ) ) ?: array() );
        $zipName = self::fileName( $root->attribute( 'name' ) ) . '-' . $data['format'] . '.zip';
        $zip = new ZipArchive();
        $zip->open( $dir . '/' . $zipName, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        $depth = (int)$root->attribute( 'depth' );
        $folders = array( (int)$root->attribute( 'node_id' ) => self::fileName( $root->attribute( 'name' ) ) );
        $data['total'] = count( $nodes );
        $used = array();
        foreach ( $nodes as $i => $node )
        {
            $progress( $i, count( $nodes ), $node->attribute( 'name' ) );
            $parent = (int)$node->attribute( 'parent_node_id' );
            $base = isset( $folders[$parent] ) ? $folders[$parent] . '/' : ( $node->attribute( 'node_id' ) == $root->attribute( 'node_id' ) ? '' : '' );
            $path = $node->attribute( 'node_id' ) == $root->attribute( 'node_id' ) ? $folders[(int)$node->attribute( 'node_id' )] : $base . self::fileName( $node->attribute( 'name' ) );
            if ( $node->attribute( 'children_count' ) > 0 )
                $folders[(int)$node->attribute( 'node_id' )] = $path;
            if ( !$node->canRead() || !self::hasText( $node ) )
                continue;
            try
            {
                $e = self::export( $node, $data['format'] );
                $entry = $path . '.' . $data['format'];
                for ( $n = 2; isset( $used[$entry] ); $n++ )
                    $entry = $path . " ($n)." . $data['format'];
                $used[$entry] = true;
                $zip->addFile( $e['file'], $entry );
                $zip->close(); // the file is read now; the work directory can go
                expOdfPaths::remove( $e['dir'] );
                $zip->open( $dir . '/' . $zipName );
                $data['done']++;
            }
            catch ( Throwable $ex ) // one item that cannot be written never stops the others
            {
                $data['failed']++;
                $data['errors'][] = $path . ': ' . expOdfConverter::scrub( $ex->getMessage() );
            }
        }
        if ( $data['errors'] )
            $zip->addFromString( 'not exported.txt', implode( "\n", $data['errors'] ) . "\n" );
        $zip->close();
        $data['state'] = 'done';
        $data['finished'] = time();
        $data['file'] = $zipName;
        $data['size'] = is_file( $dir . '/' . $zipName ) ? filesize( $dir . '/' . $zipName ) : 0;
        expOdfPaths::writeJson( $dir . '/export.json', $data );
        return array( 'message' => sprintf( '%d document(s) exported, %d failed', $data['done'], $data['failed'] ) );
    }

    /** @return bool whether an item has text an export can write (a folder with only a name is skipped) */
    protected static function hasText( eZContentObjectTreeNode $node )
    {
        foreach ( $node->object()->contentObjectAttributes() as $a )
            if ( in_array( $a->attribute( 'data_type_string' ), array( 'ezxmltext', 'eztext' ), true ) && $a->hasContent() )
                return true;
        return false;
    }
}
