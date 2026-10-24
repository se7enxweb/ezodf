<?php
/**
 * File containing the expOdfInstallation class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * A LibreOffice installation ezodf can use:
 *
 *   downloaded   a build the administration installed with one click (expOdfInstaller), below
 *                var/<site>/ezodf/libreoffice/<version>/ and recorded in libreoffice/installed.json
 *   system       soffice of the operating system's packages (/usr/lib64/libreoffice, /usr/lib/libreoffice) or of an
 *                /opt/libreoffice* installation, found without running anything
 *   custom       converter.binary of the settings (a path to soffice)
 *
 * The active one follows converter.installation: auto = the downloaded build when there is one, else the system's.
 * Versions are read from the installation's share/registry/main.xcd, so detection runs nothing.
 */
class expOdfInstallation
{
    /** @var string downloaded, system or custom */
    public $kind;

    /** @var string the real path of program/soffice */
    public $soffice;

    /** @var string the installation's root (the directory with program/ and share/) */
    public $root;

    /** @var string LibreOffice 26.8.1.1 */
    public $version = '';

    /** @var string the Python that has the uno module of this installation */
    public $python = '';

    /** @var array what installed.json says of a downloaded build */
    public $record = array();

    public function __construct( $kind, $soffice, array $record = array() )
    {
        $this->kind = $kind;
        $this->soffice = (string)( realpath( $soffice ) ?: $soffice );
        $this->root = dirname( dirname( $this->soffice ) );
        $this->record = $record;
        $this->version = self::readVersion( $this->root );
        $this->python = self::findPython( $this->root, $kind );
    }

    /** @return array for the templates and JSON */
    public function toArray()
    {
        return array( 'kind' => $this->kind, 'soffice' => $this->soffice, 'root' => $this->root, 'version' => $this->version,
                      'python' => $this->python, 'installed' => isset( $this->record['installed'] ) ? (int)$this->record['installed'] : 0,
                      'verified' => isset( $this->record['verified'] ) ? $this->record['verified'] : '',
                      'release' => isset( $this->record['version'] ) ? (string)$this->record['version'] : '',
                      'id' => $this->id() );
    }

    /** @return string downloaded:26.8.1.1, system:/usr/lib64/libreoffice ... */
    public function id()
    {
        return $this->kind . ':' . ( $this->kind === 'downloaded' && isset( $this->record['version'] ) ? $this->record['version'] : $this->root );
    }

    /** @return string the version number alone (26.8.1.1) */
    public function number()
    {
        return preg_match( '/(\d+(?:\.\d+)+)/', $this->version, $m ) ? $m[1] : '';
    }

    /** @return string LibreOffice 26.8.1.1, '' when the installation names none */
    public static function readVersion( $root )
    {
        $file = $root . '/share/registry/main.xcd';
        if ( !is_file( $file ) )
            return '';
        $head = (string)@file_get_contents( $file, false, null, 0, 4194304 );
        $name = preg_match( '/oor:name="ooName"><value>([^<]+)</', $head, $m ) ? $m[1] : 'LibreOffice';
        return preg_match( '/oor:name="ooSetupVersionAboutBox"><value>([^<]+)</', $head, $m ) ? trim( $name . ' ' . $m[1] ) : '';
    }

    /** @return string the Python of an installation: its own (TDF builds), else the system's with pyuno */
    public static function findPython( $root, $kind )
    {
        if ( is_file( $root . '/program/python' ) && is_executable( $root . '/program/python' ) )
            return $root . '/program/python';
        if ( $kind !== 'downloaded' )
        {
            foreach ( glob( '/usr/lib*/python3*/site-packages/uno.py' ) ?: array() as $uno )
            {
                if ( preg_match( '#/(python3\.\d+)/#', $uno, $m ) && is_executable( '/usr/bin/' . $m[1] ) )
                    return '/usr/bin/' . $m[1];
            }
            foreach ( glob( '/usr/lib/python3/dist-packages/uno.py' ) ?: array() as $uno )
                if ( is_executable( '/usr/bin/python3' ) )
                    return '/usr/bin/python3';
        }
        return '';
    }

