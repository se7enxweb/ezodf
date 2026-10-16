<?php
/**
 * The request check of the document conversion daemon (scripts/conversion_request.php): the paths of a request went
 * unchecked into an OpenOffice.org macro call. Kernel-free.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

require_once dirname( __DIR__ ) . '/scripts/conversion_request.php';

class eZODFConversionRequestTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        $this->dir = realpath( sys_get_temp_dir() ) . '/ezodf-test-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->dir . '/cache', 0700, true );
        file_put_contents( $this->dir . '/cache/convert_from_ab.doc', 'doc' );
    }

    protected function tearDown(): void
    {
        eZOOImport::removeWorkDirectory( $this->dir );
    }

    public function testARequestOfTheSiteIsAccepted()
    {
        $this->assertNull( ezodfConversionRequestProblem( 'convertToOOo', $this->dir . '/cache/convert_from_ab.doc',
                                                          $this->dir . '/cache/ooo_converted_ab.odt', $this->dir ) );
        $this->assertNull( ezodfConversionRequestProblem( 'convertToPDF', $this->dir . '/cache/convert_from_ab.doc',
                                                          $this->dir . '/cache/out.pdf', $this->dir ) );
    }

    public function testRefusedRequests()
    {
        $source = $this->dir . '/cache/convert_from_ab.doc';
        $cases = array(
            'unknown command' => array( 'Shell', $source, $this->dir . '/cache/x.odt', $this->dir ),
            'macro injection' => array( 'convertToOOo', $source . '") : Shell("touch /tmp/x', $this->dir . '/cache/x.odt', $this->dir ),
            'relative' => array( 'convertToOOo', 'cache/convert_from_ab.doc', $this->dir . '/cache/x.odt', $this->dir ),
            'dot dot' => array( 'convertToOOo', $this->dir . '/cache/../cache/convert_from_ab.doc', $this->dir . '/cache/x.odt', $this->dir ),
            'outside' => array( 'convertToOOo', '/etc/hosts', $this->dir . '/cache/x.odt', $this->dir ),
            'destination outside' => array( 'convertToOOo', $source, '/tmp/x.odt', $this->dir . '/cache' ),
            'wrong suffix' => array( 'convertToOOo', $source, $this->dir . '/cache/x.php', $this->dir ),
            'destination exists' => array( 'convertToDoc', $source, $source, $this->dir ),
            'no source' => array( 'convertToOOo', $this->dir . '/cache/missing.doc', $this->dir . '/cache/x.odt', $this->dir ),
            'no allowed directory' => array( 'convertToOOo', $source, $this->dir . '/cache/x.odt', '' ),
        );
        foreach ( $cases as $name => $arguments )
        {
            $this->assertIsString( call_user_func_array( 'ezodfConversionRequestProblem', $arguments ), $name );
        }
    }

    public function testASymbolicLinkAsSourceIsRefused()
    {
        symlink( '/etc/hosts', $this->dir . '/cache/link.doc' );
        $this->assertIsString( ezodfConversionRequestProblem( 'convertToOOo', $this->dir . '/cache/link.doc',
                                                              $this->dir . '/cache/x.odt', $this->dir ) );
    }
}
