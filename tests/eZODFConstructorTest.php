<?php
/**
 * No class of ezodf relies on a PHP 4 constructor (a method named like the class): PHP 8 does not call it.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZODFConstructorTest extends TestCase
{
    public function testEveryClassWithAPhp4ConstructorAlsoHasConstruct()
    {
        $root = dirname( __DIR__ );
        $files = array_merge( glob( $root . '/classes/*.php' ), glob( $root . '/uploadhandlers/*.php' ) );
        $this->assertNotEmpty( $files );

        $missing = array();
        foreach ( $files as $file )
        {
            $tokens = token_get_all( file_get_contents( $file ) );
            $class = null;
            $methods = array();
            foreach ( $tokens as $i => $token )
            {
                if ( !is_array( $token ) )
                    continue;
                if ( $token[0] === T_CLASS )
                {
                    for ( $j = $i + 1; isset( $tokens[$j] ); $j++ )
                    {
                        if ( is_array( $tokens[$j] ) && $tokens[$j][0] === T_STRING )
                        {
                            $class = $tokens[$j][1];
                            $methods[$class] = array();
                            break;
                        }
                    }
                }
                if ( $token[0] === T_FUNCTION && $class !== null )
                {
                    for ( $j = $i + 1; isset( $tokens[$j] ); $j++ )
                    {
                        if ( is_array( $tokens[$j] ) && $tokens[$j][0] === T_STRING )
                        {
                            $methods[$class][] = strtolower( $tokens[$j][1] );
                            break;
                        }
                        if ( $tokens[$j] === '(' )
                            break;
                    }
                }
            }
            foreach ( $methods as $className => $names )
            {
                if ( in_array( strtolower( $className ), $names, true ) && !in_array( '__construct', $names, true ) )
                {
                    $missing[] = basename( $file ) . ': ' . $className;
                }
            }
        }
        $this->assertSame( array(), $missing );
    }
}
