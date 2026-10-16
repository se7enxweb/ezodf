<?php
/**
 * Bootstrap of the ezodf tests: no kernel, no database, no siteaccess.
 *
 * The extension lives at extension/ezodf of an installation; its vendor/autoload.php brings the kernel's class
 * map. The import needs the PHP zip extension (ZipArchive). The stand-ins below are defined only when the real class is not loaded yet (checked without
 * autoloading), so the tests never reach the user session, the debug log or the translation system.
 *
 * Run from the installation root:
 *   vendor/bin/phpunit --bootstrap extension/ezodf/tests/bootstrap.php extension/ezodf/tests/
 *
 * @license GNU General Public License v2.0
 */

$ezodfRoot = dirname( __DIR__ );
$installationRoot = dirname( $ezodfRoot, 2 );
if ( is_file( $installationRoot . '/vendor/autoload.php' ) )
{
    require_once $installationRoot . '/vendor/autoload.php';
}

if ( !class_exists( 'ezpI18n', false ) )
{
    class ezpI18n
    {
        public static function tr( $context, $source, $comment = null, $arguments = null )
        {
            return strtr( $source, (array) $arguments );
        }
    }
}

if ( !class_exists( 'eZDebug', false ) )
{
    class eZDebug
    {
        public static $messages = array();

        public static function writeError( $message, $label = '' )
        {
            self::$messages[] = array( 'error', $message, $label );
        }

        public static function writeWarning( $message, $label = '' )
        {
            self::$messages[] = array( 'warning', $message, $label );
        }

        public static function writeNotice( $message, $label = '' )
        {
            self::$messages[] = array( 'notice', $message, $label );
        }

        public static function writeDebug( $message, $label = '' )
        {
            self::$messages[] = array( 'debug', $message, $label );
        }
    }
}

if ( !class_exists( 'eZUser', false ) )
{
    class eZUser
    {
        public static $currentUserID = 14;

        public static function currentUser()
        {
            return new self();
        }

        public function id()
        {
            return self::$currentUserID;
        }
    }
}

if ( !class_exists( 'eZContentUploadHandler', false ) )
{
    $kernelHandler = $installationRoot . '/kernel/classes/ezcontentuploadhandler.php';
    if ( is_file( $kernelHandler ) )
    {
        require_once $kernelHandler;
    }
}

require_once $ezodfRoot . '/classes/ezooimport.php';
require_once $ezodfRoot . '/classes/ezoogenerator.php';
require_once $ezodfRoot . '/classes/ezooconverter.php';
require_once $ezodfRoot . '/uploadhandlers/ezopenofficeuploadhandler.php';
