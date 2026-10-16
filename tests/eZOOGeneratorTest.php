<?php
/**
 * The export directory of eZOOGenerator: every export wrote var/cache/ezodf/ootest.odt, so two exports at the same
 * time handed each other's documents out. Kernel-free.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZOOGeneratorTest extends TestCase
{
    public function testTwoGeneratorsWorkInDifferentDirectories()
    {
        $one = new eZOOGenerator();
        $two = new eZOOGenerator();

        $this->assertNotSame( $one->OORootDir, $two->OORootDir );
        $this->assertMatchesRegularExpression( '#^var/cache/ezodf/[0-9a-f]{32}/$#', $one->OORootDir );
        foreach ( array( $one, $two ) as $generator )
        {
            $this->assertSame( $generator->OORootDir . 'export/', $generator->OOExportDir );
            $this->assertSame( $generator->OORootDir . 'template/', $generator->OOTemplateDir );
        }
    }

    public function testRemoveDocumentRemovesTheDocumentAndItsDirectory()
    {
        $base = sys_get_temp_dir() . '/ezodf-test-' . bin2hex( random_bytes( 8 ) );
        $dir = $base . '/var/cache/ezodf/' . bin2hex( random_bytes( 16 ) );
        mkdir( $dir . '/export', 0700, true );
        file_put_contents( $dir . '/document.odt', 'odt' );
        $neighbour = $base . '/var/cache/ezodf/keep.txt';
        file_put_contents( $neighbour, 'keep' );

        eZOOGenerator::removeDocument( $dir . '/document.odt' );

        $this->assertDirectoryDoesNotExist( $dir );
        $this->assertFileExists( $neighbour, 'only the export directory of the document goes' );
        eZOOImport::removeWorkDirectory( $base );
    }

    public function testRemoveDocumentLeavesADirectoryItDidNotCreate()
    {
        $base = sys_get_temp_dir() . '/ezodf-test-' . bin2hex( random_bytes( 8 ) );
        mkdir( $base, 0700, true );
        file_put_contents( $base . '/other.odt', 'odt' );
        file_put_contents( $base . '/keep.txt', 'keep' );

        eZOOGenerator::removeDocument( $base . '/other.odt' );

        $this->assertFileDoesNotExist( $base . '/other.odt' );
        $this->assertFileExists( $base . '/keep.txt' );
        eZOOImport::removeWorkDirectory( $base );
    }
}
