<?php
/**
 * File containing the expOdfConverter class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Converts documents. One call:
 *
 *   $result = expOdfConverter::convert( '/path/report.docx', 'odt' );
 *   $result['file']     the converted file (in a work directory of its own; the caller moves or reads it, and
 *                       calls expOdfConverter::cleanup( $result ) when done)
 *   $result['backend']  remote, server, cli or php;  $result['seconds'], ['filter'], ['version']
 *
 * converter.mode (settings) chooses the backends:
 *   auto     remote (when a URL is set), the conversion server (when it runs), soffice per call, PHP
 *   server   the conversion server, else soffice per call
 *   cli      soffice per call (sandboxed)
 *   remote   only the remote server (unoserver or Gotenberg)
 *   php      only the PHP readers (ezodf's own: ODT, OpenOffice.org 1.x text, and the readers of expOdfReaders)
 * A backend that cannot do a conversion (Gotenberg writes PDF only, PHP no PDF) is skipped; the next one tries.
 *
 * Failures throw expOdfConverterException with a code (too_large, timeout, unsupported, crashed, no_backend,
 * convert, sandbox) and the backends tried.
 */
class expOdfConverter
{
    /** @var array names of the PHP readers that are available, extension => callable( $in, $outDir ) => file */
    protected static $phpReaders = null;

    /**
     * @param string $file the document
     * @param string $target the extension to convert to (odt, docx, pdf ...)
     * @param array $options filter (export filter name), infilter, filter_options, name (the original file name,
     *                       for the extension), backends (list to restrict)
     * @return array
     * @throws expOdfConverterException
     */
    public static function convert( $file, $target, array $options = array() )
    {
        $target = strtolower( trim( $target, '. ' ) );
        if ( !preg_match( '/^[a-z0-9]{1,8}$/', $target ) )
            throw new expOdfConverterException( 'unsupported', "no such target format: $target" );
        if ( !is_file( $file ) || !is_readable( $file ) )
            throw new expOdfConverterException( 'convert', 'the document cannot be read' );
        $name = isset( $options['name'] ) && $options['name'] !== '' ? (string)$options['name'] : basename( $file );
        $ext = self::extensionOf( $name );
        $max = expOdfSettings::get( 'converter.max_megabytes' ) * 1048576;
        if ( filesize( $file ) > $max )
            throw new expOdfConverterException( 'too_large', sprintf( 'the document is larger than %d MB', $max / 1048576 ) );
        $errors = array();
        foreach ( self::backends( isset( $options['backends'] ) ? (array)$options['backends'] : null ) as $backend )
        {
            if ( !self::backendCan( $backend, $ext, $target ) )
                continue;
            try
            {
                $result = self::{'via' . ucfirst( $backend )}( $file, $ext, $target, $options );
                $result['backend'] = $backend;
                $result['tried'] = array_keys( $errors );
                return $result;
            }
            catch ( expOdfConverterException $e )
            {
                $errors[$backend] = $e->getMessage();
                if ( in_array( $e->code(), array( 'too_large' ), true ) )
                    break;
            }
        }
        if ( !$errors )
            throw new expOdfConverterException( 'no_backend', self::noBackendMessage( $ext, $target ) );
        $parts = array();
        foreach ( $errors as $backend => $message )
            $parts[] = "$backend: $message";
        $e = new expOdfConverterException( count( $errors ) === 1 && isset( $e ) ? $e->code() : 'convert', implode( '; ', $parts ) );
        $e->tried = $errors;
        throw $e;
    }

    /** The families of the formats ezodf imports, by extension (anything else is tried as text). */
    const FAMILIES = array(
        'spreadsheet' => array( 'ods', 'ots', 'fods', 'xlsx', 'xlsm', 'xltx', 'xls', 'xlt', 'csv', 'tsv', 'sxc', 'stc', 'sdc', 'numbers', 'wk1', 'wks', '123', 'dbf', 'slk', 'xlsb', 'et' ),
        'presentation' => array( 'odp', 'otp', 'fodp', 'pptx', 'pptm', 'ppsx', 'potx', 'ppt', 'pps', 'pot', 'sxi', 'sti', 'sdd', 'key', 'dps' ),
        'drawing' => array( 'odg', 'otg', 'fodg', 'sxd', 'std', 'sda', 'vsd', 'vsdx', 'vdx', 'cdr', 'pub', 'cgm', 'wpg' ),
        'pdf' => array( 'pdf' ),
        'markup' => array( 'md', 'markdown' ),
        'image' => array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp', 'svg' ),
    );

    /** @return string text, spreadsheet, presentation, drawing, pdf, markup or image */
    public static function family( $ext )
    {
        foreach ( self::FAMILIES as $family => $list )
            if ( in_array( $ext, $list, true ) )
                return $family;
        return 'text';
    }

