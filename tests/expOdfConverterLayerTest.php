<?php
/**
 * DB-less tests of ezodf 7's converter layer: the SSRF guard of document URLs, safe names and paths, the settings
 * validation, the XML-RPC of the remote server, the sandbox command, the format families, the OpenOffice.org 1.x
 * rewrite and the filter catalogue.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/classes/converter/expodfpaths.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfsettings.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfconverterexception.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfremotefetcher.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfremoteserver.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfsandbox.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfconverter.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfphpconverter.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodffiltercatalogue.php';
require_once dirname( __DIR__ ) . '/classes/converter/expodfbatch.php';

class expOdfConverterLayerTest extends TestCase
{
    /** @var string */
    protected $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/ezodf-layer-' . bin2hex( random_bytes( 4 ) );
        mkdir( $this->base, 0775, true );
        expOdfPaths::$baseOverride = $this->base;
        expOdfSettings::$iniOverride = array();
        expOdfSettings::reset();
    }

    protected function tearDown(): void
    {
        expOdfPaths::remove( $this->base . '/x' ); // nothing outside the base is ever removed
        $items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $items as $item )
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        rmdir( $this->base );
        expOdfPaths::$baseOverride = null;
        expOdfSettings::$iniOverride = null;
        expOdfSettings::reset();
    }

    public static function addresses()
    {
        return array(
            array( '8.8.8.8', true ), array( '1.1.1.1', true ), array( '2606:4700:4700::1111', true ),
            array( '127.0.0.1', false ), array( '10.1.2.3', false ), array( '172.16.0.1', false ), array( '192.168.1.1', false ),
            array( '169.254.169.254', false ), array( '100.64.0.1', false ), array( '0.0.0.0', false ), array( '224.0.0.1', false ),
            array( '198.51.100.7', false ), array( '::1', false ), array( '::', false ), array( 'fc00::1', false ), array( 'fe80::1', false ),
            array( '::ffff:127.0.0.1', false ), array( '::ffff:10.0.0.1', false ), array( '2001:db8::1', false ), array( '2002:0a00:0001::1', false ),
            array( 'ff02::1', false ), array( 'not-an-ip', false ),
        );
    }

    /** @dataProvider addresses */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'addresses' )]
    public function testPublicAddresses( $ip, $public )
    {
        $this->assertSame( $public, expOdfRemoteFetcher::isPublic( $ip ), $ip );
    }

    public static function refusedUrls()
    {
        return array(
            array( 'file:///etc/passwd' ), array( 'ftp://example.org/a.doc' ), array( 'gopher://127.0.0.1/' ), array( 'http://127.0.0.1/x' ),
            array( 'http://[::1]/x' ), array( 'http://10.0.0.5/a.docx' ), array( 'http://169.254.169.254/latest/meta-data/' ),
            array( 'http://user:pw@8.8.8.8/a.pdf' ), array( 'http://8.8.8.8:22/a.pdf' ), array( 'http://localhost/a.pdf' ), array( 'javascript:alert(1)' ),
        );
    }

    /** @dataProvider refusedUrls */
    #[\PHPUnit\Framework\Attributes\DataProvider( 'refusedUrls' )]
    public function testRefusedUrls( $url )
    {
        $this->expectException( expOdfConverterException::class );
        expOdfRemoteFetcher::check( $url );
    }

    public function testAllowedUrlKeepsTheCheckedAddress()
    {
        list( $host, $port, $ip ) = expOdfRemoteFetcher::check( 'https://8.8.8.8/doc.pdf' );
        $this->assertSame( array( '8.8.8.8', 443, '8.8.8.8' ), array( $host, $port, $ip ) );
    }

    public function testSafeNamesAndPaths()
    {
        $this->assertSame( 'passwd', expOdfRemoteFetcher::safeName( '../../etc/passwd' ) );
        $this->assertSame( 'Bericht_2026.docx', expOdfRemoteFetcher::safeName( 'Bericht;2026.docx' ) );
        $this->assertSame( 'document', expOdfRemoteFetcher::safeName( '...' ) );
        $this->assertSame( 'report.pdf', expOdfRemoteFetcher::fileName( 'https://x.org/a/b', 'attachment; filename="report.pdf"' ) );
        // '..' and '.' parts are dropped, never resolved: a path cannot climb out of its batch
        $this->assertSame( 'a/b/c.docx', expOdfBatch::cleanPath( '/a/../b/./c.docx' ) );
        $this->assertSame( 'etc/passwd', expOdfBatch::cleanPath( '../../etc/passwd' ) );
        $this->assertSame( 'Folder/Sub/file.odt', expOdfBatch::cleanPath( 'Folder\\Sub\\file.odt' ) );
        $this->assertSame( '', expOdfBatch::cleanPath( '../..' ) );
    }

    public function testSettingsValidation()
    {
        $schema = expOdfSettings::schema();
        $this->assertNull( expOdfSettings::validate( $schema['converter.timeout'], '120' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.timeout'], '5' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.timeout'], '12abc' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.mode'], 'shell' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.binary'], '/usr/bin/soffice; rm -rf /' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.binary'], 'soffice' ) );
        $this->assertNull( expOdfSettings::validate( $schema['converter.binary'], '/opt/libreoffice26.8/program/soffice' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.remote_url'], 'file:///tmp/x' ) );
        $this->assertNull( expOdfSettings::validate( $schema['converter.version_pin'], '26.8' ) );
        $this->assertNotNull( expOdfSettings::validate( $schema['converter.version_pin'], 'latest' ) );
    }

    public function testSettingsStoreWinsAndSecretsAreKept()
    {
        $this->assertSame( array(), expOdfSettings::save( array( 'converter.timeout' => '150', 'converter.remote_password' => 'abc' ) ) );
        $this->assertSame( 150, expOdfSettings::get( 'converter.timeout' ) );
        $this->assertSame( 'store', expOdfSettings::source( 'converter.timeout' ) );
        // an empty or masked secret keeps the stored one, '-' removes it
        expOdfSettings::save( array( 'converter.remote_password' => '' ) );
        $this->assertSame( 'abc', expOdfSettings::get( 'converter.remote_password' ) );
        expOdfSettings::save( array( 'converter.remote_password' => '********' ) );
        $this->assertSame( 'abc', expOdfSettings::get( 'converter.remote_password' ) );
        $all = expOdfSettings::all();
        $this->assertSame( '********', $all['converter.remote_password'] );
        expOdfSettings::save( array( 'converter.remote_password' => '-' ) );
        $this->assertSame( '', expOdfSettings::get( 'converter.remote_password' ) );
        $errors = expOdfSettings::save( array( 'converter.timeout' => '1', 'unknown.key' => 'x' ) );
        $this->assertArrayHasKey( 'converter.timeout', $errors );
        $this->assertArrayHasKey( 'unknown.key', $errors );
        $this->assertSame( 150, expOdfSettings::get( 'converter.timeout' ), 'nothing is stored when one value is wrong' );
    }

    public function testXmlRpcRoundTrip()
    {
        $call = expOdfRemoteServer::xmlrpcCall( 'convert', array( null, array( 'base64' => "PK\x03\x04<&>" ), 'pdf', array(), false, 3 ) );
        $dom = new DOMDocument();
        $this->assertTrue( $dom->loadXML( $call ) );
        $this->assertStringContainsString( '<nil/>', $call );
        $this->assertStringContainsString( base64_encode( "PK\x03\x04<&>" ), $call );
        $answer = '<?xml version="1.0"?><methodResponse><params><param><value><base64>' . base64_encode( '%PDF-1.7' ) . '</base64></value></param></params></methodResponse>';
        $this->assertSame( '%PDF-1.7', expOdfRemoteServer::xmlrpcResult( $answer ) );
        $info = '<?xml version="1.0"?><methodResponse><params><param><value><struct><member><name>unoserver</name><value><string>3.1</string></value></member></struct></value></param></params></methodResponse>';
        $this->assertSame( array( 'unoserver' => '3.1' ), expOdfRemoteServer::xmlrpcResult( $info ) );
        $this->expectException( expOdfConverterException::class );
        expOdfRemoteServer::xmlrpcResult( '<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultString</name><value><string>boom</string></value></member></struct></value></fault></methodResponse>' );
    }

    public function testSandboxCommandIsolatesTheJob()
    {
        $argv = expOdfSandbox::command( array( '/usr/bin/true' ), array( '/opt/lo' ), array( $this->base ), array( 'seconds' => 30, 'memory' => 1024 ) );
        $this->assertSame( '/usr/bin/true', end( $argv ) );
        if ( expOdfSandbox::tool( 'timeout' ) !== '' )
            $this->assertSame( array( expOdfSandbox::tool( 'timeout' ), '-s', 'KILL', '30' ), array_slice( $argv, 0, 4 ) );
        if ( expOdfSandbox::tool( 'prlimit' ) !== '' )
            $this->assertContains( '--as=' . ( 1024 * 1048576 ), $argv );
        $probe = expOdfSandbox::probe();
        if ( !empty( $probe['available'] ) )
        {
            foreach ( array( '--unshare-all', '--die-with-parent', '--new-session' ) as $flag )
                $this->assertContains( $flag, $argv );
            $i = array_search( '--bind', $argv, true );
            $this->assertSame( array( '--bind', $this->base, $this->base ), array_slice( $argv, $i, 3 ), 'only the job directory is writable' );
        }
        // no shell anywhere in the command
        foreach ( $argv as $arg )
            $this->assertNotContains( basename( $arg ), array( 'sh', 'bash' ) );
    }

    public function testFamilies()
    {
        $this->assertSame( 'spreadsheet', expOdfConverter::family( 'xlsx' ) );
        $this->assertSame( 'spreadsheet', expOdfConverter::family( 'sdc' ) );
        $this->assertSame( 'presentation', expOdfConverter::family( 'sxi' ) );
        $this->assertSame( 'drawing', expOdfConverter::family( 'sda' ) );
        $this->assertSame( 'text', expOdfConverter::family( 'sdw' ) );
        $this->assertSame( 'pdf', expOdfConverter::family( 'pdf' ) );
        $this->assertSame( 'docx', expOdfConverter::extensionOf( 'Report.DOCX' ) );
        $this->assertSame( '', expOdfConverter::extensionOf( 'noext' ) );
        $this->assertSame( '', expOdfConverter::extensionOf( 'x.verylongextension' ) );
    }

    public function testOpenOffice1Rewrite()
    {
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( dirname( __DIR__ ) . '/share/samples/sample.sxw' ) );
        $xml = expOdfPhpConverter::rewriteOpenOffice1( $zip->getFromName( 'content.xml' ) );
        $zip->close();
        $dom = new DOMDocument();
        $this->assertTrue( $dom->loadXML( $xml ) );
        $office = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
        $text = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
        $this->assertSame( 1, $dom->getElementsByTagNameNS( $office, 'text' )->length, 'the body is wrapped in office:text' );
        $h = $dom->getElementsByTagNameNS( $text, 'h' )->item( 0 );
        $this->assertSame( '1', $h->getAttributeNS( $text, 'outline-level' ) );
        $this->assertSame( 1, $dom->getElementsByTagNameNS( $text, 'list' )->length );
        $this->assertSame( 0, $dom->getElementsByTagNameNS( 'http://openoffice.org/2000/text', 'p' )->length, 'no 1.x namespace is left' );
        $props = $dom->getElementsByTagNameNS( 'urn:oasis:names:tc:opendocument:xmlns:style:1.0', 'text-properties' )->item( 0 );
        $this->assertSame( 'bold', $props->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0', 'font-weight' ) );
    }

    public function testFilterCatalogueParsesTypesAndFilters()
    {
        $xcd = $this->base . '/writer.xcd';
        file_put_contents( $xcd, '<?xml version="1.0"?><oor:data xmlns:oor="http://openoffice.org/2001/registry"><dependency file="main"/>'
            . '<oor:component-data oor:package="org.openoffice.TypeDetection" oor:name="Types"><node oor:name="Types">'
            . '<node oor:name="writer_StarOffice_XML_Writer" oor:op="replace"><prop oor:name="Extensions"><value>sxw</value></prop></node>'
            . '<node oor:name="writer8" oor:op="replace"><prop oor:name="Extensions"><value>odt</value></prop></node></node></oor:component-data>'
            . '<oor:component-data oor:package="org.openoffice.TypeDetection" oor:name="Filter"><node oor:name="Filters">'
            . '<node oor:name="StarOffice XML (Writer)" oor:op="replace"><prop oor:name="Flags"><value>IMPORT TEMPLATE</value></prop><prop oor:name="Type"><value>writer_StarOffice_XML_Writer</value></prop><prop oor:name="DocumentService"><value>com.sun.star.text.TextDocument</value></prop></node>'
            . '<node oor:name="writer8" oor:op="replace"><prop oor:name="Flags"><value>IMPORT EXPORT OWN</value></prop><prop oor:name="Type"><value>writer8</value></prop><prop oor:name="DocumentService"><value>com.sun.star.text.TextDocument</value></prop></node>'
            . '<node oor:name="internal" oor:op="replace"><prop oor:name="Flags"><value>INTERNAL</value></prop></node>'
            . '</node></oor:component-data></oor:data>' );
        $filters = expOdfFilterCatalogue::parse( array( $xcd ) );
        $this->assertCount( 2, $filters, 'a filter that neither imports nor exports is left out' );
        $byName = array();
        foreach ( $filters as $f )
            $byName[$f['name']] = $f;
        $this->assertTrue( $byName['StarOffice XML (Writer)']['legacy'] );
        $this->assertFalse( $byName['StarOffice XML (Writer)']['export'] );
        $this->assertSame( array( 'odt' ), $byName['writer8']['extensions'] );
        $this->assertSame( array( 'odt' => array( 'text' ), 'sxw' => array( 'text' ) ), expOdfFilterCatalogue::importExtensions( $filters ) );
    }

    public function testHeadingListsBecomeHeadings()
    {
        $t = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
        $dom = new DOMDocument();
        $dom->loadXML( '<o:body xmlns:o="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="' . $t . '"><o:text>'
            . '<text:list><text:list-item><text:h text:outline-level="1">One</text:h><text:list><text:list-item><text:h text:outline-level="2">One.one</text:h></text:list-item></text:list></text:list-item></text:list>'
            . '<text:p>Body</text:p>'
            . '<text:list><text:list-item><text:p>A point</text:p></text:list-item><text:list-item><text:h>Odd</text:h></text:list-item></text:list>'
            . '</o:text></o:body>' );
        eZOOImport::unwrapHeadingLists( $dom );
        $body = $dom->getElementsByTagNameNS( 'urn:oasis:names:tc:opendocument:xmlns:office:1.0', 'text' )->item( 0 );
        $names = array();
        foreach ( $body->childNodes as $n )
            $names[] = $n->localName . ':' . trim( $n->textContent );
        $this->assertSame( array( 'h:One', 'h:One.one', 'p:Body', 'list:A pointOdd' ), $names, 'the outline list is gone, the mixed list stays' );
        $this->assertSame( 2, $dom->getElementsByTagNameNS( $t, 'h' )->length, 'the heading inside the mixed list became a paragraph' );
    }

    public function testRemoveNeverLeavesTheBase()
    {
        $outside = sys_get_temp_dir() . '/ezodf-outside-' . bin2hex( random_bytes( 4 ) );
        mkdir( $outside );
        $this->assertFalse( expOdfPaths::remove( $outside ) );
        $this->assertFalse( expOdfPaths::remove( $this->base ), 'not the base itself' );
        $this->assertFalse( expOdfPaths::remove( $this->base . '/../' . basename( $outside ) ) );
        $this->assertDirectoryExists( $outside );
        rmdir( $outside );
        $job = expOdfPaths::jobDir( 'work' );
        file_put_contents( $job . '/a.txt', 'x' );
        $this->assertTrue( expOdfPaths::remove( $job ) );
        $this->assertDirectoryDoesNotExist( $job );
    }
}