    /** @return string the directory of the downloaded builds */
    public static function downloadDir()
    {
        return expOdfPaths::base() . '/libreoffice';
    }

    /** @return expOdfInstallation[] every installation found, id => installation */
    public static function all()
    {
        $out = array();
        foreach ( self::downloaded() as $i )
            $out[$i->id()] = $i;
        foreach ( self::system() as $i )
            $out[$i->id()] = $i;
        $custom = expOdfSettings::get( 'converter.binary' );
        if ( $custom !== '' && is_file( $custom ) && is_executable( $custom ) )
        {
            $i = new self( 'custom', $custom );
            $out[$i->id()] = $i;
        }
        return $out;
    }

    /** @return expOdfInstallation[] the builds installed from the admin, newest first */
    public static function downloaded()
    {
        $out = array();
        $data = expOdfPaths::readJson( self::downloadDir() . '/installed.json' );
        $list = isset( $data['builds'] ) && is_array( $data['builds'] ) ? $data['builds'] : array();
        foreach ( $list as $record )
        {
            if ( !isset( $record['soffice'] ) || !is_file( $record['soffice'] ) )
                continue;
            $real = realpath( $record['soffice'] );
            $base = realpath( self::downloadDir() );
            if ( $real === false || $base === false || strpos( $real, $base . '/' ) !== 0 )
                continue; // a downloaded build lives below the download directory, nowhere else
            $out[] = new self( 'downloaded', $real, $record );
        }
        usort( $out, function ( $a, $b ) { return version_compare( $b->number(), $a->number() ); } );
        return $out;
    }

    /** @return expOdfInstallation[] the system's installations */
    public static function system()
    {
        $candidates = array( '/usr/lib64/libreoffice/program/soffice', '/usr/lib/libreoffice/program/soffice' );
        foreach ( glob( '/opt/libreoffice*/program/soffice' ) ?: array() as $path )
            $candidates[] = $path;
        foreach ( array( '/usr/bin/soffice', '/usr/local/bin/soffice', '/usr/bin/libreoffice' ) as $link )
            if ( is_file( $link ) && ( $real = realpath( $link ) ) && basename( $real ) === 'soffice' )
                $candidates[] = $real;
        $out = array();
        $seen = array();
        foreach ( $candidates as $path )
        {
            $real = realpath( $path );
            if ( $real === false || isset( $seen[$real] ) || !is_executable( $real ) )
                continue;
            $seen[$real] = true;
            $out[] = new self( 'system', $real );
        }
        return $out;
    }

    /** @return expOdfInstallation|null the installation conversions use */
    public static function active()
    {
        $choice = expOdfSettings::get( 'converter.installation' );
        $downloaded = self::downloaded();
        $system = self::system();
        if ( $choice === 'custom' )
        {
            $custom = expOdfSettings::get( 'converter.binary' );
            return $custom !== '' && is_file( $custom ) && is_executable( $custom ) ? new self( 'custom', $custom ) : null;
        }
        if ( $choice === 'downloaded' )
        {
            $pin = expOdfSettings::get( 'converter.version_pin' );
            foreach ( $downloaded as $i )
                if ( $pin === '' || strpos( $i->number() . '.', $pin . '.' ) === 0 )
                    return $i;
            return $downloaded ? $downloaded[0] : null;
        }
        if ( $choice === 'system' )
            return $system ? $system[0] : null;
        if ( $downloaded )
            return $downloaded[0];
        return $system ? $system[0] : null;
    }

    /** @return array the families this installation can open (from its registry files) */
    public function modules()
    {
        $out = array();
        foreach ( array( 'writer' => 'text', 'calc' => 'spreadsheet', 'impress' => 'presentation', 'draw' => 'drawing', 'pdfimport' => 'pdf' ) as $file => $family )
            $out[$family] = is_file( $this->root . '/share/registry/' . $file . '.xcd' );
        return $out;
    }

    /** @return array the filters of the installation (expOdfFilterCatalogue) */
    public function filters()
    {
        return expOdfFilterCatalogue::forInstallation( $this );
    }
}
