<?php
/**
 * DB-less tests of the remote document sources of ezodf for 7x SQLI Import: WebDAV and Nextcloud, Google Drive,
 * OneDrive and SharePoint, and documents attached to e-mails. No network: expOdfImportHttp::$transport answers
 * every request and a mailbox in memory answers the IMAP calls. No conversion: the tests read the generator the
 * sources hand to writeSnapshot().
 *
 * The classes of 7x SQLI Import come from its clone (they are not part of ezodf); expImport (which reads
 * sqliimport.ini through the kernel) is replaced by a stand-in with settings in memory.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

use PHPUnit\Framework\TestCase;

// 7x SQLI Import next to this extension (extension/sqliimport of the installation), or where EZODF_SQLIIMPORT_CLASSES says;
// without it the tests of the document sources are skipped
$expOdfSqliimportClasses = getenv( 'EZODF_SQLIIMPORT_CLASSES' ) ?: dirname( __DIR__, 2 ) . '/sqliimport/classes/exp';
$expOdfSqliimportFiles = array(
    'expImportException' => 'expimportexception.php',
    'expImportSourceInterface' => 'expimportsourceinterface.php',
    'expImportAbstractSource' => 'expimportabstractsource.php',
    'expImportRecord' => 'expimportrecord.php',
    'expImportOptionSchema' => 'expimportoptionschema.php',
    'expImportSecrets' => 'expimportsecrets.php',
    'expImportSourceFiles' => 'engine/expimportsourcefiles.php',
    'expImportJsonSource' => 'sources/expimportjsonsource.php',
    'expImportImapTransport' => 'mail/expimportimaptransport.php',
    'expImportMailMessage' => 'mail/expimportmailmessage.php',
    'expImportImapClient' => 'mail/expimportimapclient.php',
    'expImportImapSource' => 'sources/expimportimapsource.php',
);
$expOdfSqliimportAvailable = is_dir( $expOdfSqliimportClasses );
if ( $expOdfSqliimportAvailable )
{
    // expImport reads sqliimport.ini through the kernel's eZINI: a stand-in with settings in memory
    if ( !class_exists( 'expImport', false ) )
    {
        class expImport
        {
            /** @var array section => name => value */
            public static $settings = array();

            /** @var int|null */
            public static $now = null;

            public static function setting( $section, $name, $default = null )
            {
                return isset( self::$settings[$section] ) && array_key_exists( $name, self::$settings[$section] ) ? self::$settings[$section][$name] : $default;
            }

            public static function now()
            {
                return self::$now !== null ? (int)self::$now : time();
            }
        }
    }
    foreach ( $expOdfSqliimportFiles as $class => $file )
        if ( !class_exists( $class, false ) && !interface_exists( $class, false ) )
            require_once $expOdfSqliimportClasses . '/' . $file;

    require_once dirname( __DIR__ ) . '/classes/converter/expodfpaths.php';
    require_once dirname( __DIR__ ) . '/classes/converter/expodfremotefetcher.php';
    require_once dirname( __DIR__ ) . '/classes/sqliimport/expodfimportdocumentsource.php';
    require_once dirname( __DIR__ ) . '/classes/sqliimport/expodfimporthttp.php';
    require_once dirname( __DIR__ ) . '/classes/sqliimport/expodfimportwebdavsource.php';
    require_once dirname( __DIR__ ) . '/classes/sqliimport/expodfimportgoogledrivesource.php';
    require_once dirname( __DIR__ ) . '/classes/sqliimport/expodfimportonedrivesource.php';
    require_once dirname( __DIR__ ) . '/classes/sqliimport/expodfimportmailboxdocumentssource.php';

    /** The document extensions without odf.ini (the real list comes from the kernel's settings). */
    trait expOdfImportSourcesTestExtensions
    {
        public static function documentExtensions()
        {
            return array( 'docx', 'doc', 'odt', 'pdf', 'rtf', 'md', 'xlsx', 'ods', 'pptx', 'odp' );
        }
    }

    class expOdfImportSourcesTestWebdav extends expOdfImportWebdavSource
    {
        use expOdfImportSourcesTestExtensions;
    }

    class expOdfImportSourcesTestDrive extends expOdfImportGoogleDriveSource
    {
        use expOdfImportSourcesTestExtensions;
    }

    class expOdfImportSourcesTestOneDrive extends expOdfImportOneDriveSource
    {
        use expOdfImportSourcesTestExtensions;
    }

    class expOdfImportSourcesTestMailbox extends expOdfImportMailboxDocumentsSource
    {
        use expOdfImportSourcesTestExtensions;
    }

    /** A mailbox in memory. */
    class expOdfImportSourcesTestImap implements expImportImapTransport
    {
        public $messages;
        public $opened = null;
        public $closed = false;
        public $seen = array();
        public $searches = array();
        public $failOpen = null;

        public function __construct( array $messages )
        {
            $this->messages = $messages;
        }

        public function open( $host, $port, $encryption, $user, $password, $mailbox, $readOnly )
        {
            if ( $this->failOpen !== null )
                throw new expImportSourceException( $this->failOpen );
            $this->opened = array( $host, $port, $encryption, $user, $password, $mailbox, $readOnly );
        }

        public function search( $criteria )
        {
            $this->searches[] = $criteria;
            return array_keys( $this->messages );
        }

        public function size( $uid )
        {
            return strlen( $this->messages[$uid] );
        }

        public function message( $uid, $headersOnly = false )
        {
            return $this->messages[$uid];
        }

        public function markSeen( $uid )
        {
            if ( !empty( $this->opened[6] ) )
                throw new expImportSourceException( 'the mailbox is read-only' );
            $this->seen[] = $uid;
        }

        public function close()
        {
            $this->closed = true;
        }
    }
}

class expOdfImportSourcesTest extends TestCase
{
    const WEBDAV_PASSWORD = 'dav-Pass-4711-value';
    const GRAPH_SECRET = 'graph~Secret.0815-value';
    const IMAP_PASSWORD = 'imap-Pass-1234-value';

    /** @var string */
    protected $dir;

    /** @var array list of array( method, url, headers, body ) */
    protected $calls = array();

    /** @var array list of array( method, url pattern, response or callable ) */
    protected $routes = array();

