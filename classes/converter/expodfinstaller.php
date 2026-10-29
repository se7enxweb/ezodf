<?php
/**
 * File containing the expOdfInstaller class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The one-click installation of LibreOffice, without root: the official build of The Document Foundation is
 * downloaded into var/<site>/ezodf/libreoffice/, verified, unpacked and tested; the system is not touched.
 *
 * Steps (each written to libreoffice/install.json, which the Document converter page shows live):
 *   1. version   the newest release of download.documentfoundation.org/libreoffice/stable/ (or the pinned one)
 *   2. download  LibreOffice_<v>_Linux_x86-64_rpm.tar.gz (or _deb.tar.gz where rpm2cpio is missing), streamed with
 *                a size limit, from the download host or the mirror it redirects to
 *   3. verify    the SHA-256 published on the download host itself (never the mirror's) AND the OpenPGP signature
 *                (.asc) against the LibreOffice build key shipped with ezodf (share/keys/libreoffice-build.asc,
 *                fingerprint C2839ECAD9408FBE9531C3E9F434A1EFAFEEAEA3); a file that fails is deleted
 *   4. unpack    tar, then every package (rpm2cpio | cpio, or ar + tar for .deb) into libreoffice/<version>/;
 *                desktop integration and database drivers are left out
 *   5. test      soffice --version inside the sandbox; the worker's check of the uno module
 *   6. activate  installed.json lists the build; converter.installation becomes "downloaded" when it was "auto";
 *                older downloaded builds are removed once the new one passed its test
 *
 * It runs as a background job (expOdfJobs, type ezodf.install), never in a web request. The only programs it runs
 * are tar, rpm2cpio, cpio (or ar), gpg and the soffice it just verified, with fixed arguments.
 */
class expOdfInstaller
{
    const KEY_FINGERPRINT = 'C2839ECAD9408FBE9531C3E9F434A1EFAFEEAEA3';
    const MAX_DOWNLOAD = 1073741824; // 1 GB
    const MIN_FREE = 2147483648;     // 2 GB free before a download starts

    /** packages of the build that a server does not need */
    const SKIP = array( 'gnome', 'kde', 'freedesktop', 'xdg', 'postgresql', 'onlineupdate', 'librelogo', '-dict-', 'helppack',
                        'firebird', 'report-builder', 'kf5', 'qt5', 'qt6', 'gtk3', 'gtk4', 'kf6' );

    /** @var callable|null receives the state on each change */
    public $onProgress = null;

    /** @var array */
    protected $state = array();

    /** @return string */
    public static function stateFile()
    {
        return expOdfInstallation::downloadDir() . '/install.json';
    }

    /** @return array the state of the last or running installation */
    public static function state()
    {
        $s = expOdfPaths::readJson( self::stateFile() );
        if ( $s && $s['state'] === 'running' && isset( $s['updated'] ) && $s['updated'] < time() - 900 )
        {
            $s['state'] = 'failed';
            $s['message'] = 'The installation stopped without finishing (the job ended).';
        }
        return $s;
    }

    /** @return string the package format this host can unpack: rpm, deb or '' */
    public static function packageFormat()
    {
        if ( expOdfSandbox::tool( 'rpm2cpio' ) !== '' && expOdfSandbox::tool( 'cpio' ) !== '' )
            return 'rpm';
        if ( expOdfSandbox::tool( 'ar' ) !== '' && expOdfSandbox::tool( 'tar' ) !== '' )
            return 'deb';
        return '';
    }

