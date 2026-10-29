<?php
/**
 * ezodf/exportfile/<id> (policy ezodf/export): the ZIP of a folder export, for the user who started it (all users
 * with ezodf/converter).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
/** @var eZModule $Module */
$Module = $Params['Module'];
$e = expOdfExporter::fetchExport( (string)$Params['ExportID'] );
if ( !$e || empty( $e['file'] ) || ( (int)$e['user_id'] !== (int)eZUser::currentUserID() && !expOdfDocumentsAdmin::can( 'converter' ) ) )
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
$file = expOdfPaths::base() . '/exports/' . $e['id'] . '/' . basename( $e['file'] );
if ( !is_file( $file ) )
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
while ( @ob_end_clean() );
header( 'Content-Type: application/zip' );
header( 'Content-Length: ' . filesize( $file ) );
header( 'Content-Disposition: attachment; filename="' . preg_replace( '/[^A-Za-z0-9 ._()-]/', '_', basename( $file ) ) . "\"; filename*=UTF-8''" . rawurlencode( basename( $file ) ) );
header( 'X-Content-Type-Options: nosniff' );
readfile( $file );
eZExecution::cleanExit();
