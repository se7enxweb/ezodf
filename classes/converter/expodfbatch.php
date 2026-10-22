<?php
/**
 * File containing the expOdfBatch class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * One import of documents: files, a folder (its tree), ZIP archives and URLs, uploaded on the Import documents page.
 * A batch lives in var/<site>/ezodf/batches/<id>/ (the files, and run.json with its items and their states); every
 * item is a background job ezodf.import of its own (one expqueue group per batch), so nothing is converted or
 * published inside a web request, a failed document can be retried alone, and the batch page shows each one live.
 *
 * An item is a file (with its path in the uploaded folder), a ZIP (unpacked by its job into new items, its folders
 * kept), or a URL (downloaded by its job, SSRF-safe, see expOdfRemoteFetcher). With "folders become folders" the
 * directories of a path are created below the target as folder objects (an existing child folder of the same name
 * is used again). A document becomes content through eZOOImport (the converter first, see expOdfConverter::toOdt);
 * an image or another file through the kernel's upload (upload.ini picks the class).
 *
 * The remote ID of an imported object is ezodf-<hash of target node and path>, so importing the same file into the
 * same place again with "update existing" makes a new version instead of a duplicate.
 */
class expOdfBatch
{
    const STATES_OPEN = array( 'uploading', 'queued', 'running' );

    /** @var string */
    public $id;

    /** @var string */
    public $dir;

    protected function __construct( $id )
    {
        $this->id = $id;
        $this->dir = expOdfPaths::base() . '/batches/' . $id;
    }

    /** @return expOdfBatch|null */
    public static function fetch( $id )
    {
        $id = (string)$id;
        if ( !preg_match( '/^[0-9]{8}-[0-9]{6}-[a-f0-9]{16}$/', $id ) )
            return null;
        $batch = new self( $id );
        return is_file( $batch->dir . '/run.json' ) ? $batch : null;
    }

    /**
     * A new batch.
     * @param array $options parent_node (int), tree (bool), replace (bool), language, other_files (bool), ai (bool), title
     */
    public static function create( array $options )
    {
        $dir = expOdfPaths::jobDir( 'batches' );
        $batch = new self( basename( $dir ) );
        expOdfPaths::ensure( $dir . '/files' );
        $run = array(
            'id' => $batch->id, 'created' => time(), 'updated' => time(), 'state' => 'uploading',
            'user_id' => class_exists( 'eZUser' ) ? (int)eZUser::currentUserID() : 0,
            'options' => self::cleanOptions( $options ), 'items' => array(), 'folders' => array(), 'group' => '', 'log' => array(),
        );
        expOdfPaths::writeJson( $dir . '/run.json', $run );
        return $batch;
    }

    /** @return array the options a batch keeps */
    public static function cleanOptions( array $o )
    {
        return array(
            'parent_node' => isset( $o['parent_node'] ) ? (int)$o['parent_node'] : 0,
            'tree' => !empty( $o['tree'] ),
            'replace' => !empty( $o['replace'] ),
            'other_files' => !isset( $o['other_files'] ) || !empty( $o['other_files'] ),
            'language' => isset( $o['language'] ) && preg_match( '/^[a-z]{3}-[A-Z]{2}$/', $o['language'] ) ? $o['language'] : '',
            'ai' => !empty( $o['ai'] ),
            'title' => isset( $o['title'] ) ? mb_substr( trim( (string)$o['title'] ), 0, 120 ) : '',
        );
    }

    /** @return array run.json */
    public function run()
    {
        return expOdfPaths::readJson( $this->dir . '/run.json' );
    }