    /**
     * What an installation needs on this host and what is missing.
     * @return array( 'ok' => bool, 'problems' => string[], 'free' => int, 'format' => string, 'gpg' => bool )
     */
    public static function requirements()
    {
        $problems = array();
        if ( PHP_OS_FAMILY !== 'Linux' || php_uname( 'm' ) !== 'x86_64' )
            $problems[] = 'The one-click installation needs Linux on x86-64 (this host: ' . PHP_OS_FAMILY . ' ' . php_uname( 'm' ) . ').';
        $format = self::packageFormat();
        if ( $format === '' )
            $problems[] = 'Neither rpm2cpio and cpio nor ar is installed, so the build cannot be unpacked.';
        if ( expOdfSandbox::tool( 'tar' ) === '' )
            $problems[] = 'tar is not installed.';
        if ( !function_exists( 'curl_init' ) )
            $problems[] = 'The PHP curl extension is missing.';
        if ( !function_exists( 'proc_open' ) )
            $problems[] = 'proc_open is disabled in PHP.';
        $base = expOdfInstallation::downloadDir();
        expOdfPaths::ensure( $base );
        $free = (int)@disk_free_space( $base );
        if ( $free && $free < self::MIN_FREE )
            $problems[] = sprintf( 'Only %.1f GB are free below var/; the installation needs about 2 GB.', $free / 1073741824 );
        return array( 'ok' => !$problems, 'problems' => $problems, 'free' => $free, 'format' => $format, 'gpg' => expOdfSandbox::tool( 'gpg' ) !== '' );
    }

    /**
     * The newest stable version on the download host (or the pinned one).
     * @return string 26.8.1
     */
    public static function latestVersion()
    {
        $base = rtrim( expOdfSettings::get( 'converter.download_url' ), '/' ) . '/';
        $index = self::fetch( $base, 2097152 );
        preg_match_all( '#href="(\d+\.\d+\.\d+)/"#', $index, $m );
        $versions = array_unique( $m[1] );
        if ( !$versions )
            throw new expOdfConverterException( 'install', 'the download host lists no LibreOffice version' );
        usort( $versions, 'version_compare' );
        $pin = expOdfSettings::get( 'converter.version_pin' );
        if ( $pin !== '' )
        {
            $match = array_values( array_filter( $versions, function ( $v ) use ( $pin ) { return strpos( $v . '.', $pin . '.' ) === 0; } ) );
            if ( !$match )
                throw new expOdfConverterException( 'install', "version $pin is not on the download host (it lists " . implode( ', ', $versions ) . ')' );
            return end( $match );
        }
        return end( $versions );
    }