    protected function setUp(): void
    {
        if ( !class_exists( 'expOdfImportHttp', false ) )
            $this->markTestSkipped( 'The classes of 7x SQLI Import are needed (EZODF_SQLIIMPORT_CLASSES).' );
        $this->dir = sys_get_temp_dir() . '/ezodf-sources-' . bin2hex( random_bytes( 6 ) );
        mkdir( $this->dir, 0700, true );
        expImport::$settings = array( 'SourceSettings' => array( 'AllowPrivateHosts' => 'enabled', 'MaxSourceMegabytes' => 1 ) );
        expImport::$now = null;
        putenv( 'EXPODF_TEST_DAV=' . self::WEBDAV_PASSWORD );
        putenv( 'EXPODF_TEST_GRAPH=' . self::GRAPH_SECRET );
        putenv( 'EXPODF_TEST_IMAP=' . self::IMAP_PASSWORD );
        $this->calls = array();
        $this->routes = array();
        $test = $this;
        expOdfImportHttp::$transport = function ( $method, $url, $headers, $body ) use ( $test ) {
            return $test->answer( $method, $url, $headers, $body );
        };
        expOdfImportMailboxDocumentsSource::$transport = null;
    }

    protected function tearDown(): void
    {
        expOdfImportHttp::$transport = null;
        expOdfImportMailboxDocumentsSource::$transport = null;
        putenv( 'EXPODF_TEST_DAV' );
        putenv( 'EXPODF_TEST_GRAPH' );
        putenv( 'EXPODF_TEST_DRIVE' );
        putenv( 'EXPODF_TEST_IMAP' );
        if ( $this->dir && is_dir( $this->dir ) )
            $this->removeTree( $this->dir );
    }

