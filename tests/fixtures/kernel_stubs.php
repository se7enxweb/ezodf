<?php
/**
 * Stand-ins for the kernel classes eZOOImport::import() uses up to its access check, for
 * eZOOImportAccessTest. Loaded in a separate process only, and only when none of them is loaded yet.
 *
 * @license GNU General Public License v2.0
 */

class eZOOImportTestLog
{
    public static $calls = array();
    public static $access = false;
}

class eZINI
{
    public static $values = array(
        'odf.ini' => array(
            'DocumentType' => array( 'AllowedTypes' => array( 'odt' ), 'ConvertTypes' => array( 'doc' ) ),
            'ODFImport' => array( 'DefaultImportClass' => 'article', 'DefaultImportImageClass' => 'image',
                                  'RegisteredClassArray' => array( 'article' ), 'ConversionDaemon' => 'disabled' ),
            'article' => array( 'Attribute' => array( 'title' => 'title', 'body' => 'body' ) ),
        ),
    );
    private $name;

    public static function instance( $name = 'site.ini' )
    {
        $ini = new self();
        $ini->name = $name;
        return $ini;
    }

    public function hasVariable( $section, $key )
    {
        return isset( self::$values[$this->name][$section][$key] );
    }

    public function variable( $section, $key )
    {
        return self::$values[$this->name][$section][$key] ?? false;
    }
}

class eZSys
{
    public static function cacheDirectory()
    {
        return 'var/cache';
    }
}

class eZHTTPTool
{
    public static function instance()
    {
        return new self();
    }
}

class eZTextCodec
{
    public static function internalCharset()
    {
        return 'utf-8';
    }
}

class eZContentObjectTreeNode
{
    public static function fetch( $nodeID )
    {
        return new self();
    }

    public function attribute( $name )
    {
        return $name === 'class_identifier' ? 'folder' : null;
    }
}

class eZContentClass
{
    public $identifier;

    public static function fetchByIdentifier( $identifier )
    {
        eZOOImportTestLog::$calls[] = 'eZContentClass::fetchByIdentifier ' . $identifier;
        $class = new self();
        $class->identifier = $identifier;
        return $class;
    }

    public function instantiate()
    {
        eZOOImportTestLog::$calls[] = 'instantiate ' . $this->identifier;
        throw new RuntimeException( "An object of class {$this->identifier} was created" );
    }
}

class eZContentObject
{
    public static function fetchByRemoteID( $remoteID )
    {
        eZOOImportTestLog::$calls[] = 'eZContentObject::fetchByRemoteID';
        return false;
    }
}

class eZContentFunctionCollection
{
    public function checkAccess( $function, $node, $classIdentifier, $parentClassIdentifier, $locale )
    {
        eZOOImportTestLog::$calls[] = "checkAccess $function";
        return array( 'result' => eZOOImportTestLog::$access );
    }
}

class eZDB
{
    public static function instance()
    {
        return new self();
    }

    public function begin()
    {
        eZOOImportTestLog::$calls[] = 'eZDB::begin';
    }

    public function commit()
    {
        eZOOImportTestLog::$calls[] = 'eZDB::commit';
    }

    public function rollback()
    {
        eZOOImportTestLog::$calls[] = 'eZDB::rollback';
    }
}
