<?php
/**
 * File containing the expOdfRemoteFetcher class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Downloads a document from a URL an editor gave, without letting the URL reach the server's own network
 * (server-side request forgery):
 *
 *   - http and https only, no user and password in the URL, ports 80, 443 and 8080/8443 only
 *   - the host name is resolved once, every address it resolves to must be public (no loopback, private, link-local,
 *     carrier-grade NAT, multicast, reserved, documentation, IPv6 unique-local or mapped private ranges), and the
 *     connection is pinned to the checked address (CURLOPT_RESOLVE), so a second DNS answer cannot point elsewhere
 *   - redirects are followed by hand, at most five, each checked the same way
 *   - the body is streamed to a file and stopped at remote.max_megabytes; remote.timeout for the whole download
 *   - the file name comes from Content-Disposition or the URL path, reduced to safe characters; the type is guessed
 *     from the content (finfo), not taken from the server
 * remote.allow_private_hosts=true lifts the address check (an intranet document server), never the scheme check.
 */
class expOdfRemoteFetcher
{
    /**
     * @param string $url
     * @param string $dir where the file goes
     * @return array( 'file' => path, 'name' => safe file name, 'size' => int, 'mime' => string, 'url' => the final URL )
     * @throws expOdfConverterException code 'remote'
     */
    public static function fetch( $url, $dir )
    {
        $max = expOdfSettings::get( 'remote.max_megabytes' ) * 1048576;
        $deadline = time() + expOdfSettings::get( 'remote.timeout' );
        $current = trim( (string)$url );
        for ( $hop = 0; $hop <= 5; $hop++ )
        {
            list( $host, $port, $ip ) = self::check( $current );
            $file = $dir . '/download-' . bin2hex( random_bytes( 6 ) ) . '.part';
            $fh = fopen( $file, 'wb' );
            $headers = array();
            $size = 0;
            $ch = curl_init( $current );
            curl_setopt_array( $ch, array(
                CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => max( 5, $deadline - time() ), CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => array( "$host:$port:$ip" ), CURLOPT_USERAGENT => 'Exponential ezodf/7.0 (document import)',
                CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$headers ) {
                    $p = strpos( $line, ':' );
                    if ( $p )
                        $headers[strtolower( trim( substr( $line, 0, $p ) ) )] = trim( substr( $line, $p + 1 ) );
                    return strlen( $line );
                },
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ( $ch, $total, $done ) use ( $max ) { return $done > $max || $total > $max ? 1 : 0; },
            ) );
            $ok = curl_exec( $ch );
            $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
            $error = curl_error( $ch );
            curl_close( $ch );
            fclose( $fh );
            $size = (int)@filesize( $file );
            if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) && isset( $headers['location'] ) )
            {
                @unlink( $file );
                $current = self::absolute( $current, $headers['location'] );
                continue;
            }
            if ( !$ok || $status !== 200 )
            {
                @unlink( $file );
                if ( $size > $max || stripos( $error, 'callback' ) !== false )
                    throw new expOdfConverterException( 'too_large', sprintf( 'the document at the address is larger than %d MB', $max / 1048576 ) );
                throw new expOdfConverterException( 'remote', 'the address could not be downloaded (' . ( $error !== '' ? $error : "HTTP $status" ) . ')' );
            }
            $name = self::fileName( $current, isset( $headers['content-disposition'] ) ? $headers['content-disposition'] : '' );
            $mime = function_exists( 'finfo_open' ) ? (string)finfo_file( finfo_open( FILEINFO_MIME_TYPE ), $file ) : '';
            if ( pathinfo( $name, PATHINFO_EXTENSION ) === '' )
                $name .= self::extensionForMime( $mime );
            $target = $dir . '/' . $name;
            for ( $i = 2; file_exists( $target ); $i++ )
                $target = $dir . '/' . pathinfo( $name, PATHINFO_FILENAME ) . "-$i" . ( pathinfo( $name, PATHINFO_EXTENSION ) !== '' ? '.' . pathinfo( $name, PATHINFO_EXTENSION ) : '' );
            rename( $file, $target );
            return array( 'file' => $target, 'name' => basename( $target ), 'size' => $size, 'mime' => $mime, 'url' => $current );
        }
        throw new expOdfConverterException( 'remote', 'the address redirects more than five times' );
    }

    /**
     * Checks a URL and resolves its host to one allowed address.
     * @return array( host, port, ip )
     * @throws expOdfConverterException
     */
    public static function check( $url )
    {
        $parts = parse_url( $url );
        $scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
        if ( !in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) )
            throw new expOdfConverterException( 'remote', 'only http and https addresses can be imported' );
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) )
            throw new expOdfConverterException( 'remote', 'an address with a user name or password is not imported' );
        $port = isset( $parts['port'] ) ? (int)$parts['port'] : ( $scheme === 'https' ? 443 : 80 );
        if ( !in_array( $port, array( 80, 443, 8080, 8443 ), true ) )
            throw new expOdfConverterException( 'remote', "port $port is not allowed for document addresses" );
        $host = strtolower( trim( $parts['host'], '[]' ) );
        $private = expOdfSettings::get( 'remote.allow_private_hosts' );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) )
            $ips = array( $host );
        else
        {
            if ( $host === 'localhost' || substr( $host, -6 ) === '.local' || substr( $host, -10 ) === '.localhost' || strpos( $host, '.' ) === false )
            {
                if ( !$private )
                    throw new expOdfConverterException( 'remote', 'the address names a host of the local network' );
            }
            $ips = self::resolve( $host );
            if ( !$ips )
                throw new expOdfConverterException( 'remote', "the host $host cannot be resolved" );
        }
        foreach ( $ips as $ip )
            if ( !$private && !self::isPublic( $ip ) )
                throw new expOdfConverterException( 'remote', 'the address points to a private or reserved network' );
        $ip = $ips[0];
        return array( $host, $port, strpos( $ip, ':' ) !== false ? "[$ip]" : $ip );
    }

    /** @return string[] the IPv4 and IPv6 addresses of a host */
    protected static function resolve( $host )
    {
        $ips = array();
        $records = @dns_get_record( $host, DNS_A | DNS_AAAA );
        foreach ( is_array( $records ) ? $records : array() as $r )
        {
            if ( isset( $r['ip'] ) )
                $ips[] = $r['ip'];
            if ( isset( $r['ipv6'] ) )
                $ips[] = $r['ipv6'];
        }
        if ( !$ips )
        {
            $v4 = @gethostbynamel( $host );
            if ( $v4 )
                $ips = $v4;
        }
        return array_values( array_unique( $ips ) );
    }

    /** @return bool whether an address is on the public internet */
    public static function isPublic( $ip )
    {
        if ( !filter_var( $ip, FILTER_VALIDATE_IP ) )
            return false;
        if ( !filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) )
            return false;
        if ( strpos( $ip, ':' ) === false )
        {
            $n = ip2long( $ip );
            $blocked = array( '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
                              '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24',
                              '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32' );
            foreach ( $blocked as $cidr )
            {
                list( $net, $bits ) = explode( '/', $cidr );
                $mask = $bits == 0 ? 0 : ( ~0 << ( 32 - (int)$bits ) ) & 0xFFFFFFFF;
                if ( ( $n & $mask ) === ( ip2long( $net ) & $mask ) )
                    return false;
            }
            return true;
        }
        $bin = inet_pton( $ip );
        if ( $bin === false )
            return false;
        $hex = bin2hex( $bin );
        // ::, ::1, IPv4-mapped (::ffff:0:0/96) and IPv4-compatible, unique local fc00::/7, link-local fe80::/10,
        // site-local fec0::/10, multicast ff00::/8, documentation 2001:db8::/32, NAT64 64:ff9b::/96, 6to4 of private
        if ( preg_match( '/^0{20}ffff/', $hex ) )
            return self::isPublic( inet_ntop( substr( $bin, 12 ) ) );
        if ( preg_match( '/^0{24}/', $hex ) )
            return false;
        $first = hexdec( substr( $hex, 0, 4 ) );
        if ( ( $first & 0xfe00 ) === 0xfc00 || ( $first & 0xffc0 ) === 0xfe80 || ( $first & 0xffc0 ) === 0xfec0 || ( $first & 0xff00 ) === 0xff00 )
            return false;
        if ( substr( $hex, 0, 8 ) === '20010db8' || substr( $hex, 0, 24 ) === '0064ff9b0000000000000000' )
            return false;
        if ( substr( $hex, 0, 4 ) === '2002' )
            return self::isPublic( inet_ntop( substr( $bin, 2, 4 ) ) );
        return true;
    }

    /** @return string an absolute URL of a redirect target */
    protected static function absolute( $base, $location )
    {
        if ( preg_match( '#^https?://#i', $location ) )
            return $location;
        $p = parse_url( $base );
        $origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
        if ( substr( $location, 0, 2 ) === '//' )
            return $p['scheme'] . ':' . $location;
        if ( substr( $location, 0, 1 ) === '/' )
            return $origin . $location;
        $path = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
        return $origin . $path . $location;
    }

    /** @return string a safe file name from the response or the URL */
    public static function fileName( $url, $disposition )
    {
        $name = '';
        if ( $disposition !== '' )
        {
            if ( preg_match( "/filename\\*=UTF-8''([^;]+)/i", $disposition, $m ) )
                $name = rawurldecode( $m[1] );
            elseif ( preg_match( '/filename="?([^";]+)"?/i', $disposition, $m ) )
                $name = $m[1];
        }
        if ( $name === '' )
            $name = rawurldecode( basename( (string)parse_url( $url, PHP_URL_PATH ) ) );
        return self::safeName( $name !== '' ? $name : 'document' );
    }

    /** @return string a file name of letters, digits, spaces, dots, dashes and underscores, at most 120 characters */
    public static function safeName( $name )
    {
        $name = basename( str_replace( '\\', '/', (string)$name ) );
        $name = preg_replace( '/[^\p{L}\p{N} ._()-]+/u', '_', $name );
        $name = trim( preg_replace( '/[ _]{2,}/', '_', $name ), ' ._' );
        if ( $name === '' )
            $name = 'document';
        if ( strlen( $name ) > 120 )
        {
            $ext = pathinfo( $name, PATHINFO_EXTENSION );
            $name = mb_substr( pathinfo( $name, PATHINFO_FILENAME ), 0, 100 ) . ( $ext !== '' ? '.' . substr( $ext, 0, 8 ) : '' );
        }
        return $name;
    }

    protected static function extensionForMime( $mime )
    {
        $map = array( 'application/pdf' => '.pdf', 'application/vnd.oasis.opendocument.text' => '.odt',
                      'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx', 'application/msword' => '.doc',
                      'application/rtf' => '.rtf', 'text/rtf' => '.rtf', 'text/html' => '.html', 'text/plain' => '.txt', 'text/markdown' => '.md',
                      'application/vnd.oasis.opendocument.spreadsheet' => '.ods', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
                      'application/vnd.oasis.opendocument.presentation' => '.odp', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx',
                      'application/zip' => '.zip' );
        return isset( $map[$mime] ) ? $map[$mime] : '';
    }
}
