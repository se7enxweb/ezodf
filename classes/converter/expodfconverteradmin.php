<?php
/**
 * File containing the expOdfConverterAdmin class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The data and the actions of the Document converter page (ezodf/converter) and its live state
 * (ezodf/converterstatus). Every action that takes long is a background job (expOdfJobs); the page only starts it.
 */
class expOdfConverterAdmin
{
    /** @return array everything the page shows, as plain arrays */
    public static function data()
    {
        $active = expOdfInstallation::active();
        $installations = array();
        foreach ( expOdfInstallation::all() as $id => $i )
        {
            $row = $i->toArray();
            $row['active'] = $active && $active->id() === $id;
            $row['size'] = $i->kind === 'downloaded' && isset( $i->record['size'] ) ? (int)$i->record['size'] : 0;
            $row['version_number'] = $i->number();
            $installations[] = $row;
        }
        $latest = self::latest();
        $installed = $active && $active->kind === 'downloaded' ? $active->number() : '';
        $downloaded = expOdfInstallation::downloaded();
        $newest = $downloaded ? $downloaded[0]->number() : '';
        $update = $latest['version'] !== '' && $newest !== '' && version_compare( $latest['version'], preg_replace( '/^(\d+\.\d+\.\d+).*$/', '$1', $newest ), '>' );
        $server = expOdfServer::status();
        $test = expOdfConverterTest::last();
        $sandbox = expOdfSandbox::probe();
        $filters = $active ? $active->filters() : array();
        $families = array();
        foreach ( $filters as $f )
        {
            $fam = $f['family'];
            if ( !isset( $families[$fam] ) )
                $families[$fam] = array( 'family' => $fam, 'import' => array(), 'export' => array(), 'legacy' => array(), 'count' => 0 );
            $families[$fam]['count']++;
            foreach ( $f['extensions'] as $ext )
            {
                $ext = strtolower( $ext );
                if ( !preg_match( '/^[a-z0-9]{1,8}$/', $ext ) )
                    continue;
                if ( $f['import'] )
                    $families[$fam]['import'][$ext] = true;
                if ( $f['export'] )
                    $families[$fam]['export'][$ext] = true;
                if ( $f['legacy'] )
                    $families[$fam]['legacy'][$ext] = true;
            }
        }
        foreach ( $families as &$fam )
        {
            foreach ( array( 'import', 'export', 'legacy' ) as $k )
            {
                $fam[$k] = array_keys( $fam[$k] );
                sort( $fam[$k] );
            }
        }
        unset( $fam );
        $order = array_flip( array( 'text', 'spreadsheet', 'presentation', 'drawing', 'formula', 'other' ) );
        uksort( $families, function ( $a, $b ) use ( $order ) { return ( isset( $order[$a] ) ? $order[$a] : 9 ) <=> ( isset( $order[$b] ) ? $order[$b] : 9 ); } );
        $settings = expOdfSettings::all();
        $sources = array();
        foreach ( array_keys( $settings ) as $k )
            $sources[$k] = expOdfSettings::source( $k );
        return array(
            'active' => $active ? $active->toArray() + array( 'version_number' => $active->number() ) : null,
            'installations' => $installations,
            'latest' => $latest,
            'update_available' => $update,
            'requirements' => expOdfInstaller::requirements(),
            'install' => expOdfInstaller::state(),
            'install_job' => expOdfJobs::last( 'ezodf.install' ),
            'server' => $server,
            'server_job' => expOdfJobs::last( 'ezodf.server' ),
            'server_last' => expOdfPaths::readJson( expOdfPaths::base() . '/server/last-action.json' ),
            'server_possible' => $active !== null && $active->python !== '',
            'test' => $test,
            'test_job' => expOdfJobs::last( 'ezodf.test' ),
            'backends' => expOdfConverter::backendStatus(),
            'sandbox' => $sandbox,
            'families' => array_values( $families ),
            'filter_count' => count( $filters ),
            'cron' => expOdfJobs::cronState(),
            'queue' => expOdfJobs::queueAvailable(),
            'settings' => $settings,
            'setting_sources' => $sources,
            'writable' => expOdfSettings::writable(),
            'log' => expOdfServer::log( 60 ),
            'usage' => expOdfCleanup::usage(),
            'samples' => array_keys( expOdfConverterTest::SAMPLES ),
            'busy' => self::busy(),
            'ai_installed' => class_exists( 'expAiConfig' ),
            'ai_available' => expOdfAiAssist::available(),
        );
    }