    /**
     * Installs a version (from a background job).
     * @param string $version '' = the newest
     * @return array the final state
     */
    public function install( $version = '' )
    {
        $this->state = array( 'state' => 'running', 'step' => 'version', 'version' => $version, 'bytes' => 0, 'total' => 0,
                              'message' => 'Looking for the newest version…', 'started' => time(), 'log' => array() );
        $this->save();
        $dir = expOdfInstallation::downloadDir();
        $download = $dir . '/download';
        $lock = fopen( $dir . '/install.lock', 'c' );
        if ( !$lock || !flock( $lock, LOCK_EX | LOCK_NB ) )
            return $this->fail( 'Another installation is running.' );
        try
        {
            $req = self::requirements();
            if ( !$req['ok'] )
                return $this->fail( implode( ' ', $req['problems'] ) );
            $version = $version !== '' ? $version : self::latestVersion();
            if ( !preg_match( '/^\d+\.\d+\.\d+$/', $version ) )
                return $this->fail( "Not a version: $version" );
            $this->step( 'download', "Downloading LibreOffice $version…", array( 'version' => $version ) );
            $format = $req['format'];
            $name = "LibreOffice_{$version}_Linux_x86-64_{$format}.tar.gz";
            $url = rtrim( expOdfSettings::get( 'converter.download_url' ), '/' ) . "/$version/$format/x86_64/$name";
            self::removeTree( $download );
            expOdfPaths::ensure( $download );
            $archive = "$download/$name";
            $this->download( $url, $archive );

            $this->step( 'verify', 'Checking the SHA-256 checksum and the signature…' );
            $sha = self::fetch( $url . '.sha256', 4096 );
            if ( !preg_match( '/^([0-9a-f]{64})\s/i', trim( $sha ), $m ) )
                return $this->fail( 'The download host published no SHA-256 checksum for the build.', $archive );
            $actual = hash_file( 'sha256', $archive );
            if ( !hash_equals( strtolower( $m[1] ), $actual ) )
                return $this->fail( 'The SHA-256 checksum of the download does not match the published one; the file was deleted.', $archive );
            $this->log( 'SHA-256 ' . $actual . ' matches' );
            $signature = 'not checked (gpg is not installed)';
            if ( $req['gpg'] )
            {
                $asc = self::fetch( $url . '.asc', 65536 );
                file_put_contents( $archive . '.asc', $asc );
                $check = self::verifySignature( $archive, $archive . '.asc' );
                if ( !$check['ok'] )
                    return $this->fail( 'The signature of the download is not valid (' . $check['message'] . '); the file was deleted.', $archive );
                $signature = 'good signature by ' . $check['signer'];
                $this->log( 'OpenPGP: ' . $signature );
            }

            $this->step( 'unpack', 'Unpacking…' );
            $target = "$dir/$version";
            self::removeTree( $target );
            expOdfPaths::ensure( $target );
            $this->unpack( $archive, $download, $target, $format );
            $soffice = glob( "$target/opt/libreoffice*/program/soffice" ) ?: array();
            if ( !$soffice )
                return $this->fail( 'The unpacked build has no program/soffice.' );
            $soffice = realpath( $soffice[0] );

            $this->step( 'test', 'Testing the new build…' );
            $installation = new expOdfInstallation( 'downloaded', $soffice, array( 'version' => $version ) );
            $test = self::testInstallation( $installation );
            if ( !$test['ok'] )
                return $this->fail( 'The new build does not start: ' . $test['message'] );
            $this->log( $test['message'] );

            $this->step( 'activate', 'Switching to the new build…' );
            self::record( array( 'version' => $version, 'soffice' => $soffice, 'root' => $installation->root, 'installed' => time(),
                                 'verified' => 'SHA-256 ' . substr( $actual, 0, 16 ) . '…; ' . $signature, 'url' => $url,
                                 'size' => expOdfPaths::size( $target ) ) );
            if ( expOdfSettings::get( 'converter.installation' ) === 'auto' && expOdfSettings::writable() )
                expOdfSettings::save( array( 'converter.installation' => 'downloaded' ) );
            self::removeTree( $download );
            $this->removeOlder( $version );
            $this->state['state'] = 'done';
            $this->state['finished'] = time();
            $this->state['message'] = "LibreOffice $version is installed and verified ($signature).";
            $this->save();
            return $this->state;
        }
        catch ( Exception $e )
        {
            return $this->fail( $e->getMessage(), isset( $archive ) ? $archive : null );
        }
        finally
        {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    /**
     * Removes a downloaded build (system packages are never touched).
     * @return array the final state
     */
    public function remove( $version )
    {
        $this->state = array( 'state' => 'running', 'step' => 'remove', 'version' => $version, 'message' => "Removing LibreOffice $version…",
                              'started' => time(), 'log' => array(), 'bytes' => 0, 'total' => 0 );
        $this->save();
        if ( !preg_match( '/^\d+\.\d+\.\d+$/', (string)$version ) )
            return $this->fail( 'Not a version.' );
        $active = expOdfInstallation::active();
        if ( $active && $active->kind === 'downloaded' && isset( $active->record['version'] ) && $active->record['version'] === $version && expOdfServer::running() )
            expOdfServer::stop();
        self::removeTree( expOdfInstallation::downloadDir() . '/' . $version );
        self::unrecord( $version );
        if ( !expOdfInstallation::downloaded() && expOdfSettings::get( 'converter.installation' ) === 'downloaded' && expOdfSettings::writable() )
            expOdfSettings::save( array( 'converter.installation' => 'auto' ) );
        $this->state['state'] = 'done';
        $this->state['finished'] = time();
        $this->state['message'] = "LibreOffice $version was removed.";
        $this->save();
        return $this->state;
    }

    /**
     * Runs soffice --version in the sandbox and the worker's uno check.
     * @return array( 'ok' => bool, 'message' => string, 'uno' => bool )
     */
    public static function testInstallation( expOdfInstallation $installation )
    {
        $r = expOdfSandbox::run( expOdfSandbox::command( array( $installation->soffice, '--headless', '--version' ), array( $installation->root ), array(), array( 'seconds' => 60 ) ), 70 );
        $out = trim( $r['stdout'] );
        if ( $r['code'] !== 0 || stripos( $out, 'LibreOffice' ) === false )
            return array( 'ok' => false, 'message' => 'soffice --version: exit code ' . $r['code'] . ' ' . substr( trim( $r['stderr'] ), 0, 300 ), 'uno' => false );
        $uno = false;
        if ( $installation->python !== '' )
        {
            $script = expOdfServer::workerScript();
            $c = expOdfSandbox::run( expOdfSandbox::command( array( $installation->python, $script, 'check' ), array( $installation->root, dirname( $script ) ), array(), array( 'seconds' => 30 ) ), 40 );
            $check = json_decode( trim( $c['stdout'] ), true );
            $uno = is_array( $check ) && !empty( $check['uno'] );
        }
        return array( 'ok' => true, 'message' => $out . ( $uno ? '; the conversion server can use it' : '; no uno module for the conversion server (per-call conversions only)' ), 'uno' => $uno );
    }

    /**
     * Checks a detached signature with the shipped key, in a keyring of its own.
     * @return array( 'ok' => bool, 'message' => string, 'signer' => string )
     */
    public static function verifySignature( $file, $signature )
    {
        $gpg = expOdfSandbox::tool( 'gpg' );
        $home = expOdfPaths::jobDir( 'work', 0700 );
        @chmod( $home, 0700 );
        try
        {
            $key = expOdfPaths::extension() . '/share/keys/libreoffice-build.asc';
            $import = expOdfSandbox::run( array( $gpg, '--homedir', $home, '--batch', '--quiet', '--no-autostart', '--import', $key ), 30 );
            if ( $import['code'] !== 0 )
                return array( 'ok' => false, 'message' => 'the shipped key cannot be read', 'signer' => '' );
            $r = expOdfSandbox::run( array( $gpg, '--homedir', $home, '--batch', '--no-autostart', '--status-fd', '1', '--verify', $signature, $file ), 120 );
            if ( preg_match( '/^\[GNUPG:\] VALIDSIG ([0-9A-F]{40}) .* ([0-9A-F]{40})$/m', $r['stdout'], $m )
                 && ( $m[1] === self::KEY_FINGERPRINT || $m[2] === self::KEY_FINGERPRINT ) )
            {
                $signer = preg_match( '/^\[GNUPG:\] GOODSIG [0-9A-F]+ (.+)$/m', $r['stdout'], $g ) ? trim( $g[1] ) : 'LibreOffice Build Team';
                return array( 'ok' => true, 'message' => 'good', 'signer' => $signer );
            }
            if ( strpos( $r['stdout'], 'BADSIG' ) !== false )
                return array( 'ok' => false, 'message' => 'BAD signature', 'signer' => '' );
            return array( 'ok' => false, 'message' => 'no valid signature by the LibreOffice build key', 'signer' => '' );
        }
        finally
        {
            expOdfPaths::remove( $home );
        }
    }

    /** Streams a URL to a file, updating the progress. */
    protected function download( $url, $file )
    {
        $fh = fopen( $file . '.part', 'wb' );
        $ch = curl_init( $url );
        $self = $this;
        $last = 0;
        $written = 0;
        curl_setopt_array( $ch, array(
            CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 3600, CURLOPT_LOW_SPEED_LIMIT => 10240, CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Exponential ezodf/7.0 (LibreOffice installer)', CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ( $ch, $total, $done ) use ( $self, &$last ) {
                if ( $done > expOdfInstaller::MAX_DOWNLOAD )
                    return 1;
                if ( time() !== $last )
                {
                    $last = time();
                    $self->progress( $done, $total );
                }
                return 0;
            },
        ) );
        $ok = curl_exec( $ch );
        $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $effective = (string)curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL );
        $error = curl_error( $ch );
        curl_close( $ch );
        fclose( $fh );
        if ( !$ok || $status !== 200 )
        {
            @unlink( $file . '.part' );
            throw new expOdfConverterException( 'install', 'the download failed (' . ( $error !== '' ? $error : "HTTP $status" ) . ')' );
        }
        rename( $file . '.part', $file );
        $this->log( 'downloaded ' . number_format( filesize( $file ) / 1048576, 1 ) . ' MB from ' . parse_url( $effective, PHP_URL_HOST ) );
    }

