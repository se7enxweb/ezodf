<?php
/**
 * The access check of eZOOImport::import() comes before any image object: images embedded in a document used to
 * be created while content.xml was parsed, and the create/edit check ran only afterwards, so a user without
 * create rights at the target still left image objects behind. External images are not fetched at all.
 * Kernel-free; runs in a process of its own with the stand-ins of fixtures/kernel_stubs.php.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZOOImportAccessTestImport extends eZOOImport
{
    public $created = array();

    function createImageObject( $href, $imageName )
    {
        $this->created[] = array( basename( $href ), $imageName );
        return 40 + count( $this->created );
    }
}

class eZOOImportAccessTest extends TestCase
{
    // a 1x1 PNG
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private $workDir;

    private function loadStubs()
    {
        foreach ( array( 'eZINI', 'eZSys', 'eZHTTPTool', 'eZTextCodec', 'eZContentObjectTreeNode', 'eZContentClass',
                         'eZContentObject', 'eZContentFunctionCollection', 'eZDB' ) as $class )
        {
            if ( class_exists( $class, false ) )
            {
                $this->markTestSkipped( "The kernel class $class is loaded already; run with extension/ezodf/tests/bootstrap.php" );
            }
        }
        if ( !class_exists( 'ZipArchive' ) )
        {
            $this->markTestSkipped( 'The zip extension is needed to build the test document.' );
        }
        require_once __DIR__ . '/fixtures/kernel_stubs.php';
    }

    private function document( $imageHref, $picture = null )
    {
        $this->workDir = sys_get_temp_dir() . '/ezodf-test-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->workDir, 0700, true );
        $content = "<?xml version='1.0' encoding='UTF-8'?>" .
            "<office:document-content xmlns:office='urn:oasis:names:tc:opendocument:xmlns:office:1.0'" .
            " xmlns:text='urn:oasis:names:tc:opendocument:xmlns:text:1.0'" .
            " xmlns:draw='urn:oasis:names:tc:opendocument:xmlns:drawing:1.0'" .
            " xmlns:svg='urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0'" .
            " xmlns:xlink='http://www.w3.org/1999/xlink'>" .
            "<office:automatic-styles/><office:body><office:text>" .
            "<text:p>Text<draw:frame draw:name='Picture' svg:width='2'><draw:image xlink:href='$imageHref'/></draw:frame></text:p>" .
            "</office:text></office:body></office:document-content>";
        $file = $this->workDir . '/document.odt';
        $zip = new ZipArchive();
        $zip->open( $file, ZipArchive::CREATE );
        $zip->addFromString( 'content.xml', $content );
        $zip->addFromString( 'Pictures/a.png', $picture === null ? base64_decode( self::PNG ) : $picture );
        $zip->close();
        return $file;
    }

    protected function tearDown(): void
    {
        if ( $this->workDir !== null )
        {
            eZOOImport::removeWorkDirectory( $this->workDir );
        }
    }

    #[RunInSeparateProcess]
    public function testAUserWithoutCreateRightsLeavesNoImageObject()
    {
        $this->loadStubs();
        $file = $this->document( 'Pictures/a.png' );
        eZOOImportTestLog::$access = false;

        $import = new eZOOImport();
        $import->ImportBaseDir = $this->workDir . '/import/';
        $result = $import->import( $file, 2, 'document.odt' );

        $this->assertFalse( $result );
        $this->assertSame( eZOOImport::ERROR_ACCESSDENIED, $import->getErrorNumber() );
        $this->assertContains( 'checkAccess create', eZOOImportTestLog::$calls );
        $this->assertNotContains( 'instantiate image', eZOOImportTestLog::$calls );
        $this->assertNotContains( 'eZContentObject::fetchByRemoteID', eZOOImportTestLog::$calls, 'no image is looked up before the access check' );
        $this->assertSame( array(), $import->PendingImages, 'a refused import forgets its images' );
        $this->assertDirectoryDoesNotExist( $import->ImportDir );
    }

    #[RunInSeparateProcess]
    public function testTheImageIsFoundWhileParsingAndCreatedOnlyWithAccess()
    {
        $this->loadStubs();
        $file = $this->document( 'Pictures/a.png' );
        $import = new eZOOImport();
        $import->ImportDir = $this->workDir . '/import/x/';
        $this->assertTrue( $import->extractDocument( $file, $import->ImportDir ) );

        $dom = new DOMDocument();
        $zip = new ZipArchive();
        $zip->open( $file );
        $dom->loadXML( $zip->getFromName( 'content.xml' ) );
        $zip->close();
        $frame = $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_DRAWING, 'frame' )->item( 0 );

        $xml = $import->handleInlineNode( $frame );
        $this->assertStringContainsString( "object_id='ezodf-image-0'", $xml );
        $this->assertSame( array( 'ezodf-image-0' ), array_keys( $import->PendingImages ) );
        $this->assertSame( array(), eZOOImportTestLog::$calls, 'parsing creates nothing' );
    }

    #[RunInSeparateProcess]
    public function testAnExternalImageIsNotFetched()
    {
        $this->loadStubs();
        // Port 1 on the loopback refuses at once: a fetch would end in a warning and an error message
        $file = $this->document( 'http://127.0.0.1:1/a.png' );
        $import = new eZOOImport();
        $import->ImportDir = $this->workDir . '/import/y/';
        $this->assertTrue( $import->extractDocument( $file, $import->ImportDir ) );

        $dom = new DOMDocument();
        $zip = new ZipArchive();
        $zip->open( $file );
        $dom->loadXML( $zip->getFromName( 'content.xml' ) );
        $zip->close();
        $frame = $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_DRAWING, 'frame' )->item( 0 );

        eZDebug::$messages = array();
        $xml = $import->handleInlineNode( $frame );

        $this->assertSame( '', $xml );
        $this->assertSame( array(), $import->PendingImages );
        $this->assertSame( array( 'a.png' ), array_values( array_diff( scandir( $import->ImportDir . 'Pictures' ), array( '.', '..' ) ) ) );
        $this->assertFileDoesNotExist( $import->ImportDir . 'a.png', 'nothing was downloaded into the import directory' );
        $this->assertSame( 'warning', eZDebug::$messages[0][0] );
        $this->assertStringContainsString( 'External image not imported', eZDebug::$messages[0][1] );
    }

    public function testCreatePendingImagesPutsTheObjectIdsInPlaceOfThePlaceholders()
    {
        $import = new eZOOImportAccessTestImport();
        $import->PendingImages = array( 'ezodf-image-0' => array( 'href' => '/x/Pictures/a.png', 'name' => 'A' ),
                                        'ezodf-image-1' => array( 'href' => '/x/Pictures/b.png', 'name' => 'B' ) );
        $texts = $import->createPendingImages( array(
            'body' => "<embed object_id='ezodf-image-1' size='small' /><embed object_id='ezodf-image-0' />",
            'intro' => "no image" ) );

        $this->assertSame( "<embed object_id='42' size='small' /><embed object_id='41' />", $texts['body'] );
        $this->assertSame( 'no image', $texts['intro'] );
        $this->assertSame( array( array( 'a.png', 'A' ), array( 'b.png', 'B' ) ), $import->created );
        $this->assertSame( array(), $import->PendingImages );
    }

    #[RunInSeparateProcess]
    public function testAPictureThatIsNoImageIsSkipped()
    {
        $this->loadStubs();
        $file = $this->document( 'Pictures/a.png', 'not an image' );
        $import = new eZOOImport();
        $import->ImportDir = $this->workDir . '/import/z/';
        $this->assertTrue( $import->extractDocument( $file, $import->ImportDir ) );

        $dom = new DOMDocument();
        $zip = new ZipArchive();
        $zip->open( $file );
        $dom->loadXML( $zip->getFromName( 'content.xml' ) );
        $zip->close();
        $frame = $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_DRAWING, 'frame' )->item( 0 );

        $this->assertSame( '', $import->handleInlineNode( $frame ) );
        $this->assertSame( array(), $import->PendingImages );
    }

    #[RunInSeparateProcess]
    public function testAFailedImportRollsBackItsTransaction()
    {
        $this->loadStubs();
        $file = $this->document( 'Pictures/a.png' );
        eZOOImportTestLog::$access = true;

        $import = new eZOOImport();
        $import->ImportBaseDir = $this->workDir . '/import/';
        $import->RelatedImageArray = array( array( 'ID' => 99, 'ContentObject' => null ) );
        try
        {
            $import->import( $file, 2, 'document.odt', 'replace' );
            $this->fail( 'the stand-in refuses to create the image object' );
        }
        catch ( RuntimeException $e )
        {
            $this->assertSame( 'An object of class image was created', $e->getMessage() );
        }
        $this->assertSame( array( 'eZDB::begin', 'eZDB::rollback' ),
                           array_values( array_filter( eZOOImportTestLog::$calls, function ( $call ) { return strpos( $call, 'eZDB::' ) === 0; } ) ) );
        $this->assertSame( array(), $import->RelatedImageArray, 'every import starts without the images of the previous one' );
        $this->assertDirectoryDoesNotExist( $import->ImportDir );
    }

    #[RunInSeparateProcess]
    public function testAnImportStoppedByItsSettingsRollsBack()
    {
        $this->loadStubs();
        $file = $this->document( 'http://127.0.0.1:1/a.png' );
        eZOOImportTestLog::$access = true;

        $import = new eZOOImport();
        $import->ImportBaseDir = $this->workDir . '/import/';
        $this->assertFalse( $import->import( $file, 2, 'document.odt', 'replace' ) );
        $this->assertSame( eZOOImport::ERROR_IMPORTING, $import->getErrorNumber() );
        $this->assertContains( 'eZDB::begin', eZOOImportTestLog::$calls );
        $this->assertContains( 'eZDB::rollback', eZOOImportTestLog::$calls );
        $this->assertNotContains( 'eZDB::commit', eZOOImportTestLog::$calls );
    }
}