    /**
     * Any document as an ODT text for the ODT parser (eZOOImport): text formats through LibreOffice's Writer;
     * spreadsheets and presentations through their OpenDocument form (LibreOffice, or as they are) and ezodf's
     * readers (a table per sheet, a section per slide); PDF and Markdown through the readers; the PHP converter
     * where LibreOffice is missing.
     *
     * @param string $file
     * @param string $name the original file name
     * @return array a result as convert() returns it ('file' is an .odt)
     */
    public static function toOdt( $file, $name )
    {
        $ext = self::extensionOf( $name );
        $family = self::family( $ext );
        if ( $family === 'text' || !class_exists( 'expOdfReaders' ) )
            return self::convert( $file, 'odt', array( 'name' => $name ) );
        // the family's OpenDocument form first, when the readers cannot read the format itself
        $normal = array( 'spreadsheet' => 'ods', 'presentation' => 'odp', 'drawing' => 'odg' );
        $source = $file;
        $sourceExt = $ext;
        $pre = null;
        if ( isset( $normal[$family] ) && !expOdfReaders::reads( $ext ) )
        {
            $pre = self::convert( $file, $normal[$family], array( 'name' => $name, 'backends' => array( 'remote', 'server', 'cli' ) ) );
            $source = $pre['file'];
            $sourceExt = $normal[$family];
        }
        $dir = expOdfPaths::jobDir( 'work' );
        $start = microtime( true );
        try
        {
            $html = expOdfReaders::toHtml( $source, $sourceExt, $dir, array( 'name' => $name ) );
            $out = $dir . '/out.odt';
            expOdfHtmlToOdt::write( $html['html'], $html['images'], $out, $html['title'] );
        }
        catch ( Exception $e )
        {
            expOdfPaths::remove( $dir );
            throw $e instanceof expOdfConverterException ? $e : new expOdfConverterException( 'convert', $e->getMessage() );
        }
        finally
        {
            if ( $pre )
                self::cleanup( $pre );
        }
        return array( 'file' => $out, 'dir' => $dir, 'seconds' => round( microtime( true ) - $start + ( $pre ? $pre['seconds'] : 0 ), 3 ),
                      'filter' => ( $pre ? $pre['filter'] . ' + ' : '' ) . 'ezodf ' . $family . ' reader',
                      'version' => $pre ? $pre['version'] : 'ezodf PHP reader', 'backend' => $pre ? $pre['backend'] : 'php', 'family' => $family );
    }

    /** @return string why nothing can convert this */
    protected static function noBackendMessage( $ext, $target )
    {
        if ( !expOdfInstallation::active() && expOdfSettings::get( 'converter.remote_url' ) === '' )
            return "converting .$ext to .$target needs LibreOffice: install it on the Document converter page";
        return "no converter can turn .$ext into .$target";
    }

    /** @return string the lower-case extension of a file name */
    public static function extensionOf( $name )
    {
        $ext = strtolower( (string)pathinfo( (string)$name, PATHINFO_EXTENSION ) );
        return preg_match( '/^[a-z0-9]{1,8}$/', $ext ) ? $ext : '';
    }

    /** @return string[] the backends in the order the settings give */
    public static function backends( array $only = null )
    {
        $mode = expOdfSettings::get( 'converter.mode' );
        $remote = expOdfSettings::get( 'converter.remote_url' ) !== '';
        switch ( $mode )
        {
            case 'server':
                $list = array( 'server', 'cli', 'php' );
                break;
            case 'cli':
                $list = array( 'cli', 'php' );
                break;
            case 'remote':
                $list = array( 'remote' );
                break;
            case 'php':
                $list = array( 'php' );
                break;
            default:
                $list = $remote ? array( 'remote', 'server', 'cli', 'php' ) : array( 'server', 'cli', 'php' );
        }
        return $only !== null ? array_values( array_intersect( $list, $only ) ) : $list;
    }

    /** @return bool whether a backend may try this conversion at all */
    public static function backendCan( $backend, $ext, $target )
    {
        switch ( $backend )
        {
            case 'remote':
                if ( expOdfSettings::get( 'converter.remote_url' ) === '' )
                    return false;
                return expOdfSettings::get( 'converter.remote_kind' ) === 'gotenberg' ? $target === 'pdf' : true;
            case 'server':
                return expOdfServer::running(); // a running server is used, also while it is being turned off
            case 'cli':
                return expOdfInstallation::active() !== null;
            case 'php':
                return expOdfPhpConverter::can( $ext, $target );
        }
        return false;
    }