    /** @return bool whether a job of the page is queued or running (the page then polls) */
    public static function busy()
    {
        foreach ( array( 'ezodf.install', 'ezodf.server', 'ezodf.test' ) as $type )
        {
            $j = expOdfJobs::last( $type );
            if ( $j && in_array( $j['state'], array( 'queued', 'running' ), true ) && $j['time'] > time() - 7200 )
                return true;
        }
        $s = expOdfInstaller::state();
        return $s && $s['state'] === 'running';
    }

    /** @return array the live part, for ezodf/converterstatus */
    public static function live()
    {
        $install = expOdfInstaller::state();
        $server = expOdfServer::status();
        $pct = 0;
        if ( $install && !empty( $install['total'] ) )
            $pct = (int)min( 100, round( $install['bytes'] * 100 / $install['total'] ) );
        $jobs = array();
        foreach ( array( 'install' => 'ezodf.install', 'server' => 'ezodf.server', 'test' => 'ezodf.test' ) as $k => $type )
        {
            $j = expOdfJobs::last( $type );
            $jobs[$k] = $j ? array( 'state' => $j['state'], 'message' => $j['message'], 'progress' => isset( $j['progress'] ) ? $j['progress'] : '', 'time' => $j['time'] ) : null;
        }
        return array(
            'busy' => self::busy(),
            'install' => $install ? array( 'state' => $install['state'], 'step' => $install['step'], 'message' => $install['message'],
                                           'percent' => $pct - $pct % 4, 'bytes' => (int)$install['bytes'], 'total' => (int)$install['total'],
                                           'mb' => number_format( $install['bytes'] / 1048576, 0 ), 'total_mb' => number_format( $install['total'] / 1048576, 0 ),
                                           'log' => array_slice( isset( $install['log'] ) ? $install['log'] : array(), -12 ) ) : null,
            'server' => array( 'running' => $server['running'], 'enabled' => $server['enabled'], 'conversions' => $server['conversions'],
                               'version' => $server['version'], 'last_error' => $server['last_error'] ),
            'jobs' => $jobs,
            'cron' => expOdfJobs::cronState(),
        );
    }

    /** @return array( 'version' => string, 'checked' => int, 'error' => string ), cached six hours */
    public static function latest( $refresh = false )
    {
        $file = expOdfInstallation::downloadDir() . '/latest.json';
        $data = expOdfPaths::readJson( $file );
        if ( !$refresh && $data && $data['checked'] > time() - 21600 )
            return $data;
        if ( !$refresh && !$data )
            return array( 'version' => '', 'checked' => 0, 'error' => '' );
        try
        {
            $data = array( 'version' => expOdfInstaller::latestVersion(), 'checked' => time(), 'error' => '' );
        }
        catch ( Exception $e )
        {
            $data = array( 'version' => isset( $data['version'] ) ? $data['version'] : '', 'checked' => time(), 'error' => $e->getMessage() );
        }
        expOdfPaths::writeJson( $file, $data );
        return $data;
    }