    /** Called by the download: bytes done and the total. */
    public function progress( $done, $total )
    {
        $this->state['bytes'] = (int)$done;
        $this->state['total'] = (int)$total;
        $this->save();
    }

    protected function unpack( $archive, $download, $target, $format )
    {
        $tar = expOdfSandbox::tool( 'tar' );
        $r = expOdfSandbox::run( array( $tar, '-xzf', $archive, '-C', $download, '--no-same-owner', '--no-same-permissions' ), 900 );
        if ( $r['code'] !== 0 )
            throw new expOdfConverterException( 'install', 'tar could not unpack the build: ' . substr( $r['stderr'], 0, 300 ) );
        $packages = glob( "$download/*/" . ( $format === 'rpm' ? 'RPMS/*.rpm' : 'DEBS/*.deb' ) ) ?: array();
        if ( !$packages )
            throw new expOdfConverterException( 'install', 'the build contains no packages' );
        $done = 0;
        foreach ( $packages as $package )
        {
            $base = strtolower( basename( $package ) );
            $skip = false;
            foreach ( self::SKIP as $word )
                if ( strpos( $base, $word ) !== false )
                    $skip = true;
            if ( $skip )
                continue;
            if ( $format === 'rpm' )
            {
                $cpio = $package . '.cpio';
                $r = self::runToFile( array( expOdfSandbox::tool( 'rpm2cpio' ), $package ), $cpio );
                if ( $r['code'] === 0 )
                    $r = self::runFromFile( array( expOdfSandbox::tool( 'cpio' ), '-idm', '--quiet', '--no-absolute-filenames' ), $cpio, $target );
                @unlink( $cpio );
            }
            else
            {
                $work = $package . '.d';
                expOdfPaths::ensure( $work );
                $r = expOdfSandbox::run( array( expOdfSandbox::tool( 'ar' ), 'x', $package ), 300, $work );
                $data = glob( "$work/data.tar.*" ) ?: array();
                if ( $r['code'] === 0 && $data )
                    $r = expOdfSandbox::run( array( $tar, '-xf', $data[0], '-C', $target, '--no-same-owner', '--no-same-permissions' ), 600 );
                self::removeTree( $work );
            }
            if ( $r['code'] !== 0 )
                throw new expOdfConverterException( 'install', basename( $package ) . ' could not be unpacked: ' . substr( $r['stderr'], 0, 300 ) );
            $done++;
            $this->state['message'] = "Unpacking… ($done packages)";
            $this->save();
        }
        $this->log( "$done packages unpacked, " . number_format( expOdfPaths::size( $target ) / 1048576 ) . ' MB' );
    }