    /**
     * What every backend can do now, for the status panel.
     * @return array backend => array( 'available' => bool, 'note' => string )
     */
    public static function backendStatus()
    {
        $installation = expOdfInstallation::active();
        $server = expOdfServer::status();
        $remote = expOdfSettings::get( 'converter.remote_url' );
        return array(
            'remote' => array( 'available' => $remote !== '', 'note' => $remote !== '' ? expOdfSettings::get( 'converter.remote_kind' ) . ' ' . self::redact( $remote ) : '' ),
            'server' => array( 'available' => $server['running'], 'note' => $server['enabled'] ? ( $server['running'] ? 'running' : 'turned on, not running' ) : 'turned off' ),
            'cli' => array( 'available' => $installation !== null, 'note' => $installation ? $installation->version : 'no LibreOffice' ),
            'php' => array( 'available' => true, 'note' => implode( ', ', expOdfPhpConverter::extensions() ) . ' to ODT' ),
        );
    }

    /** @return string a URL without user and password */
    public static function redact( $url )
    {
        return preg_replace( '#//[^/@]*@#', '//', (string)$url );
    }

    /**
     * A work directory with the input copied in under a neutral name.
     * @return array( dir, input path )
     */
    protected static function workDir( $file, $ext, $where = 'work', $mode = 02775 )
    {
        $dir = expOdfPaths::jobDir( $where, $mode );
        $in = $dir . '/in.' . ( $ext !== '' ? $ext : 'bin' );
        if ( !@copy( $file, $in ) )
        {
            expOdfPaths::remove( $dir );
            throw new expOdfConverterException( 'convert', 'the document cannot be copied to the work directory' );
        }
        @chmod( $in, 0664 );
        return array( $dir, $in );
    }

    /** Removes the work directory of a result. */
    public static function cleanup( array $result )
    {
        if ( !empty( $result['dir'] ) )
            expOdfPaths::remove( $result['dir'] );
    }

    // ---- the backends ----------------------------------------------------------------------------------------

    protected static function viaServer( $file, $ext, $target, array $options )
    {
        list( $dir, $in ) = self::workDir( $file, $ext, 'spool', 02777 );
        @chmod( $dir, 02777 );
        $timeout = expOdfSettings::get( 'converter.timeout' );
        $request = array( 'input' => basename( $in ), 'output' => 'out.' . $target, 'timeout' => $timeout,
                          'filter' => isset( $options['filter'] ) ? (string)$options['filter'] : '',
                          'infilter' => isset( $options['infilter'] ) ? (string)$options['infilter'] : '',
                          'filter_options' => isset( $options['filter_options'] ) ? (string)$options['filter_options'] : '' );
        expOdfPaths::writeJson( $dir . '/request.json', $request );
        $start = microtime( true );
        // the server may have other jobs before this one: wait for the queue plus this job's timeout
        $deadline = $start + $timeout + 30 + 5 * count( glob( expOdfServer::spoolDir() . '/*/request.json' ) ?: array() );
        while ( !is_file( $dir . '/result.json' ) )
        {
            if ( microtime( true ) > $deadline || ( is_file( $dir . '/request.json' ) && !expOdfServer::running() ) )
            {
                expOdfPaths::remove( $dir );
                throw new expOdfConverterException( 'timeout', 'the conversion server did not answer in time' );
            }
            usleep( 50000 );
        }
        $result = expOdfPaths::readJson( $dir . '/result.json' );
        if ( empty( $result['ok'] ) )
        {
            expOdfPaths::remove( $dir );
            throw new expOdfConverterException( isset( $result['code'] ) ? $result['code'] : 'convert', isset( $result['error'] ) ? $result['error'] : 'the conversion failed' );
        }
        $out = $dir . '/out.' . $target;
        if ( !is_file( $out ) || filesize( $out ) === 0 )
        {
            expOdfPaths::remove( $dir );
            throw new expOdfConverterException( 'convert', 'the conversion wrote no file' );
        }
        return array( 'file' => $out, 'dir' => $dir, 'seconds' => round( microtime( true ) - $start, 3 ),
                      'filter' => isset( $result['filter'] ) ? $result['filter'] : '', 'version' => isset( $result['version'] ) ? $result['version'] : '' );
    }

