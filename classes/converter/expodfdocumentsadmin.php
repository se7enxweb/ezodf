<?php
/**
 * File containing the expOdfDocumentsAdmin class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The data of the Import documents, batch and runs pages (ezodf/import, ezodf/batch, ezodf/runs).
 */
class expOdfDocumentsAdmin
{
    /** @return array the options of a new batch from the form */
    public static function optionsFromPost( eZHTTPTool $http, $nodeID )
    {
        $get = function ( $name ) use ( $http ) { return $http->hasPostVariable( $name ) ? $http->postVariable( $name ) : ''; };
        return array( 'parent_node' => (int)$nodeID, 'tree' => (bool)$get( 'Tree' ), 'replace' => (bool)$get( 'Replace' ),
                      'other_files' => (bool)$get( 'OtherFiles' ), 'language' => (string)$get( 'Language' ),
                      'ai' => (bool)$get( 'AIAssist' ) && expOdfSettings::get( 'ai.enabled' ), 'title' => (string)$get( 'Title' ) );
    }

    /**
     * The uploaded files of a field (one file or a multiple one), with the path in the chosen folder where the browser
     * sent it (PHP 8.1 full_path).
     * @return array list of array( 'tmp_name', 'path', 'size' )
     */
    public static function uploadedFiles( $field )
    {
        if ( !isset( $_FILES[$field] ) )
            return array();
        $f = $_FILES[$field];
        $out = array();
        $names = (array)$f['name'];
        foreach ( array_keys( $names ) as $k )
        {
            $error = is_array( $f['error'] ) ? $f['error'][$k] : $f['error'];
            $tmp = is_array( $f['tmp_name'] ) ? $f['tmp_name'][$k] : $f['tmp_name'];
            if ( $error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file( $tmp ) )
                continue;
            $full = isset( $f['full_path'] ) ? ( is_array( $f['full_path'] ) ? $f['full_path'][$k] : $f['full_path'] ) : '';
            $out[] = array( 'tmp_name' => $tmp, 'path' => $full !== '' ? $full : ( is_array( $f['name'] ) ? $f['name'][$k] : $f['name'] ),
                            'size' => is_array( $f['size'] ) ? $f['size'][$k] : $f['size'] );
        }
        return $out;
    }

    /** @return array what the Import documents page shows besides the target */
    public static function pageData()
    {
        $active = expOdfInstallation::active();
        $ini = eZINI::instance( 'odf.ini' );
        $types = array_values( array_unique( array_merge( (array)$ini->variable( 'DocumentType', 'AllowedTypes' ), (array)$ini->variable( 'DocumentType', 'ConvertTypes' ) ) ) );
        $groups = array();
        foreach ( $types as $ext )
            $groups[expOdfConverter::family( $ext )][] = $ext;
        $languages = array();
        foreach ( eZContentLanguage::prioritizedLanguages() as $l )
            $languages[] = array( 'locale' => $l->attribute( 'locale' ), 'name' => $l->attribute( 'name' ) );
        return array(
            'converter' => $active ? $active->version : '',
            'server' => expOdfServer::running(),
            'php_only' => $active === null && expOdfSettings::get( 'converter.remote_url' ) === '',
            'php_formats' => expOdfPhpConverter::extensions(),
            'formats' => $groups,
            'accept' => '.' . implode( ',.', array_merge( $types, array( 'zip', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ) ) ),
            'max_files' => expOdfSettings::get( 'import.max_files' ),
            'max_mb' => expOdfSettings::get( 'import.max_batch_megabytes' ),
            'max_doc_mb' => expOdfSettings::get( 'converter.max_megabytes' ),
            'max_uploads' => (int)ini_get( 'max_file_uploads' ),
            'languages' => $languages,
            'ai' => expOdfSettings::get( 'ai.enabled' ) && class_exists( 'expAI' ),
            'cron' => expOdfJobs::cronState(),
            'can_converter' => self::can( 'converter' ),
            'recent' => array_slice( self::runs(), 0, 5 ),
        );
    }