    /**
     * Carries out one action of the page.
     * @return array( 'kind' => ok|warn|bad, 'message' => string )
     */
    public static function action( $action, eZHTTPTool $http )
    {
        $post = function ( $name, $default = '' ) use ( $http ) { return $http->hasPostVariable( $name ) ? $http->postVariable( $name ) : $default; };
        $needsWrite = array( 'install', 'update', 'remove', 'use', 'on', 'off', 'limits', 'remote', 'mode', 'ai' );
        if ( in_array( $action, $needsWrite, true ) && !expOdfSettings::writable() )
            return self::msg( 'bad', 'The configuration is managed by the deployment: the converter cannot be changed here.' );
        switch ( $action )
        {
            case 'check':
                $l = self::latest( true );
                return $l['error'] !== '' ? self::msg( 'bad', 'The download site cannot be reached: %error', array( '%error' => $l['error'] ) )
                                          : self::msg( 'ok', 'The newest LibreOffice is %version.', array( '%version' => $l['version'] ) );
            case 'install':
            case 'update':
                $req = expOdfInstaller::requirements();
                if ( !$req['ok'] )
                    return self::msg( 'bad', implode( ' ', $req['problems'] ) );
                $version = (string)$post( 'Version' );
                if ( $version !== '' && !preg_match( '/^\d+\.\d+\.\d+$/', $version ) )
                    return self::msg( 'bad', 'Not a version.' );
                expOdfJobs::start( 'ezodf.install', array( 'action' => 'install', 'version' => $version ),
                                   array( 'idempotency_key' => 'ezodf-install-' . ( $version ?: 'latest' ) . '-' . date( 'YmdHi' ) ) );
                return self::msg( 'ok', 'The installation has started in the background. This page shows its progress.' );
            case 'remove':
                $version = (string)$post( 'Version' );
                if ( !preg_match( '/^\d+\.\d+\.\d+$/', $version ) )
                    return self::msg( 'bad', 'Not a version.' );
                expOdfJobs::start( 'ezodf.install', array( 'action' => 'remove', 'version' => $version ) );
                return self::msg( 'ok', 'LibreOffice %version is being removed.', array( '%version' => $version ) );
            case 'use':
                $id = (string)$post( 'Installation' );
                $all = expOdfInstallation::all();
                if ( !isset( $all[$id] ) )
                    return self::msg( 'bad', 'That installation is not there any more.' );
                $i = $all[$id];
                $values = array( 'converter.installation' => $i->kind );
                if ( $i->kind === 'downloaded' && isset( $i->record['version'] ) )
                    $values['converter.version_pin'] = preg_replace( '/^(\d+\.\d+).*$/', '$1', $i->record['version'] );
                $system = expOdfInstallation::system();
                if ( $i->kind === 'system' && ( !$system || $system[0]->soffice !== $i->soffice ) )
                {
                    $values['converter.installation'] = 'custom';
                    $values['converter.binary'] = $i->soffice;
                }
                if ( $i->kind === 'system' )
                    $values['converter.version_pin'] = '';
                $errors = expOdfSettings::save( $values );
                if ( $errors )
                    return self::msg( 'bad', implode( ' ', $errors ) );
                if ( expOdfServer::enabled() )
                    expOdfJobs::start( 'ezodf.server', array( 'action' => 'restart' ) );
                return self::msg( 'ok', 'Conversions now use %version (%path).', array( '%version' => $i->version, '%path' => $i->root ) );
            case 'on':
                if ( !expOdfInstallation::active() )
                    return self::msg( 'bad', 'Install LibreOffice or choose an installed one first.' );
                expOdfSettings::save( array( 'converter.server_enabled' => true ) );
                expOdfServer::clearStarts();
                expOdfJobs::start( 'ezodf.server', array( 'action' => 'start' ) );
                return self::msg( 'ok', 'The conversion server is starting. The cron job keeps it running from now on, also after a restart of the server.' );
            case 'off':
                expOdfSettings::save( array( 'converter.server_enabled' => false ) );
                expOdfJobs::start( 'ezodf.server', array( 'action' => 'stop' ) );
                return self::msg( 'ok', 'The conversion server is stopping; conversions start LibreOffice per document again.' );
            case 'restart':
                expOdfServer::clearStarts();
                expOdfJobs::start( 'ezodf.server', array( 'action' => 'restart' ) );
                return self::msg( 'ok', 'The conversion server is restarting.' );
            case 'test':
                $samples = (array)$post( 'Samples', array() );
                $samples = array_values( array_intersect( $samples, array_keys( expOdfConverterTest::SAMPLES ) ) );
                expOdfJobs::start( 'ezodf.test', array( 'samples' => $samples ) );
                return self::msg( 'ok', 'The test conversions have started.' );
            case 'limits':
                return self::save( array(
                    'converter.timeout' => $post( 'Timeout' ), 'converter.max_megabytes' => $post( 'MaxMegabytes' ),
                    'converter.max_parallel' => $post( 'MaxParallel' ), 'converter.restart_after' => $post( 'RestartAfter' ),
                    'converter.memory_megabytes' => $post( 'MemoryMegabytes' ), 'retention.keep_days' => $post( 'KeepDays' ) ), true );
            case 'mode':
                return self::save( array( 'converter.mode' => $post( 'Mode' ) ), false );
            case 'remote':
                $values = array( 'converter.remote_url' => trim( (string)$post( 'RemoteURL' ) ), 'converter.remote_kind' => $post( 'RemoteKind' ),
                                 'converter.remote_username' => trim( (string)$post( 'RemoteUsername' ) ) );
                if ( $post( 'RemotePassword' ) !== '' )
                    $values['converter.remote_password'] = (string)$post( 'RemotePassword' );
                if ( $post( 'RemoteForget' ) )
                    $values = array( 'converter.remote_url' => '', 'converter.remote_username' => '', 'converter.remote_password' => '-' );
                $m = self::save( $values, false );
                if ( $m['kind'] !== 'ok' || $values['converter.remote_url'] === '' )
                    return $m;
                $t = expOdfRemoteServer::test();
                return self::msg( $t['ok'] ? 'ok' : 'warn', 'Saved. Test: %result', array( '%result' => $t['message'] ) );
            case 'ai':
                return self::save( array( 'ai.enabled' => $post( 'AIEnabled' ) ? '1' : '0' ), false );
            case 'remotetest':
                $t = expOdfRemoteServer::test();
                return self::msg( $t['ok'] ? 'ok' : 'bad', $t['message'] );
            case 'runpending':
                return expOdfJobs::runPendingNow() ? self::msg( 'ok', 'The pending jobs are running now.' ) : self::msg( 'bad', 'No PHP command line binary was found to run the jobs.' );
            case 'cleanup':
                $r = expOdfCleanup::run();
                return self::msg( 'ok', '%n item(s) removed, %mb MB freed.', array( '%n' => $r['items'], '%mb' => number_format( $r['bytes'] / 1048576, 1 ) ) );
        }
        return self::msg( 'bad', 'Unknown action.' );
    }

    protected static function save( array $values, $restart )
    {
        $errors = expOdfSettings::save( $values );
        if ( $errors )
        {
            $parts = array();
            foreach ( $errors as $k => $e )
                $parts[] = ( $k !== '' ? $k . ': ' : '' ) . $e;
            return self::msg( 'bad', 'Not saved: %errors', array( '%errors' => implode( '; ', $parts ) ) );
        }
        if ( $restart && expOdfServer::enabled() && expOdfServer::running() )
        {
            expOdfJobs::start( 'ezodf.server', array( 'action' => 'restart' ) );
            return self::msg( 'ok', 'Saved. The conversion server restarts with the new limits.' );
        }
        return self::msg( 'ok', 'Saved.' );
    }

    protected static function msg( $kind, $message, array $args = array() )
    {
        return array( 'kind' => $kind, 'message' => class_exists( 'ezpI18n' ) ? ezpI18n::tr( 'extension/ezodf/converter', $message, null, $args ) : strtr( $message, $args ) );
    }
}