    protected static function viaCli( $file, $ext, $target, array $options )
    {
        $installation = expOdfInstallation::active();
        if ( !$installation )
            throw new expOdfConverterException( 'no_backend', 'no LibreOffice installation' );
        list( $dir, $in ) = self::workDir( $file, $ext );
        $slot = self::acquireSlot();
        try
        {
            $to = $target . ( !empty( $options['filter'] ) ? ':' . $options['filter'] . ( !empty( $options['filter_options'] ) ? ':' . $options['filter_options'] : '' ) : '' );
            $command = array( $installation->soffice, '--headless', '--norestore', '--nologo', '--nodefault', '--nolockcheck',
                              '--nofirststartwizard', '-env:UserInstallation=file:///tmp/home/lo' );
            if ( !empty( $options['infilter'] ) )
                $command[] = '--infilter=' . $options['infilter'];
            array_push( $command, '--convert-to', $to, '--outdir', $dir . '/out', $in );
            mkdir( $dir . '/out', 02775 );
            $timeout = expOdfSettings::get( 'converter.timeout' );
            $argv = expOdfSandbox::command( $command, array( $installation->root ), array( $dir ),
                                            array( 'seconds' => $timeout, 'memory' => expOdfSettings::get( 'converter.memory_megabytes' ),
                                                   'fsize' => max( 256, expOdfSettings::get( 'converter.max_megabytes' ) * 8 ), 'cpu' => $timeout * 2 ) );
            $r = expOdfSandbox::run( $argv, $timeout + 10 );
        }
        finally
        {
            self::releaseSlot( $slot );
        }
        $out = $dir . '/out/in.' . $target;
        if ( $r['timedout'] )
        {
            expOdfPaths::remove( $dir );
            throw new expOdfConverterException( 'timeout', sprintf( 'the conversion took longer than %d seconds', $timeout ) );
        }
        if ( !is_file( $out ) || filesize( $out ) === 0 )
        {
            expOdfPaths::remove( $dir );
            $why = trim( $r['stderr'] . ' ' . $r['stdout'] );
            if ( stripos( $why, 'no export filter' ) !== false || stripos( $why, 'Error: source file could not be loaded' ) !== false )
                throw new expOdfConverterException( 'unsupported', "LibreOffice cannot convert .$ext to .$target" . ( $why !== '' ? ' (' . self::shorten( $why ) . ')' : '' ) );
            throw new expOdfConverterException( $r['code'] > 128 ? 'crashed' : 'convert', 'LibreOffice wrote no file (exit code ' . $r['code'] . ( $why !== '' ? ', ' . self::shorten( $why ) : '' ) . ')' );
        }
        $filter = preg_match( '/using filter : (.+)$/m', $r['stdout'], $m ) ? trim( $m[1] ) : '';
        return array( 'file' => $out, 'dir' => $dir, 'seconds' => $r['seconds'], 'filter' => $filter, 'version' => $installation->version );
    }

    protected static function viaRemote( $file, $ext, $target, array $options )
    {
        $dir = expOdfPaths::jobDir( 'work' );
        $out = $dir . '/out.' . $target;
        try
        {
            $info = expOdfRemoteServer::convert( $file, $ext, $target, $out, $options );
        }
        catch ( expOdfConverterException $e )
        {
            expOdfPaths::remove( $dir );
            throw $e;
        }
        return array( 'file' => $out, 'dir' => $dir, 'seconds' => $info['seconds'], 'filter' => isset( $options['filter'] ) ? (string)$options['filter'] : '',
                      'version' => $info['version'] );
    }

    protected static function viaPhp( $file, $ext, $target, array $options )
    {
        $dir = expOdfPaths::jobDir( 'work' );
        $start = microtime( true );
        try
        {
            $out = expOdfPhpConverter::convert( $file, $ext, $target, $dir );
        }
        catch ( Exception $e )
        {
            expOdfPaths::remove( $dir );
            throw $e instanceof expOdfConverterException ? $e : new expOdfConverterException( 'convert', $e->getMessage() );
        }
        return array( 'file' => $out, 'dir' => $dir, 'seconds' => round( microtime( true ) - $start, 3 ), 'filter' => 'php', 'version' => 'ezodf PHP reader' );
    }

    // ---- one conversion at a time per slot (converter.max_parallel) -------------------------------------------

    /** @return resource the lock of a free slot (waits up to the timeout) */
    protected static function acquireSlot()
    {
        $slots = expOdfSettings::get( 'converter.max_parallel' );
        $deadline = time() + expOdfSettings::get( 'converter.timeout' ) * 2;
        expOdfPaths::ensure( expOdfPaths::base() . '/server' );
        do
        {
            for ( $n = 1; $n <= $slots; $n++ )
            {
                $fh = fopen( expOdfPaths::base() . "/server/cli-$n.lock", 'c' );
                if ( $fh && flock( $fh, LOCK_EX | LOCK_NB ) )
                    return $fh;
                if ( $fh )
                    fclose( $fh );
            }
            usleep( 250000 );
        } while ( time() < $deadline );
        throw new expOdfConverterException( 'timeout', 'every converter is busy' );
    }

    protected static function releaseSlot( $fh )
    {
        if ( is_resource( $fh ) )
        {
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    protected static function shorten( $text )
    {
        $text = preg_replace( '/\s+/', ' ', (string)$text );
        return strlen( $text ) > 300 ? substr( $text, 0, 300 ) . '…' : $text;
    }
}