    /** @return bool */
    public static function can( $function )
    {
        $access = eZUser::currentUser()->hasAccessTo( 'ezodf', $function );
        return $access['accessWord'] !== 'no';
    }

    /** @return array the batch page's data */
    public static function batchData( expOdfBatch $batch )
    {
        $batch->reconcile();
        $run = $batch->run();
        $state = expOdfBatch::stateOf( $run );
        $counts = expOdfBatch::counts( $run );
        $items = array();
        foreach ( $run['items'] as $item )
        {
            if ( $item['kind'] === 'zip' && $item['state'] === 'done' )
                $item['state_label'] = self::label( 'unpacked' );
            else
                $item['state_label'] = self::label( $item['state'] );
            $item += array( 'code' => '', 'node_id' => 0, 'url_alias' => '', 'title' => '', 'class' => '', 'backend' => '', 'seconds' => 0, 'url' => '', 'path' => '' );
            $item['folder'] = strpos( $item['path'], '/' ) !== false ? dirname( $item['path'] ) : '';
            $items[] = $item;
        }
        usort( $items, function ( $a, $b ) { return strnatcasecmp( $a['path'] !== '' ? $a['path'] : $a['name'], $b['path'] !== '' ? $b['path'] : $b['name'] ); } );
        $finished = $counts['done'] + $counts['failed'] + $counts['skipped'] + $counts['cancelled'];
        $parent = $run['options']['parent_node'] ? eZContentObjectTreeNode::fetch( (int)$run['options']['parent_node'] ) : null;
        $user = $run['user_id'] ? eZContentObject::fetch( (int)$run['user_id'] ) : null;
        return array(
            'id' => $batch->id, 'state' => $state, 'state_label' => self::label( $state ), 'created' => $run['created'],
            'options' => $run['options'], 'counts' => $counts, 'items' => $items,
            'percent' => $counts['total'] ? (int)( floor( $finished * 100 / $counts['total'] / 4 ) * 4 ) : 0,
            'busy' => in_array( $state, expOdfBatch::STATES_OPEN, true ) && $state !== 'uploading',
            'parent' => $parent ? array( 'node_id' => (int)$parent->attribute( 'node_id' ), 'name' => $parent->attribute( 'name' ), 'url_alias' => $parent->attribute( 'url_alias' ) ) : null,
            'user' => $user ? $user->attribute( 'name' ) : '',
            'folders' => isset( $run['folders'] ) ? count( $run['folders'] ) : 0,
            'cron' => expOdfJobs::cronState(),
            'can_retry' => (bool)array_filter( $items, function ( $i ) { return in_array( $i['state'], array( 'failed', 'cancelled' ), true ) && $i['code'] !== 'refused'; } ),
        );
    }

    /** @return array the batches the current user may see */
    public static function runs()
    {
        $all = self::can( 'converter' );
        $me = (int)eZUser::currentUserID();
        $out = array();
        foreach ( expOdfBatch::listAll( 200 ) as $b )
        {
            if ( !$all && (int)$b['user_id'] !== $me )
                continue;
            $parent = $b['parent_node'] ? eZContentObjectTreeNode::fetch( (int)$b['parent_node'] ) : null;
            $b['parent_name'] = $parent ? $parent->attribute( 'name' ) : '';
            $b['state_label'] = self::label( $b['state'] );
            $user = $b['user_id'] ? eZContentObject::fetch( (int)$b['user_id'] ) : null;
            $b['user'] = $user ? $user->attribute( 'name' ) : '';
            $out[] = $b;
        }
        return $out;
    }

    /** @return string the name of a state */
    public static function label( $state )
    {
        $t = 'extension/ezodf/import';
        $labels = array( 'pending' => 'waiting', 'queued' => 'queued', 'running' => 'running', 'done' => 'done', 'failed' => 'failed',
                         'skipped' => 'skipped', 'cancelled' => 'cancelled', 'uploading' => 'uploading', 'done_with_errors' => 'done with errors',
                         'unpacked' => 'unpacked' );
        return ezpI18n::tr( $t, isset( $labels[$state] ) ? $labels[$state] : $state );
    }
}
