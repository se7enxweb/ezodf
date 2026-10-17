<?php
/**
 * More archives an attacker could upload as an OpenDocument file, beyond eZOOImportArchiveTest: duplicate entry
 * names, back slashes, drive letters, devices and FIFOs, too many entries, an entry that collides with another's
 * directory, damaged compressed data, a target that is a link, and the clean-up never following links. Kernel-free.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZOOImportZipSlipTest extends TestCase
{
    private $workDir;

    protected function setUp(): void
    {
        if ( !class_exists( 'ZipArchive' ) )
        {
            $this->markTestSkipped( 'The zip extension is needed to build the test archives.' );
        }
        $this->workDir = sys_get_temp_dir() . '/ezodf-zipslip-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->workDir . '/upload', 0700, true );
        mkdir( $this->workDir . '/outside', 0700, true );
    }

    protected function tearDown(): void
    {
        if ( $this->workDir !== null )
        {
            eZOOImport::removeWorkDirectory( $this->workDir );
        }
    }

    /**
     * @param array $entries name => content, or name => array( 'mode' => unix mode, 'content' => ... )
     */
    private function archive( array $entries, $compress = true )
    {
        $file = $this->workDir . '/upload/' . bin2hex( random_bytes( 4 ) ) . '.odt';
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $file, ZipArchive::CREATE ) === true );
        foreach ( $entries as $name => $content )
        {
            $mode = null;
            if ( is_array( $content ) )
            {
                $mode = $content['mode'];
                $content = $content['content'];
            }
            $this->assertTrue( $zip->addFromString( $name, $content ), $name );
            if ( !$compress )
            {
                $zip->setCompressionName( $name, ZipArchive::CM_STORE );
            }
            if ( $mode !== null )
            {
                $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $mode << 16 );
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

    private function assertRefused( $file, $code, $target = null )
    {
        $target = $target ?: $this->workDir . '/import/' . bin2hex( random_bytes( 4 ) );
        $import = new eZOOImport();
        $this->assertFalse( $import->extractDocument( $file, $target ), 'the archive is refused' );
        $this->assertStringContainsString( $code, $import->getErrorMessage() );
        $this->assertSame( array(), $this->filesBelow( $this->workDir . '/outside' ), 'nothing reaches another directory' );
        return $target;
    }

    public function testTwoEntriesOfTheSameNameAreRefusedAndTheFirstIsNotOverwritten()
    {
        // ZipArchive never writes a duplicate name, so the second name is patched in afterwards (same length,
        // and the name is not part of the checksum)
        $file = $this->archive( array( 'content.xml' => $this->content(), 'content.xmZ' => '<evil/>' ) );
        $bytes = file_get_contents( $file );
        $this->assertSame( 2, substr_count( $bytes, 'content.xmZ' ), 'local header and central directory' );
        file_put_contents( $file, str_replace( 'content.xmZ', 'content.xml', $bytes ) );

        $target = $this->assertRefused( $file, 'duplicate_entry' );
        $this->assertSame( array(), $this->filesBelow( $target ), 'a refused archive leaves nothing for the import' );
    }

    public function testAnEntryWithBackSlashesIsRefused()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'Pictures\\..\\..\\..\\outside\\evil.php' => '<?php echo 1;' ) );
        $target = $this->assertRefused( $file, 'unsafe_path' );
        $this->assertSame( array(), $this->filesBelow( $target ) );
    }

    public function testAnEntryWithADriveLetterIsRefused()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'C:/outside/evil.php' => '<?php echo 1;' ) );
        $this->assertRefused( $file, 'unsafe_path' );
    }

    public function testAnEntryThatClimbsOutOfThePicturesDirectoryIsRefused()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'Pictures/./../../outside/evil.php' => '<?php echo 1;' ) );
        $this->assertRefused( $file, 'unsafe_path' );
    }

    #[DataProvider( 'specialFileTypes' )]
    public function testDevicesAndFifosAreRefused( $mode )
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'Pictures/a.png' => array( 'mode' => $mode, 'content' => '' ) ) );
        $this->assertRefused( $file, 'unsafe_type' );
    }

    public static function specialFileTypes()
    {
        return array( 'fifo' => array( 0010644 ), 'character device' => array( 0020644 ),
                      'block device' => array( 0060644 ), 'socket' => array( 0140644 ), 'link' => array( 0120777 ) );
    }

    public function testRegularFilesAndDirectoriesWithUnixModesAreAccepted()
    {
        $file = $this->archive( array( 'content.xml' => array( 'mode' => 0100644, 'content' => $this->content() ),
                                       'Pictures/' => array( 'mode' => 0040755, 'content' => '' ),
                                       'Pictures/a.png' => array( 'mode' => 0100600, 'content' => 'png' ) ) );
        $target = $this->workDir . '/import/modes';
        $import = new eZOOImport();
        $this->assertTrue( $import->extractDocument( $file, $target ), $import->getErrorMessage() );
        $this->assertSame( array( 'Pictures', 'Pictures/a.png', 'content.xml' ), $this->filesBelow( $target ) );
    }

    public function testTooManyEntriesAreRefusedBeforeAnythingIsWritten()
    {
        $entries = array( 'content.xml' => $this->content() );
        for ( $i = 0; $i < eZOOImport::MAX_ARCHIVE_ENTRIES; $i++ )
        {
            $entries["Pictures/$i.png"] = '';
        }
        $file = $this->archive( $entries, false );
        $target = $this->assertRefused( $file, 'too_many_entries' );
        $this->assertDirectoryDoesNotExist( $target, 'the check runs before the directory is created' );
    }

    public function testAnEntryBelowAFileOfTheSameNameIsRefused()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'Pictures/a' => 'file', 'Pictures/a/b.png' => 'png' ) );
        $this->assertRefused( $file, 'unwritable' );
    }

    public function testDamagedCompressedDataEndsTheExtraction()
    {
        $file = $this->archive( array( 'content.xml' => $this->content() . str_repeat( '<p>text</p>', 20000 ) ) );
        $bytes = file_get_contents( $file );
        $header = unpack( 'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength', $bytes );
        $this->assertSame( 0x04034b50, $header['signature'] );
        $this->assertSame( ZipArchive::CM_DEFLATE, $header['method'] );
        $dataStart = 30 + $header['nameLength'] + $header['extraLength'];
        $middle = $dataStart + intdiv( $header['compressed'], 2 );
        for ( $i = 0; $i < 64; $i++ )
        {
            $bytes[$middle + $i] = chr( ord( $bytes[$middle + $i] ) ^ 0xA5 );
        }
        file_put_contents( $file, $bytes );

        $started = microtime( true );
        $import = new eZOOImport();
        $result = $import->extractDocument( $file, $this->workDir . '/import/damaged' );
        $this->assertLessThan( 10, microtime( true ) - $started, 'a damaged entry never keeps the extraction busy' );
        if ( $result === false )
        {
            $this->assertSame( eZOOImport::ERROR_ARCHIVE, $import->getErrorNumber() );
        }
        $this->assertSame( array(), $this->filesBelow( $this->workDir . '/outside' ) );
    }

    public function testATargetThatIsALinkIsRefused()
    {
        $file = $this->archive( array( 'content.xml' => $this->content(), 'Pictures/a.png' => 'png' ) );
        mkdir( $this->workDir . '/import', 0700, true );
        symlink( $this->workDir . '/outside', $this->workDir . '/import/link' );

        $import = new eZOOImport();
        $this->assertFalse( $import->extractDocument( $file, $this->workDir . '/import/link/' ) );
        $this->assertSame( eZOOImport::ERROR_IMPORTING, $import->getErrorNumber() );
        $this->assertSame( array(), $this->filesBelow( $this->workDir . '/outside' ) );
    }

    public function testRemovingAWorkDirectoryNeverFollowsALink()
    {
        file_put_contents( $this->workDir . '/outside/keep.txt', 'keep' );
        $dir = $this->workDir . '/import/' . str_repeat( 'e', 32 );
        mkdir( $dir . '/Pictures', 0700, true );
        symlink( $this->workDir . '/outside', $dir . '/Pictures/link' );
        symlink( $this->workDir . '/outside/keep.txt', $dir . '/file-link' );

        eZOOImport::removeWorkDirectory( $dir );

        $this->assertDirectoryDoesNotExist( $dir );
        $this->assertFileExists( $this->workDir . '/outside/keep.txt' );
    }

    public function testTheSweepNeverFollowsALinkedWorkDirectory()
    {
        file_put_contents( $this->workDir . '/outside/keep.txt', 'keep' );
        $base = $this->workDir . '/ezodf';
        mkdir( $base . '/import', 0700, true );
        $link = $base . '/import/' . str_repeat( 'f', 32 );
        symlink( $this->workDir . '/outside', $link );
        touch( $this->workDir . '/outside', time() - 2 * 86400 );

        eZOOImport::sweepWorkDirectories( $base );

        $this->assertFileExists( $this->workDir . '/outside/keep.txt' );
    }

    public function testMorePathsThatLeaveTheDirectory()
    {
        foreach ( array( '..', 'Pictures/..', 'Pictures/./../x', 'Pictures//../../x', "Pictures/a\x7f", "Pictures/\ta", 'c:x', '\\\\server\\share' ) as $path )
        {
            $this->assertFalse( eZOOImport::safeEntryPath( $path ), json_encode( $path ) );
        }
        foreach ( array( '..', 'Pictures/', 'Pictures', 'content.xml/', 'Thumbnails/thumbnail.png', 'META-INF/manifest.xml' ) as $path )
        {
            $this->assertFalse( eZOOImport::isImportedEntry( $path ), json_encode( $path ) . ' is not extracted' );
        }
    }
}