    private function removeTree( $dir )
    {
        foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $f )
            $f->isDir() && !$f->isLink() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
        rmdir( $dir );
    }

    /** The fake network: the first route whose method and pattern match answers. */
    public function answer( $method, $url, array $headers, $body )
    {
        $this->calls[] = array( $method, $url, $headers, $body );
        foreach ( $this->routes as $route )
        {
            if ( $route[0] !== $method || !preg_match( $route[1], $url ) )
                continue;
            return is_callable( $route[2] ) ? call_user_func( $route[2], $url, $headers, $body ) : $route[2];
        }
        return array( 404, array(), 'not found' );
    }

    private function route( $method, $pattern, $response )
    {
        $this->routes[] = array( $method, $pattern, $response );
    }

    /** @return array the documents a generator yields */
    private function collect( $generator )
    {
        $out = array();
        foreach ( $generator as $doc )
            $out[] = $doc;
        return $out;
    }

    /** @return array path => document */
    private function byPath( array $docs )
    {
        $out = array();
        foreach ( $docs as $doc )
            $out[$doc['path']] = $doc;
        return $out;
    }

    // ---- the catalogue entries -------------------------------------------------------------------------------------

    public static function sourceClasses()
    {
        return array(
            'webdav' => array( 'expOdfImportWebdavSource', 'webdav', 'content', 'webdav-{id}' ),
            'google_drive' => array( 'expOdfImportGoogleDriveSource', 'google_drive', 'content', 'gdrive-{id}' ),
            'onedrive' => array( 'expOdfImportOneDriveSource', 'onedrive', 'content', 'onedrive-{id}' ),
            'mailbox_documents' => array( 'expOdfImportMailboxDocumentsSource', 'mailbox_documents', 'other', 'mail-{id}' ),
        );
    }

    /** @dataProvider sourceClasses */
    #[\PHPUnit\Framework\Attributes\DataProvider('sourceClasses')]
    public function testCatalogueEntry( $class, $identifier, $group, $pattern )
    {
        $this->assertSame( $identifier, $class::identifier() );
        $this->assertSame( $group, $class::group() );
        $this->assertSame( array( 'remote' ), $class::origins() );
        $this->assertSame( array(), $class::extensions() );
        $this->assertNotSame( '', $class::label() );
        $this->assertGreaterThan( 80, strlen( $class::description() ) );
        $this->assertSame( array(), expImportOptionSchema::check( $class::schema() ) );
        foreach ( $class::schema() as $name => $def )
        {
            $this->assertArrayHasKey( 'label', $def, "$class $name" );
            if ( $def['type'] === 'secret' )
                $this->assertArrayHasKey( 'help', $def, "$class $name" );
        }
        $preset = $class::preset();
        $this->assertSame( $pattern, $preset['match']['pattern'] );
        $this->assertTrue( method_exists( $class, 'fetch' ) );
    }

    public function testMailboxSchemaReusesTheImapSchemaWithoutAttachments()
    {
        $schema = expOdfImportMailboxDocumentsSource::schema();
        foreach ( array( 'host', 'port', 'encryption', 'user', 'password', 'mailbox', 'search', 'days', 'mark_seen' ) as $name )
            $this->assertSame( expImportImapSource::schema()[$name], $schema[$name] );
        $this->assertArrayNotHasKey( 'attachments', $schema );
        $this->assertSame( 'secret', $schema['password']['type'] );
    }

    // ---- the HTTP helper -------------------------------------------------------------------------------------------

    public function testRedirectsAreFollowedByHandAndCheckedAgain()
    {
        $this->route( 'GET', '#^https://a\.example\.test/one$#', array( 302, array( 'Location' => '/two' ), '' ) );
        $this->route( 'GET', '#^https://a\.example\.test/two$#', array( 301, array( 'Location' => 'https://b.example.test/three' ), '' ) );
        $this->route( 'GET', '#^https://b\.example\.test/three$#', array( 200, array( 'Content-Type' => 'text/plain' ), 'done' ) );
        $r = expOdfImportHttp::request( 'GET', 'https://a.example.test/one', array( 'Authorization' => 'Bearer t0ken-value' ) );
        $this->assertSame( 200, $r['status'] );
        $this->assertSame( 'done', $r['body'] );
        $this->assertSame( 'text/plain', $r['headers']['content-type'] );
        $this->assertSame( 'https://b.example.test/three', $r['url'] );
        $this->assertCount( 3, $this->calls );
        $this->assertArrayHasKey( 'Authorization', $this->calls[1][2], 'same host: the header stays' );
        $this->assertArrayNotHasKey( 'Authorization', $this->calls[2][2], 'another host: no Authorization' );
    }

    public function testRedirectIntoAPrivateNetworkIsRefused()
    {
        expImport::$settings['SourceSettings']['AllowPrivateHosts'] = 'disabled';
        $this->route( 'GET', '#^https://93\.184\.215\.14/#', array( 302, array( 'Location' => 'http://127.0.0.1/admin' ), '' ) );
        try
        {
            expOdfImportHttp::request( 'GET', 'https://93.184.215.14/doc.pdf' );
            $this->fail( 'the redirect was followed' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'private or reserved', $e->getMessage() );
        }
        $this->assertCount( 1, $this->calls );
    }

    public function testAtMostThreeRedirects()
    {
        $this->route( 'GET', '#^https://loop\.example\.test/#', array( 302, array( 'Location' => '/again' ), '' ) );
        $this->expectException( expImportSourceException::class );
        $this->expectExceptionMessage( 'redirects more than 3 times' );
        try
        {
            expOdfImportHttp::request( 'GET', 'https://loop.example.test/start' );
        }
        finally
        {
            $this->assertCount( 4, $this->calls );
        }
    }

    public function testSchemesCredentialsAndSizeAreChecked()
    {
        foreach ( array( 'file:///etc/passwd', 'ftp://example.test/a.pdf', 'https://user:pass@example.test/a.pdf' ) as $url )
        {
            try
            {
                expOdfImportHttp::request( 'GET', $url );
                $this->fail( "$url was requested" );
            }
            catch ( expImportSourceException $e )
            {
                $this->assertStringNotContainsString( 'pass@', $e->getMessage() );
            }
        }
        $this->assertCount( 0, $this->calls );
        $this->route( 'GET', '#/big$#', array( 200, array(), str_repeat( 'x', 1048577 ) ) );
        $this->expectExceptionMessage( 'MaxSourceMegabytes' );
        expOdfImportHttp::request( 'GET', 'https://example.test/big' );
    }

    public function testDisplayDropsQueryAndCredentials()
    {
        $this->assertSame( 'https://dl.example.test/file', expOdfImportHttp::display( 'https://u:p@dl.example.test/file?tempauth=abc#x' ) );
        $this->assertSame( '_', expOdfImportHttp::segment( '..' ) );
        $this->assertSame( 'a_b', expOdfImportHttp::segment( 'a/b' ) );
    }

    // ---- WebDAV ----------------------------------------------------------------------------------------------------

    private function multistatus( array $responses )
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">';
        foreach ( $responses as $r )
        {
            $type = !empty( $r['collection'] ) ? '<d:resourcetype><d:collection/></d:resourcetype>' : '<d:resourcetype/>';
            $xml .= '<d:response><d:href>' . htmlspecialchars( $r['href'] ) . '</d:href><d:propstat><d:prop>' . $type
                  . ( isset( $r['etag'] ) ? '<d:getetag>&quot;' . $r['etag'] . '&quot;</d:getetag>' : '' )
                  . '<d:getlastmodified>Tue, 20 Oct 2026 10:00:00 GMT</d:getlastmodified>'
                  . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>'
                  . '<d:propstat><d:prop><oc:size/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat></d:response>';
        }
        return $xml . '</d:multistatus>';
    }

    private function webdavServer()
    {
        $base = '/remote.php/dav/files/editor/Documents';
        $this->route( 'PROPFIND', '#^https://cloud\.example\.test' . preg_quote( $base, '#' ) . '/$#', array( 207, array( 'Content-Type' => 'application/xml' ), $this->multistatus( array(
            array( 'href' => $base . '/', 'collection' => true ),
            array( 'href' => $base . '/Annual%20Report.docx', 'etag' => 'e1' ),
            array( 'href' => $base . '/picture.png', 'etag' => 'e2' ),
            array( 'href' => $base . '/broken.pdf', 'etag' => 'e3' ),
            array( 'href' => $base . '/Press/', 'collection' => true ),
            array( 'href' => '/remote.php/dav/files/other/secret.docx', 'etag' => 'x1' ),
            array( 'href' => $base . '/%2e%2e/escape.docx', 'etag' => 'x2' ),
            array( 'href' => 'https://elsewhere.example.test' . $base . '/foreign.docx', 'etag' => 'x3' ),
        ) ) ) );
        $this->route( 'PROPFIND', '#^https://cloud\.example\.test' . preg_quote( $base, '#' ) . '/Press/$#', array( 207, array(), $this->multistatus( array(
            array( 'href' => 'https://cloud.example.test' . $base . '/Press/', 'collection' => true ),
            array( 'href' => $base . '/Press/release.odt', 'etag' => 'e4' ),
            array( 'href' => $base . '/Press/Archive/', 'collection' => true ),
        ) ) ) );
        $this->route( 'PROPFIND', '#/Press/Archive/$#', array( 207, array(), $this->multistatus( array(
            array( 'href' => $base . '/Press/Archive/', 'collection' => true ),
            array( 'href' => $base . '/Press/Archive/old.rtf', 'etag' => 'e5' ),
        ) ) ) );
        $this->route( 'GET', '#/broken\.pdf$#', array( 500, array(), 'error' ) );
        $this->route( 'GET', '#^https://cloud\.example\.test/#', function ( $url ) {
            return array( 200, array(), 'content of ' . rawurldecode( basename( $url ) ) );
        } );
        return 'https://cloud.example.test' . $base . '/';
    }

    private function webdavOptions( $url, array $more = array() )
    {
        return $more + array( 'url' => $url, 'user' => 'editor', 'password' => 'env:EXPODF_TEST_DAV', 'depth' => 2, 'folders' => true, 'trusted' => false );
    }

    public function testWebdavListsFolderByFolderDownToTheDepth()
    {
        $url = $this->webdavServer();
        $docs = $this->collect( expOdfImportSourcesTestWebdav::documents( $this->webdavOptions( $url ), $this->dir ) );
        $paths = $this->byPath( $docs );

        $this->assertArrayHasKey( 'Annual Report.docx', $paths );
        $this->assertArrayHasKey( 'Press/release.odt', $paths );
        $this->assertArrayNotHasKey( 'picture.png', $paths, 'not a document extension' );
        $this->assertArrayNotHasKey( 'Press/Archive/old.rtf', $paths, 'below the depth' );

        $doc = $paths['Annual Report.docx'];
        $this->assertSame( 'content of Annual Report.docx', file_get_contents( $doc['file'] ) );
        $this->assertStringStartsWith( $this->dir . '/', $doc['file'] );
        $this->assertSame( $url . 'Annual%20Report.docx', $doc['source_url'] );
        $this->assertSame( 'e1', $doc['etag'] );
        $this->assertSame( 'Annual Report.docx', $doc['id'] );
        $this->assertSame( strtotime( 'Tue, 20 Oct 2026 10:00:00 GMT' ), strtotime( $doc['modified'] ) );

        $this->assertArrayHasKey( 'error', $paths['broken.pdf'], 'a failed download is a failed row' );
        $this->assertStringContainsString( 'HTTP 500', $paths['broken.pdf']['error'] );

        // three entries outside the folder: refused, never requested
        $refused = array_filter( $docs, function ( $d ) { return strpos( $d['path'], '_refused/' ) === 0; } );
        $this->assertCount( 3, $refused );
        foreach ( $this->calls as $call )
        {
            $this->assertStringNotContainsString( 'secret.docx', $call[1] );
            $this->assertStringNotContainsString( 'escape.docx', $call[1] );
            $this->assertStringNotContainsString( 'elsewhere', $call[1] );
        }

        $propfinds = array_values( array_filter( $this->calls, function ( $c ) { return $c[0] === 'PROPFIND'; } ) );
        $this->assertCount( 2, $propfinds, 'depth 2: the folder and Press, not Archive' );
        $this->assertSame( '1', $propfinds[0][2]['Depth'] );
        $this->assertStringContainsString( '<d:propfind', $propfinds[0][3] );
        foreach ( $this->calls as $call )
            $this->assertSame( 'Basic ' . base64_encode( 'editor:' . self::WEBDAV_PASSWORD ), $call[2]['Authorization'] );
    }

    public function testWebdavDepthOneReadsOnlyTheFolder()
    {
        $url = $this->webdavServer();
        $docs = $this->collect( expOdfImportSourcesTestWebdav::documents( $this->webdavOptions( $url, array( 'depth' => 1 ) ), $this->dir ) );
        $this->assertArrayNotHasKey( 'Press/release.odt', $this->byPath( $docs ) );
        $this->assertCount( 1, array_filter( $this->calls, function ( $c ) { return $c[0] === 'PROPFIND'; } ) );
    }

    public function testWebdavPrivateHostOnlyWhenTrusted()
    {
        expImport::$settings['SourceSettings']['AllowPrivateHosts'] = 'disabled';
        $url = 'https://10.20.30.40/dav/';
        $this->route( 'PROPFIND', '#^https://10\.20\.30\.40/dav/$#', array( 207, array(), $this->multistatus( array(
            array( 'href' => '/dav/', 'collection' => true ), array( 'href' => '/dav/a.pdf', 'etag' => 'a' ) ) ) ) );
        $this->route( 'GET', '#^https://10\.20\.30\.40/dav/a\.pdf$#', array( 200, array(), '%PDF-1.4' ) );
        try
        {
            $this->collect( expOdfImportSourcesTestWebdav::documents( $this->webdavOptions( $url ), $this->dir ) );
            $this->fail( 'a private host was read without trust' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'private or reserved', $e->getMessage() );
        }
        $this->assertCount( 0, $this->calls );

        $docs = $this->collect( expOdfImportSourcesTestWebdav::documents( $this->webdavOptions( $url, array( 'trusted' => true ) ), $this->dir ) );
        $this->assertSame( array( 'a.pdf' ), array_keys( $this->byPath( $docs ) ) );
    }

    public function testWebdavPasswordNeverInMessages()
    {
        $auth = base64_encode( 'editor:' . self::WEBDAV_PASSWORD );
        $this->route( 'PROPFIND', '#.#', array( 401, array(), 'denied' ) );
        try
        {
            $this->collect( expOdfImportSourcesTestWebdav::documents( $this->webdavOptions( 'https://cloud.example.test/dav/' ), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'refused the user and password', $e->getMessage() );
            $this->assertStringNotContainsString( self::WEBDAV_PASSWORD, $e->getMessage() );
            $this->assertStringNotContainsString( $auth, $e->getMessage() );
        }

        // an error that quotes the secret is masked
        $this->routes = array();
        $this->route( 'PROPFIND', '#.#', function () use ( $auth ) {
            throw new expImportSourceException( 'proxy said: Basic ' . $auth . ' / ' . self::WEBDAV_PASSWORD );
        } );
        try
        {
            $this->collect( expOdfImportSourcesTestWebdav::documents( $this->webdavOptions( 'https://cloud.example.test/dav/' ), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringNotContainsString( self::WEBDAV_PASSWORD, $e->getMessage() );
            $this->assertStringNotContainsString( $auth, $e->getMessage() );
        }
    }

    public function testWebdavHrefsOutsideTheFolderAreRefused()
    {
        $start = expOdfImportWebdavSource::start( 'https://cloud.example.test/remote.php/dav/files/editor/Docs/' );
        $this->assertSame( '', expOdfImportWebdavSource::relativeOf( '/remote.php/dav/files/editor/Docs/', $start ) );
        $this->assertSame( '', expOdfImportWebdavSource::relativeOf( '/remote.php/dav/files/editor/Docs', $start ) );
        $this->assertSame( 'A b/c.docx', expOdfImportWebdavSource::relativeOf( '/remote.php/dav/files/editor/Docs/A%20b/c.docx', $start ) );
        $this->assertSame( 'x.pdf', expOdfImportWebdavSource::relativeOf( 'https://cloud.example.test/remote.php/dav/files/editor/Docs/x.pdf', $start ) );
        foreach ( array( '/remote.php/dav/files/editor/Docs/../../other/x.pdf', '/remote.php/dav/files/editor/Docs/%2E%2E/x.pdf',
                         '/remote.php/dav/files/editor/Docsx/x.pdf', '/remote.php/dav/files/other/x.pdf', '//evil.example.test/x.pdf',
                         'https://evil.example.test/remote.php/dav/files/editor/Docs/x.pdf', 'http://cloud.example.test/remote.php/dav/files/editor/Docs/x.pdf',
                         'https://cloud.example.test:8443/remote.php/dav/files/editor/Docs/x.pdf', 'relative/x.pdf',
                         '/remote.php/dav/files/editor/Docs/a%00.pdf', '/remote.php/dav/files/editor/Docs/a%5C..%5Cb.pdf' ) as $href )
            $this->assertNull( expOdfImportWebdavSource::relativeOf( $href, $start ), $href );
        $this->expectException( expImportSourceException::class );
        expOdfImportWebdavSource::start( 'https://user:pw@cloud.example.test/dav/' );
    }

    public function testWebdavRefusesADoctype()
    {
        $start = expOdfImportWebdavSource::start( 'https://cloud.example.test/dav/' );
        $this->expectException( expImportSourceException::class );
        expOdfImportWebdavSource::parseMultistatus( '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><d:multistatus xmlns:d="DAV:"/>', $start );
    }

    // ---- Google Drive ----------------------------------------------------------------------------------------------

    private function serviceAccount()
    {
        $key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
        if ( !$key )
            $this->markTestSkipped( 'openssl cannot create an RSA key here' );
        openssl_pkey_export( $key, $pem );
        $public = openssl_pkey_get_details( $key )['key'];
        $json = json_encode( array( 'type' => 'service_account', 'client_email' => 'importer@project.iam.example.test',
                                    'private_key' => $pem, 'token_uri' => 'https://evil.example.test/token' ) );
        putenv( 'EXPODF_TEST_DRIVE=' . $json );
        return array( $pem, $public );
    }

    private function driveOptions( array $more = array() )
    {
        return $more + array( 'folder_id' => 'rootFolder_1', 'credentials' => 'env:EXPODF_TEST_DRIVE', 'depth' => 2, 'folders' => true, 'include_google_docs' => true );
    }

    private function driveServer()
    {
        $this->route( 'POST', '#^https://oauth2\.googleapis\.com/token$#', array( 200, array(), json_encode( array( 'access_token' => 'ya29.drive-token', 'expires_in' => 3600 ) ) ) );
        $list = function ( $url ) {
            parse_str( (string)parse_url( $url, PHP_URL_QUERY ), $q );
            $this->assertSame( 'nextPageToken,files(id,name,mimeType,modifiedTime,size,md5Checksum)', $q['fields'] );
            $this->assertSame( 'true', $q['supportsAllDrives'] );
            $this->assertSame( 'true', $q['includeItemsFromAllDrives'] );
            $this->assertSame( '200', $q['pageSize'] );
            preg_match( "/^'([^']+)' in parents and trashed=false$/", $q['q'], $m );
            $folder = $m[1];
            $page = isset( $q['pageToken'] ) ? $q['pageToken'] : '';
            $files = array();
            $next = null;
            if ( $folder === 'rootFolder_1' && $page === '' )
            {
                $files = array(
                    array( 'id' => 'docA', 'name' => 'Minutes', 'mimeType' => 'application/vnd.google-apps.document', 'modifiedTime' => '2026-10-01T08:00:00Z' ),
                    array( 'id' => 'pdfB', 'name' => 'Budget.pdf', 'mimeType' => 'application/pdf', 'modifiedTime' => '2026-10-02T08:00:00Z', 'md5Checksum' => 'abc123', 'size' => '8' ),
                    array( 'id' => 'pngC', 'name' => 'logo.png', 'mimeType' => 'image/png' ),
                );
                $next = 'page-two';
            }
            elseif ( $folder === 'rootFolder_1' && $page === 'page-two' )
                $files = array(
                    array( 'id' => 'sheetD', 'name' => 'Figures', 'mimeType' => 'application/vnd.google-apps.spreadsheet' ),
                    array( 'id' => 'formE', 'name' => 'Survey', 'mimeType' => 'application/vnd.google-apps.form' ),
                    array( 'id' => 'sub1', 'name' => 'Sub', 'mimeType' => 'application/vnd.google-apps.folder' ),
                );
            elseif ( $folder === 'sub1' )
                $files = array(
                    array( 'id' => 'slidesF', 'name' => 'Deck', 'mimeType' => 'application/vnd.google-apps.presentation' ),
                    array( 'id' => 'sub2', 'name' => 'Deeper', 'mimeType' => 'application/vnd.google-apps.folder' ),
                );
            elseif ( $folder === 'sub2' )
                $files = array( array( 'id' => 'deepG', 'name' => 'deep.docx', 'mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ) );
            return array( 200, array( 'Content-Type' => 'application/json' ), json_encode( array( 'files' => $files ) + ( $next ? array( 'nextPageToken' => $next ) : array() ) ) );
        };
        $this->route( 'GET', '#^https://www\.googleapis\.com/drive/v3/files\?#', $list );
        $this->route( 'GET', '#^https://www\.googleapis\.com/drive/v3/files/([A-Za-z0-9_-]+)(/export)?\?#', function ( $url ) {
            preg_match( '#/files/([A-Za-z0-9_-]+)#', $url, $m );
            return array( 200, array(), 'bytes of ' . $m[1] );
        } );
    }

    public function testDriveSignsAJwtAndExchangesItForAToken()
    {
        list( , $public ) = $this->serviceAccount();
        $this->route( 'POST', '#^https://oauth2\.googleapis\.com/token$#', array( 200, array(), json_encode( array( 'access_token' => 'ya29.drive-token' ) ) ) );
        $before = time();
        $token = expOdfImportGoogleDriveSource::accessToken( json_decode( getenv( 'EXPODF_TEST_DRIVE' ), true ) );
        $this->assertSame( 'ya29.drive-token', $token );
        $this->assertCount( 1, $this->calls, 'the token_uri of the key is not used' );
        list( $method, $url, $headers, $body ) = $this->calls[0];
        $this->assertSame( 'https://oauth2.googleapis.com/token', $url );
        $this->assertSame( 'application/x-www-form-urlencoded', $headers['Content-Type'] );
        parse_str( $body, $form );
        $this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type'] );

        $parts = explode( '.', $form['assertion'] );
        $this->assertCount( 3, $parts );
        $decode = function ( $s ) { return base64_decode( strtr( $s, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); };
        $this->assertSame( array( 'alg' => 'RS256', 'typ' => 'JWT' ), json_decode( $decode( $parts[0] ), true ) );
        $claims = json_decode( $decode( $parts[1] ), true );
        $this->assertSame( 'importer@project.iam.example.test', $claims['iss'] );
        $this->assertSame( 'https://www.googleapis.com/auth/drive.readonly', $claims['scope'] );
        $this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
        $this->assertGreaterThanOrEqual( $before, $claims['iat'] );
        $this->assertSame( 3600, $claims['exp'] - $claims['iat'] );
        $this->assertSame( 1, openssl_verify( $parts[0] . '.' . $parts[1], $decode( $parts[2] ), $public, OPENSSL_ALGO_SHA256 ), 'the signature verifies with the public key' );
        $this->assertStringNotContainsString( '=', $form['assertion'] );
    }

    public function testDriveListsPagesRecursesAndExportsGoogleFiles()
    {
        $this->serviceAccount();
        $this->driveServer();
        $docs = $this->collect( expOdfImportSourcesTestDrive::documents( $this->driveOptions(), $this->dir ) );
        $paths = $this->byPath( $docs );
        $this->assertSame( array( 'Minutes.docx', 'Budget.pdf', 'Figures.xlsx', 'Sub/Deck.pptx' ), array_keys( $paths ),
                           'second page read, the form and the PNG left out, depth 2 stops before Deeper' );

        $this->assertSame( 'bytes of docA', file_get_contents( $paths['Minutes.docx']['file'] ) );
        $this->assertSame( 'docA', $paths['Minutes.docx']['id'] );
        $this->assertSame( 'docA', $paths['Minutes.docx']['drive_id'] );
        $this->assertSame( 'application/vnd.google-apps.document', $paths['Minutes.docx']['mime'] );
        $this->assertSame( '2026-10-01T08:00:00Z', $paths['Minutes.docx']['modified'] );
        $this->assertSame( 'https://drive.google.com/file/d/docA/view', $paths['Minutes.docx']['source_url'] );
        $this->assertSame( 'abc123', $paths['Budget.pdf']['md5'] );

        $byUrl = array();
        foreach ( $this->calls as $call )
            if ( $call[0] === 'GET' && strpos( $call[1], '/files/' ) !== false )
                $byUrl[] = $call[1];
        $this->assertContains( 'https://www.googleapis.com/drive/v3/files/docA/export?mimeType=' . rawurlencode( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ), $byUrl );
        $this->assertContains( 'https://www.googleapis.com/drive/v3/files/sheetD/export?mimeType=' . rawurlencode( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ), $byUrl );
        $this->assertContains( 'https://www.googleapis.com/drive/v3/files/slidesF/export?mimeType=' . rawurlencode( 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ), $byUrl );
        $this->assertContains( 'https://www.googleapis.com/drive/v3/files/pdfB?alt=media&supportsAllDrives=true', $byUrl );
        foreach ( $this->calls as $call )
            if ( strpos( $call[1], 'www.googleapis.com' ) !== false )
                $this->assertSame( 'Bearer ya29.drive-token', $call[2]['Authorization'] );

        $lists = array_filter( $this->calls, function ( $c ) { return strpos( $c[1], '/drive/v3/files?' ) !== false; } );
        $this->assertCount( 3, $lists, 'two pages of the folder and one of Sub' );

        // depth 3 reaches Deeper; without Google Docs only the files remain
        $this->calls = array();
        $deep = $this->byPath( $this->collect( expOdfImportSourcesTestDrive::documents( $this->driveOptions( array( 'depth' => 3, 'include_google_docs' => false ) ), $this->dir ) ) );
        $this->assertSame( array( 'Budget.pdf', 'Sub/Deeper/deep.docx' ), array_keys( $deep ) );
    }

    public function testDriveFailuresNeverShowTheKey()
    {
        list( $pem ) = $this->serviceAccount();
        $this->route( 'POST', '#token$#', array( 400, array(), json_encode( array( 'error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.' ) ) ) );
        try
        {
            $this->collect( expOdfImportSourcesTestDrive::documents( $this->driveOptions(), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'could not sign in', $e->getMessage() );
            $this->assertStringContainsString( 'Invalid JWT Signature', $e->getMessage() );
            $this->assertStringNotContainsString( 'PRIVATE KEY', $e->getMessage() );
        }

        // a key that is not one, and a folder ID with path tricks
        putenv( 'EXPODF_TEST_DRIVE=' . json_encode( array( 'client_email' => 'x@example.test', 'private_key' => 'not a key at all' ) ) );
        try
        {
            $this->collect( expOdfImportSourcesTestDrive::documents( $this->driveOptions(), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'private key', $e->getMessage() );
            $this->assertStringNotContainsString( 'not a key at all', $e->getMessage() );
        }
        $this->expectException( expImportSourceException::class );
        $this->collect( expOdfImportSourcesTestDrive::documents( $this->driveOptions( array( 'folder_id' => "x' or '1'='1" ) ), $this->dir ) );
    }

    public function testDriveDownloadFailureIsAFailedRow()
    {
        $this->serviceAccount();
        $this->route( 'GET', '#/files/pdfB\?alt=media#', array( 403, array(), 'forbidden' ) );
        $this->driveServer();
        $paths = $this->byPath( $this->collect( expOdfImportSourcesTestDrive::documents( $this->driveOptions( array( 'depth' => 1 ) ), $this->dir ) ) );
        $this->assertStringContainsString( 'HTTP 403', $paths['Budget.pdf']['error'] );
        $this->assertArrayHasKey( 'file', $paths['Minutes.docx'] );
    }

    // ---- OneDrive --------------------------------------------------------------------------------------------------

    private function oneDriveOptions( array $more = array() )
    {
        return $more + array( 'tenant' => 'example.onmicrosoft.com', 'client_id' => '11111111-2222-3333-4444-555555555555',
                              'client_secret' => 'env:EXPODF_TEST_GRAPH', 'drive' => 'sites/example.sharepoint.com,aaa,bbb/drive',
                              'folder' => 'Shared Documents/Press', 'depth' => 2, 'folders' => true );
    }

    private function graphServer( $downloadHost = 'https://93.184.215.14' )
    {
        $this->route( 'POST', '#^https://login\.microsoftonline\.com/example\.onmicrosoft\.com/oauth2/v2\.0/token$#',
                      array( 200, array(), json_encode( array( 'access_token' => 'graph-access-token', 'token_type' => 'Bearer' ) ) ) );
        $drive = 'https://graph.microsoft.com/v1.0/sites/example.sharepoint.com,aaa,bbb/drive';
        $item = function ( $id, $name, $folder = false ) use ( $downloadHost ) {
            $i = array( 'id' => $id, 'name' => $name, 'eTag' => '"{' . $id . '},1"', 'lastModifiedDateTime' => '2026-10-03T09:00:00Z',
                        'webUrl' => 'https://example.sharepoint.com/sites/x/' . rawurlencode( $name ) );
            if ( $folder )
                $i['folder'] = array( 'childCount' => 1 );
            else
            {
                $i['file'] = array( 'mimeType' => 'application/octet-stream' );
                $i['@microsoft.graph.downloadUrl'] = $downloadHost . '/download/' . $id . '?tempauth=pre-signed-' . $id;
            }
            return $i;
        };
        $this->route( 'GET', '#^' . preg_quote( $drive . '/root:/Shared%20Documents/Press:/children?$top=200', '#' ) . '$#', array( 200, array(), json_encode( array(
            'value' => array( $item( 'ITEM1', 'Release.docx' ), $item( 'ITEM2', 'photo.jpg' ) ),
            '@odata.nextLink' => $drive . '/root:/Shared%20Documents/Press:/children?$top=200&$skiptoken=page2',
        ) ) ) );
        $this->route( 'GET', '#skiptoken=page2$#', array( 200, array(), json_encode( array(
            'value' => array( $item( 'ITEM3', 'Facts.pdf' ), $item( 'FOLDER1', 'Archive', true ) ),
        ) ) ) );
        $this->route( 'GET', '#/items/FOLDER1/children#', array( 200, array(), json_encode( array(
            'value' => array( $item( 'ITEM4', 'Old.odt' ), $item( 'FOLDER2', 'Older', true ) ),
        ) ) ) );
        $this->route( 'GET', '#/items/FOLDER2/children#', array( 200, array(), json_encode( array( 'value' => array( $item( 'ITEM5', 'Oldest.rtf' ) ) ) ) ) );
        $this->route( 'GET', '#/download/#', function ( $url ) {
            preg_match( '#/download/([A-Z0-9]+)#', $url, $m );
            return array( 200, array(), 'file ' . $m[1] );
        } );
    }

    public function testOneDriveSignsInPagesRecursesAndDownloadsWithoutTheToken()
    {
        $this->graphServer();
        $docs = $this->collect( expOdfImportSourcesTestOneDrive::documents( $this->oneDriveOptions(), $this->dir ) );
        $paths = $this->byPath( $docs );
        $this->assertSame( array( 'Release.docx', 'Facts.pdf', 'Archive/Old.odt' ), array_keys( $paths ) );

        list( $method, $url, $headers, $body ) = $this->calls[0];
        $this->assertSame( 'POST', $method );
        parse_str( $body, $form );
        $this->assertSame( 'client_credentials', $form['grant_type'] );
        $this->assertSame( 'https://graph.microsoft.com/.default', $form['scope'] );
        $this->assertSame( '11111111-2222-3333-4444-555555555555', $form['client_id'] );
        $this->assertSame( self::GRAPH_SECRET, $form['client_secret'] );

        foreach ( $this->calls as $call )
        {
            if ( strpos( $call[1], 'https://graph.microsoft.com/' ) === 0 )
                $this->assertSame( 'Bearer graph-access-token', $call[2]['Authorization'] );
            if ( strpos( $call[1], '/download/' ) !== false )
                $this->assertArrayNotHasKey( 'Authorization', $call[2], 'a pre-authenticated address never gets the token' );
        }
        $doc = $paths['Release.docx'];
        $this->assertSame( 'file ITEM1', file_get_contents( $doc['file'] ) );
        $this->assertSame( 'ITEM1', $doc['item_id'] );
        $this->assertSame( 'ITEM1', $doc['id'] );
        $this->assertSame( '"{ITEM1},1"', $doc['etag'] );
        $this->assertSame( '2026-10-03T09:00:00Z', $doc['modified'] );
        $this->assertSame( 'https://example.sharepoint.com/sites/x/Release.docx', $doc['source_url'] );
    }

    public function testOneDrivePrivateDownloadAddressIsAFailedRow()
    {
        expImport::$settings['SourceSettings']['AllowPrivateHosts'] = 'disabled';
        $this->graphServer( 'https://10.9.8.7' );
        $paths = $this->byPath( $this->collect( expOdfImportSourcesTestOneDrive::documents( $this->oneDriveOptions( array( 'depth' => 1 ) ), $this->dir ) ) );
        $this->assertSame( array( 'Release.docx', 'Facts.pdf' ), array_keys( $paths ) );
        foreach ( $paths as $doc )
        {
            $this->assertStringContainsString( 'private or reserved', $doc['error'] );
            $this->assertStringNotContainsString( 'tempauth', $doc['error'] );
        }
        foreach ( $this->calls as $call )
            $this->assertStringNotContainsString( '10.9.8.7', $call[1] );
    }

    public function testOneDriveNextLinkMustStayOnGraph()
    {
        $this->route( 'POST', '#token$#', array( 200, array(), json_encode( array( 'access_token' => 'graph-access-token' ) ) ) );
        $this->route( 'GET', '#/root/children#', array( 200, array(), json_encode( array( 'value' => array(), '@odata.nextLink' => 'https://evil.example.test/steal' ) ) ) );
        $this->expectExceptionMessage( 'not an address of Microsoft Graph' );
        $this->collect( expOdfImportSourcesTestOneDrive::documents( $this->oneDriveOptions( array( 'folder' => '' ) ), $this->dir ) );
    }

    public function testOneDriveDriveAndFolderAreCheckedStrictly()
    {
        foreach ( array( 'users/editor@example.test/drive', 'sites/example.sharepoint.com,1a2b,3c4d/drive', 'drives/b!AbC-_123' ) as $ok )
            $this->assertSame( $ok, expOdfImportOneDriveSource::drive( $ok ) );
        foreach ( array( 'me/drive', 'users/../drive', 'users/a/drive/../../x', 'https://evil.example.test/drive', '//evil.example.test/drive',
                         'drives/x/items/y', 'users/a?x=1/drive', 'sites/a/b/drive', 'users/a%2F../drive', '' ) as $bad )
        {
            try
            {
                expOdfImportOneDriveSource::drive( $bad );
                $this->fail( "$bad was accepted" );
            }
            catch ( expImportSourceException $e )
            {
                $this->assertStringContainsString( 'the drive must be', $e->getMessage() );
            }
        }
        $this->assertSame( array( 'Shared Documents', 'Press' ), expOdfImportOneDriveSource::folderSegments( '/Shared Documents//Press/' ) );
        foreach ( array( 'a/../b', 'a/./b', 'a:b', 'x?y', 'x#y', "a\0b" ) as $bad )
        {
            try
            {
                expOdfImportOneDriveSource::folderSegments( $bad );
                $this->fail( "$bad was accepted" );
            }
            catch ( expImportSourceException $e )
            {
                $this->assertStringContainsString( 'not allowed', $e->getMessage() );
            }
        }
        $this->assertCount( 0, $this->calls );
    }

    public function testOneDriveSecretNeverInMessages()
    {
        $this->route( 'POST', '#token$#', array( 401, array(), json_encode( array( 'error' => 'invalid_client',
            'error_description' => 'AADSTS7000215: Invalid client secret provided: ' . self::GRAPH_SECRET ) ) ) );
        try
        {
            $this->collect( expOdfImportSourcesTestOneDrive::documents( $this->oneDriveOptions(), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'could not sign in', $e->getMessage() );
            $this->assertStringContainsString( 'AADSTS7000215', $e->getMessage() );
            $this->assertStringNotContainsString( self::GRAPH_SECRET, $e->getMessage() );
        }
    }

    // ---- documents attached to e-mails -----------------------------------------------------------------------------

    private function message( $id )
    {
        $docx = base64_encode( 'PK fake docx ' . $id );
        $pdf = base64_encode( '%PDF-1.4 forwarded ' . $id );
        return "Message-ID: <$id@mail.example.invalid>\r\n"
             . "From: submissions@example.invalid\r\n"
             . "To: press@example.invalid\r\n"
             . "Subject: =?UTF-8?B?" . base64_encode( 'Press release ' . $id ) . "?=\r\n"
             . "Date: Tue, 20 Oct 2026 10:00:00 +0200\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/mixed; boundary=\"outer\"\r\n\r\n"
             . "preamble\r\n--outer\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nPlease find the release attached.\r\n"
             . "--outer\r\nContent-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document; name=\"release.docx\"\r\n"
             . "Content-Disposition: attachment; filename=\"release.docx\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split( $docx, 76, "\r\n" )
             . "--outer\r\nContent-Type: image/png; name=\"logo.png\"\r\nContent-Disposition: attachment; filename=\"logo.png\"\r\nContent-Transfer-Encoding: base64\r\n\r\niVBORw0KGgo=\r\n"
             . "--outer\r\nContent-Type: message/rfc822\r\n\r\n"
             . "Subject: forwarded\r\nContent-Type: multipart/mixed; boundary=\"inner\"\r\n\r\n"
             . "--inner\r\nContent-Type: text/plain\r\n\r\nsee attachment\r\n"
             . "--inner\r\nContent-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''..%2F..%2Fpaper%20one.pdf\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $pdf . "\r\n"
             . "--inner--\r\n"
             . "--outer--\r\n";
    }

    private function mailOptions( array $more = array() )
    {
        return $more + array( 'host' => 'imap.example.test', 'port' => 993, 'encryption' => 'ssl', 'user' => 'press',
                              'password' => 'env:EXPODF_TEST_IMAP', 'mailbox' => 'INBOX', 'search' => 'UNSEEN', 'days' => 0, 'mark_seen' => false );
    }

    public function testMailboxYieldsTheDocumentAttachments()
    {
        $imap = new expOdfImportSourcesTestImap( array( 7 => $this->message( 'm7' ), 9 => $this->message( 'm9' ) ) );
        $docs = $this->collect( expOdfImportSourcesTestMailbox::documentsWith( $imap, $this->mailOptions(), $this->dir ) );
        $this->assertCount( 4, $docs, 'two messages, a .docx and a forwarded .pdf each; the PNG left out' );
        $this->assertSame( array( 'imap.example.test', 993, 'ssl', 'press', self::IMAP_PASSWORD, 'INBOX', true ), $imap->opened, 'read-only' );
        $this->assertTrue( $imap->closed );
        $this->assertSame( array( 'UNSEEN' ), $imap->searches );

        $first = $docs[0];
        $this->assertSame( 'PK fake docx m7', file_get_contents( $first['file'] ) );
        $this->assertStringStartsWith( $this->dir . '/', $first['file'] );
        $this->assertSame( 'm7@mail.example.invalid', $first['message_id'] );
        $this->assertSame( 'Press release m7', $first['subject'] );
        $this->assertSame( 'submissions@example.invalid', $first['from'] );
        $this->assertSame( '2026-10-20T10:00:00+02:00', $first['date'] );
        $this->assertSame( 'release.docx', $first['attachment_name'] );
        $this->assertSame( 'm7@mail.example.invalid/release.docx', $first['id'] );
        $this->assertSame( substr( sha1( 'm7@mail.example.invalid' ), 0, 12 ) . '/release.docx', $first['path'] );

        $pdf = $docs[1];
        $this->assertSame( '%PDF-1.4 forwarded m7', file_get_contents( $pdf['file'] ) );
        $this->assertSame( 'paper one.pdf', $pdf['attachment_name'], 'a name with a path is reduced to its last part' );
        $this->assertStringStartsWith( realpath( $this->dir ) . '/', realpath( $pdf['file'] ) );
        $this->assertNotSame( $docs[0]['path'], $docs[2]['path'], 'the same name in two messages' );
    }

    public function testMailboxMarksSeenOnlyWhenAsked()
    {
        $imap = new expOdfImportSourcesTestImap( array( 3 => $this->message( 'm3' ) ) );
        expImport::$now = mktime( 12, 0, 0, 10, 20, 2026 );
        $this->collect( expOdfImportSourcesTestMailbox::documentsWith( $imap, $this->mailOptions( array( 'mark_seen' => true, 'days' => 7 ) ), $this->dir ) );
        $this->assertFalse( $imap->opened[6] );
        $this->assertSame( array( 3 ), $imap->seen );
        $this->assertSame( array( 'UNSEEN SINCE 13-Oct-2026' ), $imap->searches );
    }

    public function testMailboxTooLargeMessageIsAFailedRow()
    {
        expImport::$settings['ImapSourceSettings'] = array( 'MaxMessageMegabytes' => 1 );
        $imap = new expOdfImportSourcesTestImap( array( 5 => $this->message( 'm5' ) . str_repeat( 'x', 1048577 ), 6 => $this->message( 'm6' ) ) );
        $docs = $this->collect( expOdfImportSourcesTestMailbox::documentsWith( $imap, $this->mailOptions(), $this->dir ) );
        $this->assertSame( 'message-5', $docs[0]['path'] );
        $this->assertStringContainsString( 'MaxMessageMegabytes', $docs[0]['error'] );
        $this->assertCount( 3, $docs );
    }

    public function testMailboxPrivateHostRefusedUnlessListed()
    {
        expImport::$settings['SourceSettings']['AllowPrivateHosts'] = 'disabled';
        $imap = new expOdfImportSourcesTestImap( array( 1 => $this->message( 'm1' ) ) );
        expOdfImportMailboxDocumentsSource::$transport = $imap;
        try
        {
            expOdfImportSourcesTestMailbox::documents( $this->mailOptions( array( 'host' => '10.0.0.5' ) ), $this->dir );
            $this->fail( 'a private host was read' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'AllowedHosts', $e->getMessage() );
        }
        $this->assertNull( $imap->opened );

        expImport::$settings['ImapSourceSettings'] = array( 'AllowedHosts' => array( '10.0.0.5' ) );
        $docs = $this->collect( expOdfImportSourcesTestMailbox::documents( $this->mailOptions( array( 'host' => '10.0.0.5' ) ), $this->dir ) );
        $this->assertCount( 2, $docs );
    }

    public function testMailboxPasswordNeverInMessages()
    {
        $imap = new expOdfImportSourcesTestImap( array() );
        $imap->failOpen = 'IMAP: LOGIN failed for press with ' . self::IMAP_PASSWORD;
        try
        {
            $this->collect( expOdfImportSourcesTestMailbox::documentsWith( $imap, $this->mailOptions(), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( 'LOGIN failed', $e->getMessage() );
            $this->assertStringNotContainsString( self::IMAP_PASSWORD, $e->getMessage() );
        }
        // a missing secret names the secret, never a value
        try
        {
            $this->collect( expOdfImportSourcesTestMailbox::documentsWith( $imap, $this->mailOptions( array( 'password' => 'env:EXPODF_TEST_MISSING' ) ), $this->dir ) );
            $this->fail( 'no exception' );
        }
        catch ( expImportSourceException $e )
        {
            $this->assertStringContainsString( "the secret 'env:EXPODF_TEST_MISSING' is not set", $e->getMessage() );
        }
    }
}
