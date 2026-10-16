<?php
/**
 * The check both conversion scripts (daemon.php, converttooo.php) run on a request before OpenOffice.org is started.
 *
 * A request is "<command> <source> <destination>", read from an unauthenticated socket. The two paths went into the
 * OpenOffice.org macro call macro:///eZconversion.Module1.<command>("<source>", "<destination>") unchecked: a quote in
 * a path ended the Basic string and the rest ran as a macro, and any file the daemon could read was converted to any
 * place it could write. Now a request names one of the three commands, two absolute paths of plain characters below
 * the directory the daemon was started with, an existing source file and a destination with the command's suffix that
 * does not exist yet.
 *
 * @license GNU General Public License v2.0
 */

/**
 * @param string $command convertToOOo, convertToPDF or convertToDoc
 * @param string $source absolute path of the document to convert
 * @param string $destination absolute path of the converted document
 * @param string $allowedDir the directory both paths must be in
 * @return string|null why the request is refused, or null when it may be converted
 */
function ezodfConversionRequestProblem( $command, $source, $destination, $allowedDir )
{
    $suffixes = array( 'convertToOOo' => 'odt', 'convertToPDF' => 'pdf', 'convertToDoc' => 'doc' );
    if ( !is_string( $command ) || !isset( $suffixes[$command] ) )
    {
        return 'unknown command';
    }
    $allowed = is_string( $allowedDir ) && $allowedDir !== '' ? realpath( $allowedDir ) : false;
    if ( $allowed === false || !is_dir( $allowed ) )
    {
        return 'no directory to convert in';
    }
    foreach ( array( 'source' => $source, 'destination' => $destination ) as $role => $path )
    {
        if ( !is_string( $path ) || !preg_match( '#^/[A-Za-z0-9._/-]+$#', $path ) )
        {
            return "the $role path is not absolute or has characters other than letters, digits, . _ / -";
        }
        if ( preg_match( '#(^|/)\.\.?(/|$)#', $path ) )
        {
            return "the $role path has a . or .. segment";
        }
        $dir = realpath( dirname( $path ) );
        if ( $dir === false || strpos( $dir . '/', rtrim( $allowed, '/' ) . '/' ) !== 0 )
        {
            return "the $role is outside the directory to convert in";
        }
    }
    if ( is_link( $source ) || !is_file( $source ) )
    {
        return 'the source file does not exist';
    }
    if ( strtolower( pathinfo( $destination, PATHINFO_EXTENSION ) ) !== $suffixes[$command] )
    {
        return 'the destination of ' . $command . ' must end in .' . $suffixes[$command];
    }
    if ( file_exists( $destination ) || is_link( $destination ) )
    {
        return 'the destination exists';
    }
    return null;
}
