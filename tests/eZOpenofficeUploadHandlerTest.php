<?php
/**
 * The upload handler for OpenDocument and Word files: its PHP 4 constructor was not called under PHP 8, so
 * eZContentUpload's "new $handlerClass()" ended in an ArgumentCountError from eZContentUploadHandler::__construct(),
 * and a failed import was reported as success. Kernel-free.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZOpenofficeUploadHandlerTestImport
{
    public $calls = array();

    public function import( $file, $placeNodeID, $originalFileName, $importType = 'import', $upload = null, $locale = false )
    {
        $this->calls[] = array( $file, $placeNodeID, $originalFileName, $importType );
        return false;
    }

    public function getErrorMessage()
    {
        return 'The document could not be imported.';
    }
}

class eZOpenofficeUploadHandlerTestHandler extends eZOpenofficeUploadHandler
{
    public $import;

    protected function createImport()
    {
        return $this->import;
    }
}

class eZOpenofficeUploadHandlerTest extends TestCase
{
    public function testTheHandlerCanBeInstantiatedLikeTheKernelDoes()
    {
        $handlerClass = 'eZOpenofficeUploadHandler';
        $handler = new $handlerClass();

        $this->assertInstanceOf( 'eZContentUploadHandler', $handler );
        $this->assertSame( 'OOo file handling', $handler->Name );
        $this->assertSame( 'openoffice', $handler->Identifier );
    }

    public function testAFailedImportIsReportedWithItsError()
    {
        $handler = new eZOpenofficeUploadHandlerTestHandler();
        $handler->import = new eZOpenofficeUploadHandlerTestImport();
        $upload = null;
        $result = array( 'errors' => array() );

        $returned = $handler->handleFile( $upload, $result, '/tmp/phpUpload', '../../dir/My file.odt', array(), 2, false );

        $this->assertFalse( $returned );
        $this->assertSame( array( array( 'description' => 'The document could not be imported.' ) ), $result['errors'] );
        $this->assertSame( array( array( '/tmp/phpUpload', 2, 'My file.odt', 'import' ) ), $handler->import->calls,
                           'the uploaded file is imported where it is, under the base name the browser sent' );
    }
}