    /** Runs a command with its standard output going to a file. */
    protected static function runToFile( array $command, $file )
    {
        $spec = array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', $file, 'w' ), 2 => array( 'pipe', 'w' ) );
        $proc = proc_open( $command, $spec, $pipes );
        if ( !is_resource( $proc ) )
            return array( 'code' => 127, 'stderr' => 'cannot start ' . basename( $command[0] ) );
        $err = stream_get_contents( $pipes[2] );
        fclose( $pipes[2] );
        return array( 'code' => proc_close( $proc ), 'stderr' => (string)$err );
    }

    /** Runs a command with its standard input read from a file, in a directory. */
    protected static function runFromFile( array $command, $file, $cwd )
    {
        $spec = array( 0 => array( 'file', $file, 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'pipe', 'w' ) );
        $proc = proc_open( $command, $spec, $pipes, $cwd );
        if ( !is_resource( $proc ) )
            return array( 'code' => 127, 'stderr' => 'cannot start ' . basename( $command[0] ) );
        $err = stream_get_contents( $pipes[2] );
        fclose( $pipes[2] );
        return array( 'code' => proc_close( $proc ), 'stderr' => (string)$err );
    }

    /** @return string a small text resource from the download host (https only, no redirects to elsewhere) */
    public static function fetch( $url, $max )
    {
        if ( strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) ) !== 'https' )
            throw new expOdfConverterException( 'install', 'the download address must be https' );
        $ch = curl_init( $url );
        $body = '';
        curl_setopt_array( $ch, array(
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 60,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Exponential ezodf/7.0 (LibreOffice installer)',
            CURLOPT_WRITEFUNCTION => function ( $ch, $chunk ) use ( &$body, $max ) {
                $body .= $chunk;
                return strlen( $body ) > $max ? 0 : strlen( $chunk );
            },
        ) );
        $ok = curl_exec( $ch );
        $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );
        if ( !$ok || $status !== 200 )
            throw new expOdfConverterException( 'install', "$url: " . ( $error !== '' ? $error : "HTTP $status" ) );
        return $body;
    }

    /** Adds a build to installed.json. */
    protected static function record( array $build )
    {
        $file = expOdfInstallation::downloadDir() . '/installed.json';
        $data = expOdfPaths::readJson( $file );
        $builds = isset( $data['builds'] ) ? $data['builds'] : array();
        $builds = array_values( array_filter( $builds, function ( $b ) use ( $build ) { return $b['version'] !== $build['version']; } ) );
        $builds[] = $build;
        expOdfPaths::writeJson( $file, array( 'builds' => $builds ) );
    }

    protected static function unrecord( $version )
    {
        $file = expOdfInstallation::downloadDir() . '/installed.json';
        $data = expOdfPaths::readJson( $file );
        $builds = isset( $data['builds'] ) ? $data['builds'] : array();
        expOdfPaths::writeJson( $file, array( 'builds' => array_values( array_filter( $builds, function ( $b ) use ( $version ) { return $b['version'] !== $version; } ) ) ) );
    }

    /** Removes every downloaded build but $keep. */
    protected function removeOlder( $keep )
    {
        foreach ( expOdfInstallation::downloaded() as $i )
        {
            $v = isset( $i->record['version'] ) ? $i->record['version'] : '';
            if ( $v !== '' && $v !== $keep )
            {
                self::removeTree( expOdfInstallation::downloadDir() . '/' . $v );
                self::unrecord( $v );
                $this->log( "the older build $v was removed" );
            }
        }
    }

    protected static function removeTree( $dir )
    {
        if ( file_exists( $dir ) )
            expOdfPaths::remove( $dir );
    }

    protected function step( $step, $message, array $extra = array() )
    {
        $this->state = $extra + $this->state;
        $this->state['step'] = $step;
        $this->state['message'] = $message;
        $this->log( $message );
    }

    protected function log( $line )
    {
        $this->state['log'][] = date( 'H:i:s' ) . ' ' . $line;
        $this->save();
    }

    protected function fail( $message, $deleteFile = null )
    {
        if ( $deleteFile )
        {
            @unlink( $deleteFile );
            @unlink( $deleteFile . '.part' );
            @unlink( $deleteFile . '.asc' );
        }
        $this->state['state'] = 'failed';
        $this->state['finished'] = time();
        $this->state['message'] = $message;
        $this->log( 'FAILED: ' . $message );
        return $this->state;
    }

    protected function save()
    {
        $this->state['updated'] = time();
        expOdfPaths::writeJson( self::stateFile(), $this->state );
        if ( $this->onProgress )
            call_user_func( $this->onProgress, $this->state );
    }
}
