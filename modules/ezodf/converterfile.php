<?php
/**
 * ezodf/converterfile/<name> (policy ezodf/converter): a result of the test conversions as a download.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
/** @var eZModule $Module */
$Module = $Params['Module'];
$name = basename( (string)$Params['Name'] );
$file = expOdfConverterTest::dir() . '/' . $name;
if ( !preg_match( '/^result-[a-z0-9_-]+\.[a-z0-9]{1,8}$/', $name ) || !is_file( $file ) )
    return $Module->handleError( eZError::KERNEL_NOT_AVAILABLE, 'kernel' );
$types = array( 'pdf' => 'application/pdf', 'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
                'odp' => 'application/vnd.oasis.opendocument.presentation',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation' );
$ext = pathinfo( $name, PATHINFO_EXTENSION );
while ( @ob_end_clean() );
header( 'Content-Type: ' . ( isset( $types[$ext] ) ? $types[$ext] : 'application/octet-stream' ) );
header( 'Content-Disposition: attachment; filename="' . $name . '"' );
header( 'Content-Length: ' . filesize( $file ) );
header( 'X-Content-Type-Options: nosniff' );
readfile( $file );
eZExecution::cleanExit();
