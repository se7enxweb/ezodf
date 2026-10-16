<?php
/**
 * The archive check of eZOOImport::extractDocument(): an uploaded OpenDocument file is a zip archive, and its
 * entries were extracted wherever they pointed (zip slip). Kernel-free.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZOOImportArchiveTest extends TestCase
{
    private $workDir;

    protected function setUp(): void
    {
        if ( !class_exists( 'ZipArchive' ) )
        {
            $this->markTestSkipped( 'The zip extension is needed to build the test archives.' );
        }
        $this->workDir = sys_get_temp_dir() . '/ezodf-test-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->workDir . '/upload', 0700, true );
    }

    protected function tearDown(): void
    {
        if ( $this->workDir !== null )
        {
            eZOOImport::removeWorkDirectory( $this->workDir );
        }
    }

    /**
     * @param array $entries name => content, or name => array( 'link' => target ) for a symbolic link
     */
    private function archive( array $entries )
    {
        $file = $this->workDir . '/upload/' . bin2hex( random_bytes( 4 ) ) . '.odt';
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $file, ZipArchive::CREATE ) === true );
        foreach ( $entries as $name => $content )
        {
            if ( is_array( $content ) )
            {
                $zip->addFromString( $name, $content['link'] );
                $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) );
            }
            else
            {
                $zip->addFromString( $name, $content );
            }
        }
        $zip->close();
        return $file;
    }

    private function content()
    {
        return "<?xml version='1.0' encoding='UTF-8'?><office:document-content xmlns:office='urn:oasis:names:tc:opendocument:xmlns:office:1.0'/>";
    }

    private function filesBelow( $dir )
    {
        $found = array();
        if ( !is_dir( $dir ) )
        {
            return $found;
        }
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
        foreach ( $iterator as $item )
        {
            $found[] = substr( $item->getPathname(), strlen( $dir ) + 1 );
        }
        sort( $found );
        return $found;
    }

    public function testAnEntryWithDotDotIsRefusedAndNothingIsWritten()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), '../x' => 'escaped', 'Pictures/../../y' => 'escaped' ) );
        $target = $this->workDir . '/import/one';

        $import = new eZOOImport();
        $this->assertFalse( $import->extractDocument( $file, $target ) );
        $this->assertSame( eZOOImport::ERROR_ARCHIVE, $import->getErrorNumber() );
        $this->assertStringContainsString( 'unsafe_path', $import->getErrorMessage() );
        $this->assertFileDoesNotExist( $this->workDir . '/import/x' );
        $this->assertFileDoesNotExist( $this->workDir . '/x' );
        $this->assertSame( array(), $this->filesBelow( $target ), 'nothing of a refused archive is extracted' );
    }

    public function testAnAbsoluteEntryIsRefused()
    {
        $outside = $this->workDir . '/absolute.txt';
        $file = $this->archive( array( 'content.xml' => $this->content(), $outside => 'escaped' ) );

        $import = new eZOOImport();
        $this->assertFalse( $import->extractDocument( $file, $this->workDir . '/import/two' ) );
        $this->assertSame( eZOOImport::ERROR_ARCHIVE, $import->getErrorNumber() );
        $this->assertFileDoesNotExist( $outside );
    }

    public function testASymbolicLinkEntryIsRefused()
    {
        $outside = $this->workDir . '/outside';
        mkdir( $outside );
        $file = $this->archive( array( 'content.xml' => $this->content(),
                                       'Pictures/link' => array( 'link' => $outside ),
                                       'Pictures/link/evil.php' => '<?php echo 1;' ) );
        $target = $this->workDir . '/import/three';

        $import = new eZOOImport();
        $this->assertFalse( $import->extractDocument( $file, $target ) );
        $this->assertStringContainsString( 'unsafe_type', $import->getErrorMessage() );
        $this->assertFalse( is_link( $target . '/Pictures/link' ) );
        $this->assertSame( array(), $this->filesBelow( $outside ) );
        $this->assertSame( array(), $this->filesBelow( $target ) );
    }

    public function testOnlyTheContentAndItsPicturesAreExtractedIntoAPrivateDirectory()
    {
        $file = $this->archive( array( 'mimetype' => 'application/vnd.oasis.opendocument.text',
                                       'content.xml' => $this->content(),
                                       'styles.xml' => '<x/>',
                                       'Pictures/a.png' => 'png',
                                       'Scripts/evil.php' => '<?php echo 1;',
                                       'META-INF/manifest.xml' => '<x/>' ) );
        $target = $this->workDir . '/import/four';

        $import = new eZOOImport();
        $this->assertTrue( $import->extractDocument( $file, $target ), $import->getErrorMessage() );
        $this->assertSame( array( 'Pictures', 'Pictures/a.png', 'content.xml' ), $this->filesBelow( $target ) );
        $this->assertSame( '0700', substr( sprintf( '%o', fileperms( $target ) ), -4 ) );
    }

    public function testAFileThatIsNoZipArchiveIsRefused()
    {
        $file = $this->workDir . '/upload/plain.odt';
        file_put_contents( $file, 'not an archive' );

        $import = new eZOOImport();
        $this->assertFalse( $import->extractDocument( $file, $this->workDir . '/import/five' ) );
        $this->assertSame( eZOOImport::ERROR_ARCHIVE, $import->getErrorNumber() );
    }

    public function testAPictureReferenceOutsideTheImportDirectoryResolvesToNothing()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'Pictures/a.png' => 'png' ) );
        $import = new eZOOImport();
        $import->ImportDir = $this->workDir . '/import/six/';
        $this->assertTrue( $import->extractDocument( $file, $import->ImportDir ) );

        $this->assertSame( $import->ImportDir . 'Pictures/a.png', $import->importFilePath( 'Pictures/a.png' ) );
        $this->assertSame( $import->ImportDir . 'Pictures/a.png', $import->importFilePath( '#Pictures/a.png' ) );
        $this->assertFalse( $import->importFilePath( '../../../../etc/passwd' ) );
        $this->assertFalse( $import->importFilePath( '/etc/passwd' ) );
        $this->assertFalse( $import->importFilePath( 'Pictures/../content.xml' ) );
        $this->assertFalse( $import->importFilePath( 'content.xml' ), 'only pictures are read through a reference' );
        $this->assertFalse( $import->importFilePath( 'Pictures/missing.png' ) );
    }

    public function testEveryImporterGetsItsOwnUnguessableDirectory()
    {
        $one = new eZOOImport();
        $two = new eZOOImport();
        $this->assertNotSame( $one->ImportDir, $two->ImportDir );
        $this->assertMatchesRegularExpression( '#^var/cache/ezodf/import/[0-9a-f]{32}/$#', $one->ImportDir );
        $this->assertSame( eZUser::$currentUserID, $one->currentUserID, 'the constructor runs and knows the importing user' );
    }

    public function testTheEntryPathCheck()
    {
        foreach ( array( 'content.xml', 'Pictures/a.png', './content.xml', 'a/b/c' ) as $path )
        {
            $this->assertTrue( eZOOImport::safeEntryPath( $path ), $path );
        }
        foreach ( array( '', '../x', 'a/../../x', '/etc/passwd', 'C:/x', "a\0b", 'a\\..\\b', "a\nb" ) as $path )
        {
            $this->assertFalse( eZOOImport::safeEntryPath( $path ), json_encode( $path ) );
        }
    }
}