    /**
     * Changes run.json under a lock: $change( array &$run ) may change it; the result is written back.
     * @return mixed what $change returned
     */
    public function update( $change )
    {
        $fh = fopen( $this->dir . '/run.lock', 'c' );
        flock( $fh, LOCK_EX );
        try
        {
            $run = $this->run();
            $out = $change( $run );
            $run['updated'] = time();
            $run['state'] = self::stateOf( $run );
            expOdfPaths::writeJson( $this->dir . '/run.json', $run );
            return $out;
        }
        finally
        {
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    /** @return string the batch's state from its items */
    public static function stateOf( array $run )
    {
        if ( $run['state'] === 'uploading' || $run['state'] === 'cancelled' )
            return $run['state'];
        $n = array( 'queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0, 'pending' => 0 );
        foreach ( $run['items'] as $item )
            $n[$item['state']] = ( isset( $n[$item['state']] ) ? $n[$item['state']] : 0 ) + 1;
        if ( $n['running'] )
            return 'running';
        if ( $n['queued'] || $n['pending'] )
            return $n['done'] || $n['failed'] ? 'running' : 'queued';
        if ( $n['failed'] )
            return $n['done'] ? 'done_with_errors' : 'failed';
        return 'done';
    }

    /** @return array state => count of the items */
    public static function counts( array $run )
    {
        $n = array( 'total' => 0, 'queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0, 'pending' => 0 );
        foreach ( $run['items'] as $item )
        {
            if ( $item['kind'] === 'zip' && $item['state'] === 'done' )
                continue; // an unpacked ZIP counts by its documents
            $n['total']++;
            $n[$item['state']] = ( isset( $n[$item['state']] ) ? $n[$item['state']] : 0 ) + 1;
        }
        return $n;
    }

    /**
     * Adds an uploaded file (moved into the batch).
     * @param string $tmp the uploaded file
     * @param string $path its name, or its path in the uploaded folder (a/b/report.docx)
     * @return array the item, or array( 'error' => ... )
     */
    public function addFile( $tmp, $path, $moveUploaded = true )
    {
        $path = self::cleanPath( $path );
        if ( $path === '' )
            return array( 'error' => 'no file name' );
        $run = $this->run();
        if ( !in_array( $run['state'], array( 'uploading' ), true ) )
            return array( 'error' => 'the batch has started already' );
        $size = (int)@filesize( $tmp );
        $max = expOdfSettings::get( 'import.max_batch_megabytes' ) * 1048576;
        $total = 0;
        foreach ( $run['items'] as $i )
            $total += isset( $i['size'] ) ? (int)$i['size'] : 0;
        if ( count( $run['items'] ) >= expOdfSettings::get( 'import.max_files' ) )
            return array( 'error' => sprintf( 'a batch takes at most %d files', expOdfSettings::get( 'import.max_files' ) ) );
        if ( $total + $size > $max )
            return array( 'error' => sprintf( 'a batch takes at most %d MB', $max / 1048576 ) );
        $id = 'i' . bin2hex( random_bytes( 6 ) );
        $ext = expOdfConverter::extensionOf( $path );
        $stored = 'files/' . $id . ( $ext !== '' ? '.' . $ext : '' );
        $ok = $moveUploaded && is_uploaded_file( $tmp ) ? move_uploaded_file( $tmp, $this->dir . '/' . $stored ) : copy( $tmp, $this->dir . '/' . $stored );
        if ( !$ok )
            return array( 'error' => 'the file could not be stored' );
        @chmod( $this->dir . '/' . $stored, 0664 );
        $item = array( 'id' => $id, 'kind' => $ext === 'zip' ? 'zip' : 'file', 'name' => basename( $path ), 'path' => $path,
                       'file' => $stored, 'size' => $size, 'state' => 'pending', 'message' => '' );
        $this->update( function ( &$run ) use ( $item ) { $run['items'][$item['id']] = $item; } );
        return $item;
    }

    /** Adds URLs (one per line); returns the items and the errors. */
    public function addUrls( $text )
    {
        $items = array();
        $errors = array();
        foreach ( preg_split( '/[\r\n]+/', (string)$text ) as $line )
        {
            $url = trim( $line );
            if ( $url === '' || $url[0] === '#' )
                continue;
            try
            {
                expOdfRemoteFetcher::check( $url );
            }
            catch ( expOdfConverterException $e )
            {
                // refused (a private address, a scheme or port that is not allowed): listed on the batch page, never fetched
                $errors[] = $url . ': ' . $e->getMessage();
                $id = 'u' . bin2hex( random_bytes( 6 ) );
                $items[$id] = array( 'id' => $id, 'kind' => 'url', 'name' => mb_substr( $url, 0, 120 ), 'path' => '', 'url' => mb_substr( $url, 0, 500 ),
                                     'size' => 0, 'state' => 'failed', 'message' => 'refused: ' . $e->getMessage(), 'code' => 'refused' );
                continue;
            }
            $id = 'u' . bin2hex( random_bytes( 6 ) );
            $items[$id] = array( 'id' => $id, 'kind' => 'url', 'name' => expOdfRemoteFetcher::fileName( $url, '' ), 'path' => '', 'url' => $url,
                                 'size' => 0, 'state' => 'pending', 'message' => '' );
        }
        if ( $items )
            $this->update( function ( &$run ) use ( $items ) { $run['items'] += $items; } );
        return array( $items, $errors );
    }

    /**
     * Starts the batch: one job per pending item.
     * @return int the jobs started
     */
    public function start()
    {
        $run = $this->run();
        if ( !$run['items'] )
            return 0;
        $group = '';
        if ( expOdfJobs::queueAvailable() )
        {
            $g = expQueue::createGroup( 'Documents: ' . ( $run['options']['title'] !== '' ? $run['options']['title'] : $this->id ), 'ezodf.import' );
            $group = $g ? $g->attribute( 'uuid' ) : '';
        }
        $this->update( function ( &$run ) use ( $group ) { $run['state'] = 'queued'; $run['group'] = $group; $run['started'] = time(); } );
        return $this->enqueue( array_keys( array_filter( $run['items'], function ( $i ) { return $i['state'] === 'pending'; } ) ) );
    }

    /** Queues jobs for items (start, retry, the files of an unpacked ZIP). */
    public function enqueue( array $itemIDs )
    {
        $run = $this->run();
        $n = 0;
        foreach ( $itemIDs as $itemID )
        {
            if ( !isset( $run['items'][$itemID] ) )
                continue;
            $options = array( 'resource' => 'ezodf-converter' );
            if ( $run['group'] !== '' && expOdfJobs::queueAvailable() )
                $options['group'] = $run['group'];
            $ref = expOdfJobs::start( 'ezodf.import', array( 'batch' => $this->id, 'item' => $itemID, 'name' => $run['items'][$itemID]['name'] ), $options );
            $this->update( function ( &$run ) use ( $itemID, $ref ) {
                $run['items'][$itemID]['state'] = 'queued';
                $run['items'][$itemID]['job'] = $ref;
                $run['items'][$itemID]['message'] = '';
            } );
            $n++;
        }
        return $n;
    }

    /** Queues the failed items again. */
    public function retryFailed( array $only = array() )
    {
        $run = $this->run();
        $ids = array();
        foreach ( $run['items'] as $id => $item )
            if ( in_array( $item['state'], array( 'failed', 'cancelled' ), true ) && ( !isset( $item['code'] ) || $item['code'] !== 'refused' )
                 && ( !$only || in_array( $id, $only, true ) ) )
                $ids[] = $id;
        if ( $run['state'] === 'cancelled' )
            $this->update( function ( &$run ) { $run['state'] = 'queued'; } );
        return $this->enqueue( $ids );
    }

    /** Cancels what has not run yet. */
    public function cancel()
    {
        $run = $this->run();
        if ( $run['group'] !== '' && expOdfJobs::queueAvailable() )
            expQueue::cancelGroup( $run['group'], 'cancelled on the batch page' );
        $this->update( function ( &$run ) {
            foreach ( $run['items'] as &$item )
                if ( in_array( $item['state'], array( 'queued', 'pending' ), true ) )
                    $item['state'] = 'cancelled';
            unset( $item );
            $run['state'] = 'cancelled';
        } );
    }

    /** @return array the batches, newest first (summary only) */
    public static function listAll( $limit = 100 )
    {
        $out = array();
        $dirs = glob( expOdfPaths::base() . '/batches/*', GLOB_ONLYDIR ) ?: array();
        rsort( $dirs );
        foreach ( array_slice( $dirs, 0, $limit ) as $dir )
        {
            $batch = self::fetch( basename( $dir ) );
            if ( !$batch )
                continue;
            $run = $batch->run();
            if ( in_array( self::stateOf( $run ), array( 'queued', 'running' ), true ) && $batch->reconcile() )
                $run = $batch->run();
            $out[] = array( 'id' => $batch->id, 'created' => $run['created'], 'state' => self::stateOf( $run ), 'counts' => self::counts( $run ),
                            'user_id' => $run['user_id'], 'parent_node' => $run['options']['parent_node'], 'title' => $run['options']['title'] );
        }
        return $out;
    }

    /** @return bool whether the current user may see the batch (own batch, or ezodf/converter for all) */
    public function visibleTo( $user = null )
    {
        $user = $user ?: eZUser::currentUser();
        $run = $this->run();
        if ( (int)$run['user_id'] === (int)$user->attribute( 'contentobject_id' ) )
            return true;
        $access = $user->hasAccessTo( 'ezodf', 'converter' );
        return $access['accessWord'] !== 'no';
    }

    // ---- the work of one item (job ezodf.import) ----------------------------------------------------------------

    /**
     * @return array the job's result
     */
    public static function runItem( $batchID, $itemID, $progress )
    {
        $batch = self::fetch( $batchID );
        if ( !$batch )
            throw new expOdfConverterException( 'convert', "no batch $batchID" );
        $run = $batch->run();
        if ( !isset( $run['items'][$itemID] ) )
            throw new expOdfConverterException( 'convert', "no item $itemID in batch $batchID" );
        $item = $run['items'][$itemID];
        if ( in_array( $item['state'], array( 'done', 'cancelled' ), true ) || $run['state'] === 'cancelled' )
            return array( 'message' => 'nothing to do: ' . $item['state'] );
        $batch->setItem( $itemID, array( 'state' => 'running', 'started' => time(), 'message' => '' ) );
        if ( !empty( $run['user_id'] ) && eZUser::currentUserID() != $run['user_id'] && ( $user = eZUser::fetch( (int)$run['user_id'] ) ) )
            $user->loginCurrent();
        $start = microtime( true );
        try
        {
            $progress( 0, 0, $item['name'] );
            if ( $item['kind'] === 'url' )
            {
                $progress( 0, 0, 'downloading ' . $item['url'] );
                $f = expOdfRemoteFetcher::fetch( $item['url'], $batch->dir . '/files' );
                $item['file'] = 'files/' . basename( $f['file'] );
                $item['name'] = $f['name'];
                $item['size'] = $f['size'];
                $item['kind'] = expOdfConverter::extensionOf( $f['name'] ) === 'zip' ? 'zip' : 'file';
                $batch->setItem( $itemID, array( 'file' => $item['file'], 'name' => $item['name'], 'size' => $item['size'], 'kind' => $item['kind'], 'final_url' => $f['url'] ) );
            }
            if ( $item['kind'] === 'zip' )
                $result = $batch->unpack( $itemID, $item );
            else
                $result = $batch->importFile( $item, $run['options'], $progress );
            $result['seconds'] = round( microtime( true ) - $start, 2 );
            $batch->setItem( $itemID, $result + array( 'finished' => time() ) );
            return array( 'message' => isset( $result['message'] ) ? $result['message'] : $result['state'] );
        }
        catch ( Throwable $e ) // a PHP Error of a parser too: the item must not stay "running"
        {
            $message = expOdfConverter::scrub( $e->getMessage() );
            $batch->setItem( $itemID, array( 'state' => 'failed', 'message' => $message, 'finished' => time(),
                                             'code' => $e instanceof expOdfConverterException ? $e->code() : 'error' ) );
            throw $e instanceof expOdfConverterException ? $e : new expOdfConverterException( 'convert', $message );
        }
    }

    /**
     * Items whose background job has ended without ending the item (a worker that was killed, the job's attempts
     * used up before the item was written) are marked failed with the job's error, so a batch never waits forever.
     * Looks at a job only when the item has not changed for two minutes.
     * @return int the items it ended
     */
    public function reconcile()
    {
        $run = $this->run();
        $stale = array();
        foreach ( $run['items'] as $id => $item )
        {
            if ( !in_array( $item['state'], array( 'queued', 'running' ), true ) || empty( $item['job'] ) )
                continue;
            if ( isset( $item['started'] ) && $item['started'] > time() - 120 )
                continue;
            $job = expOdfJobs::status( $item['job'] );
            if ( in_array( $job['state'], array( 'failed', 'cancelled' ), true ) )
                $stale[$id] = $job;
        }
        if ( !$stale )
            return 0;
        $this->update( function ( &$run ) use ( $stale ) {
            foreach ( $stale as $id => $job )
            {
                $run['items'][$id]['state'] = $job['state'] === 'cancelled' ? 'cancelled' : 'failed';
                $run['items'][$id]['message'] = $job['message'] !== '' ? expOdfConverter::scrub( $job['message'] ) : 'the background job ended without a result';
                $run['items'][$id]['finished'] = time();
            }
        } );
        return count( $stale );
    }

    /** Merges values into an item. */
    public function setItem( $itemID, array $values )
    {
        $this->update( function ( &$run ) use ( $itemID, $values ) {
            if ( isset( $run['items'][$itemID] ) )
                $run['items'][$itemID] = $values + $run['items'][$itemID];
        } );
    }

    /** Unpacks a ZIP into items (its folders kept in their paths) and queues them. */
    protected function unpack( $itemID, array $item )
    {
        $zip = new ZipArchive();
        if ( $zip->open( $this->dir . '/' . $item['file'] ) !== true )
            throw new expOdfConverterException( 'convert', 'the ZIP archive cannot be opened' );
        if ( $problem = eZOOImport::zipEntriesProblem( $zip ) )
        {
            $zip->close();
            throw new expOdfConverterException( 'convert', 'the ZIP archive has entries that cannot be unpacked safely (' . ( is_array( $problem ) ? implode( ' ', $problem ) : 'unsafe' ) . ')' );
        }
        $prefix = $item['path'] !== '' && strpos( $item['path'], '/' ) !== false ? dirname( $item['path'] ) . '/' : '';
        $names = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ )
        {
            $name = $zip->getNameIndex( $i );
            if ( substr( $name, -1 ) === '/' || strpos( $name, '__MACOSX/' ) === 0 || preg_match( '#(^|/)\.#', $name ) || basename( $name ) === 'Thumbs.db' )
                continue;
            $names[$i] = $name;
        }
        $limit = expOdfSettings::get( 'import.max_files' );
        if ( count( $names ) > $limit )
        {
            $zip->close();
            throw new expOdfConverterException( 'too_large', sprintf( 'the ZIP archive holds %d files; a batch takes at most %d', count( $names ), $limit ) );
        }
        $total = 0;
        $new = array();
        foreach ( $names as $i => $name )
        {
            $stat = $zip->statIndex( $i );
            $total += (int)$stat['size'];
            if ( $total > expOdfSettings::get( 'import.max_batch_megabytes' ) * 1048576 )
            {
                $zip->close();
                throw new expOdfConverterException( 'too_large', 'the ZIP archive unpacks to more than the batch limit' );
            }
            $path = self::cleanPath( $prefix . $name );
            if ( $path === '' )
                continue;
            $id = 'z' . bin2hex( random_bytes( 6 ) );
            $ext = expOdfConverter::extensionOf( $path );
            $stored = 'files/' . $id . ( $ext !== '' ? '.' . $ext : '' );
            $in = $zip->getStream( $name );
            $out = fopen( $this->dir . '/' . $stored, 'wb' );
            $copied = $in && $out ? stream_copy_to_stream( $in, $out, 268435456 ) : false;
            if ( $in )
                fclose( $in );
            if ( $out )
                fclose( $out );
            if ( $copied === false )
                continue;
            $new[$id] = array( 'id' => $id, 'kind' => $ext === 'zip' ? 'zip' : 'file', 'name' => basename( $path ), 'path' => $path,
                               'file' => $stored, 'size' => (int)$copied, 'state' => 'pending', 'message' => '', 'from' => $itemID );
        }
        $zip->close();
        $this->update( function ( &$run ) use ( $new ) { $run['items'] += $new; } );
        $this->enqueue( array_keys( $new ) );
        return array( 'state' => 'done', 'message' => sprintf( 'unpacked: %d files', count( $new ) ) );
    }

    /**
     * Imports one file below its folder.
     * @return array values of the item
     */
    protected function importFile( array $item, array $options, $progress )
    {
        $file = $this->dir . '/' . $item['file'];
        if ( !is_file( $file ) )
            throw new expOdfConverterException( 'convert', 'the uploaded file is gone (removed by the cleanup?)' );
        $parent = (int)$options['parent_node'];
        $folder = $options['tree'] && strpos( $item['path'], '/' ) !== false ? dirname( $item['path'] ) : '';
        $nodeID = $folder !== '' ? $this->ensureFolder( $parent, $folder ) : $parent;
        $ext = expOdfConverter::extensionOf( $item['name'] );
        $remoteID = 'ezodf-' . md5( $parent . '|' . mb_strtolower( $item['path'] !== '' ? $item['path'] : $item['name'] ) );
        $existing = eZContentObject::fetchByRemoteID( $remoteID );
        if ( $existing && !$options['replace'] )
            return array( 'state' => 'skipped', 'message' => 'imported before (choose "update existing" to make a new version)',
                          'node_id' => (int)$existing->attribute( 'main_node_id' ), 'object_id' => (int)$existing->attribute( 'id' ) );
        if ( self::isDocument( $ext ) )
        {
            $progress( 0, 0, 'converting ' . $item['name'] );
            $import = new eZOOImport();
            if ( $existing )
                $result = $import->import( $file, (int)$existing->attribute( 'main_node_id' ), $item['name'], 'replace', null, $options['language'] ?: false );
            else
                $result = $import->import( $file, $nodeID, $item['name'], 'import', null, $options['language'] ?: false );
            if ( !$result || empty( $result['Object'] ) )
            {
                $error = $import->getErrorNumber() ? trim( $import->getErrorMessage() ) : 'the document is not supported';
                throw new expOdfConverterException( 'convert', $error );
            }
            $object = $result['Object'];
            if ( !$existing && $object instanceof eZContentObject )
            {
                $object->setAttribute( 'remote_id', $remoteID );
                $object->store();
            }
            $info = $import->ConversionInfo ?: array();
            // AI assist (opt-in per import): a summary and keywords to apply on the batch page, never applied here
            $ai = null;
            if ( !empty( $options['ai'] ) && expOdfAiAssist::available() && $object instanceof eZContentObject )
            {
                $progress( 0, 0, 'AI suggestions for ' . $item['name'] );
                eZContentObject::clearCache( array( (int)$object->attribute( 'id' ) ) );
                $fresh = eZContentObject::fetch( (int)$object->attribute( 'id' ) );
                $ai = expOdfAiAssist::suggest( $fresh ?: $object, expOdfAiAssist::textOf( $fresh ?: $object ) );
            }
            return array( 'state' => 'done', 'message' => '', 'object_id' => (int)$object->attribute( 'id' ), 'ai' => $ai,
                          'node_id' => $result['MainNode'] ? (int)$result['MainNode']->attribute( 'node_id' ) : 0,
                          'url_alias' => (string)$result['URLAlias'], 'title' => (string)$result['NodeName'], 'class' => (string)$result['ClassIdentifier'],
                          'backend' => isset( $info['backend'] ) ? $info['backend'] : 'odt', 'filter' => isset( $info['filter'] ) ? $info['filter'] : '',
                          'replaced' => (bool)$existing );
        }
        if ( !$options['other_files'] )
            return array( 'state' => 'skipped', 'message' => 'not a document (images and other files are left out)' );
        // an image or another file: the kernel's upload picks the class (upload.ini)
        $progress( 0, 0, 'uploading ' . $item['name'] );
        $named = $this->dir . '/work-' . bin2hex( random_bytes( 4 ) ) . '/' . $item['name'];
        expOdfPaths::ensure( dirname( $named ) );
        copy( $file, $named );
        $upload = new eZContentUpload();
        $result = array();
        $ok = $upload->handleLocalFile( $result, $named, $existing ? (int)$existing->attribute( 'main_node_id' ) : $nodeID, $existing ? $existing->attribute( 'main_node' ) : false,
                                        pathinfo( $item['name'], PATHINFO_FILENAME ), $options['language'] ?: false );
        expOdfPaths::remove( dirname( $named ) );
        if ( !$ok || empty( $result['contentobject'] ) )
        {
            $errors = array();
            foreach ( isset( $result['errors'] ) ? $result['errors'] : array() as $e )
                $errors[] = $e['description'];
            throw new expOdfConverterException( 'convert', $errors ? implode( ' ', $errors ) : 'the file could not be uploaded' );
        }
        $object = $result['contentobject'];
        if ( !$existing )
        {
            $object->setAttribute( 'remote_id', $remoteID );
            $object->store();
        }
        $node = $object->attribute( 'main_node' );
        return array( 'state' => 'done', 'message' => '', 'object_id' => (int)$object->attribute( 'id' ),
                      'node_id' => $node ? (int)$node->attribute( 'node_id' ) : 0, 'url_alias' => $node ? (string)$node->attribute( 'url_alias' ) : '',
                      'title' => (string)$object->attribute( 'name' ), 'class' => (string)$object->attribute( 'class_identifier' ), 'backend' => 'upload',
                      'replaced' => (bool)$existing );
    }

    /** @return bool whether a file of this extension is imported as a document */
    public static function isDocument( $ext )
    {
        $ini = eZINI::instance( 'odf.ini' );
        $types = array_merge( (array)$ini->variable( 'DocumentType', 'AllowedTypes' ), (array)$ini->variable( 'DocumentType', 'ConvertTypes' ) );
        return $ext !== '' && in_array( $ext, $types, true );
    }

    /**
     * The node of a folder path below a parent, created where missing (folder class import.class_folder); one
     * batch creates a folder once (run.json 'folders'), and an existing child of the same name and class is used.
     * @return int
     */
    public function ensureFolder( $parentNodeID, $path )
    {
        $fh = fopen( $this->dir . '/folders.lock', 'c' );
        flock( $fh, LOCK_EX );
        try
        {
            $run = $this->run();
            $folders = isset( $run['folders'] ) ? $run['folders'] : array();
            $nodeID = (int)$parentNodeID;
            $sofar = '';
            foreach ( explode( '/', $path ) as $part )
            {
                $sofar = $sofar === '' ? $part : $sofar . '/' . $part;
                if ( isset( $folders[$sofar] ) && eZContentObjectTreeNode::fetch( (int)$folders[$sofar] ) )
                {
                    $nodeID = (int)$folders[$sofar];
                    continue;
                }
                $nodeID = self::folderNode( $nodeID, $part, $parentNodeID . '|' . mb_strtolower( $sofar ) );
                $folders[$sofar] = $nodeID;
            }
            $this->update( function ( &$run ) use ( $folders ) { $run['folders'] = $folders; } );
            return $nodeID;
        }
        finally
        {
            flock( $fh, LOCK_UN );
            fclose( $fh );
        }
    }

    /** @return int the node of a child folder named $name below $parentNodeID, created when there is none */
    protected static function folderNode( $parentNodeID, $name, $key )
    {
        $class = expOdfSettings::get( 'import.class_folder' );
        $remoteID = 'ezodf-folder-' . md5( $key );
        $object = eZContentObject::fetchByRemoteID( $remoteID );
        if ( $object && $object->attribute( 'main_node_id' ) )
            return (int)$object->attribute( 'main_node_id' );
        $parent = eZContentObjectTreeNode::fetch( (int)$parentNodeID );
        if ( !$parent )
            throw new expOdfConverterException( 'convert', "the target folder (node $parentNodeID) does not exist" );
        foreach ( $parent->subTree( array( 'Depth' => 1, 'ClassFilterType' => 'include', 'ClassFilterArray' => array( $class ), 'Limit' => 200 ) ) as $child )
            if ( mb_strtolower( $child->attribute( 'name' ) ) === mb_strtolower( $name ) )
                return (int)$child->attribute( 'node_id' );
        if ( !$parent->canCreate() )
            throw new expOdfConverterException( 'convert', 'you may not create folders in "' . $parent->attribute( 'name' ) . '"' );
        $contentClass = eZContentClass::fetchByIdentifier( $class );
        if ( !$contentClass )
            throw new expOdfConverterException( 'convert', "the folder class $class does not exist" );
        $nameAttribute = $contentClass->fetchAttributeByIdentifier( 'name' ) ? 'name' : ( $contentClass->fetchAttributeByIdentifier( 'title' ) ? 'title' : '' );
        $params = array( 'class_identifier' => $class, 'parent_node_id' => (int)$parentNodeID, 'remote_id' => $remoteID,
                         'attributes' => $nameAttribute !== '' ? array( $nameAttribute => $name ) : array() );
        $object = eZContentFunctions::createAndPublishObject( $params );
        if ( !$object || !$object->attribute( 'main_node_id' ) )
            throw new expOdfConverterException( 'convert', "the folder \"$name\" could not be created" );
        return (int)$object->attribute( 'main_node_id' );
    }

    /** @return string a relative path of safe parts (a/b/file.docx), '' when nothing is left */
    public static function cleanPath( $path )
    {
        $parts = array();
        foreach ( explode( '/', str_replace( '\\', '/', (string)$path ) ) as $part )
        {
            $part = trim( $part );
            if ( $part === '' || $part === '.' || $part === '..' )
                continue;
            $part = preg_replace( '/[\x00-\x1f\x7f]+/u', '', $part );
            $part = mb_substr( $part, 0, 120 );
            if ( $part !== '' )
                $parts[] = $part;
        }
        return implode( '/', array_slice( $parts, -8 ) );
    }
}
