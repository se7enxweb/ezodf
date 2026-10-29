#!/usr/bin/env php
<?php
/**
 * Runs one ezodf background job on an installation without expqueue (started by expOdfJobs; the administration
 * starts it, nobody needs to type it): php extension/ezodf/bin/php/ezodfjob.php --job=<id>
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
require_once 'autoload.php';

$cli = eZCLI::instance();
$script = eZScript::instance( array( 'description' => 'Runs one ezodf background job', 'use-session' => false, 'use-modules' => true, 'use-extensions' => true ) );
$script->startup();
$options = $script->getOptions( '[job:]', '', array( 'job' => 'the job id (var/<site>/ezodf/jobs/<id>.json)' ) );
$script->initialize();

$id = preg_replace( '/[^a-f0-9]/', '', (string)$options['job'] );
$file = expOdfPaths::base() . '/jobs/' . $id . '.json';
$job = expOdfPaths::readJson( $file );
if ( $id === '' || !$job || $job['state'] !== 'queued' )
{
    $cli->error( 'FAIL no queued ezodf job ' . $id );
    $script->shutdown( 1 );
}
$job['state'] = 'running';
$job['started'] = time();
expOdfPaths::writeJson( $file, $job );
if ( !empty( $job['user_id'] ) && ( $user = eZUser::fetch( (int)$job['user_id'] ) ) )
    $user->loginCurrent();
try
{
    $job['result'] = expOdfJobs::run( $job['type'], $job['payload'], function ( $done, $total = 0, $message = '' ) use ( $file, &$job ) {
        $job['progress'] = $message;
        $job['done'] = $done;
        $job['total'] = $total;
        expOdfPaths::writeJson( $file, $job );
    } );
    $job['state'] = 'done';
    $cli->output( 'PASS ' . $job['type'] );
}
catch ( Exception $e )
{
    $job['state'] = 'failed';
    $job['message'] = $e->getMessage();
    $cli->error( 'FAIL ' . $job['type'] . ': ' . $e->getMessage() );
}
$job['finished'] = time();
expOdfPaths::writeJson( $file, $job );
$script->shutdown( $job['state'] === 'done' ? 0 : 1 );
