<?php
/**
 * File containing the expOdfSettings class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The settings of ezodf 7 as the administration changes them: the settings pages write
 * var/<site>/ezodf/settings.json (the extension's own store, never settings/override), and every value not set there
 * comes from odf.ini (the extension's defaults, which a site may still override in its INI files as before).
 *
 *   expOdfSettings::get( 'converter.timeout' )       the effective value, typed
 *   expOdfSettings::source( 'converter.timeout' )    'store', 'ini' or 'default'
 *   expOdfSettings::save( array( 'converter.timeout' => 120 ) )   validated; returns the errors (key => message)
 *
 * Writes are refused while site.ini [ConfigurationSettings] ManagedByDeployment=enabled refuses configuration writes
 * from the web (expManagedConfiguration::refusesWrite()); the pages show the values read-only then.
 */
class expOdfSettings
{
    /** @var array|null the store, read once per request (reset() forgets it) */
    protected static $store = null;

    /**
     * The settings: key => array( type, default, INI group, INI name, [min, max | choices], label ).
     * @return array
     */
    public static function schema()
    {
        return array(
            'converter.mode' => array( 'type' => 'choice', 'default' => 'auto', 'ini' => array( 'Converter', 'Mode' ),
                                       'choices' => array( 'auto', 'server', 'cli', 'remote', 'php' ) ),
            'converter.installation' => array( 'type' => 'choice', 'default' => 'auto', 'ini' => array( 'Converter', 'Installation' ),
                                               'choices' => array( 'auto', 'downloaded', 'system', 'custom' ) ),
            'converter.binary' => array( 'type' => 'path', 'default' => '', 'ini' => array( 'Converter', 'Binary' ) ),
            'converter.version_pin' => array( 'type' => 'version', 'default' => '', 'ini' => array( 'Converter', 'VersionPin' ) ),
            'converter.download_url' => array( 'type' => 'url', 'default' => 'https://download.documentfoundation.org/libreoffice/stable/',
                                               'ini' => array( 'Converter', 'DownloadURL' ) ),
            'converter.server_enabled' => array( 'type' => 'bool', 'default' => false, 'ini' => array( 'Converter', 'Server' ) ),
            'converter.timeout' => array( 'type' => 'int', 'default' => 90, 'min' => 10, 'max' => 900, 'ini' => array( 'Converter', 'Timeout' ) ),
            'converter.max_megabytes' => array( 'type' => 'int', 'default' => 50, 'min' => 1, 'max' => 1024, 'ini' => array( 'Converter', 'MaxMegabytes' ) ),
            'converter.max_parallel' => array( 'type' => 'int', 'default' => 1, 'min' => 1, 'max' => 8, 'ini' => array( 'Converter', 'MaxParallel' ) ),
            'converter.restart_after' => array( 'type' => 'int', 'default' => 200, 'min' => 1, 'max' => 100000, 'ini' => array( 'Converter', 'RestartAfter' ) ),
            'converter.memory_megabytes' => array( 'type' => 'int', 'default' => 4096, 'min' => 512, 'max' => 32768, 'ini' => array( 'Converter', 'MemoryMegabytes' ) ),
            'converter.remote_url' => array( 'type' => 'url', 'default' => '', 'ini' => array( 'Converter', 'RemoteURL' ) ),
            'converter.remote_kind' => array( 'type' => 'choice', 'default' => 'unoserver', 'ini' => array( 'Converter', 'RemoteKind' ),
                                              'choices' => array( 'unoserver', 'gotenberg' ) ),
            'converter.remote_username' => array( 'type' => 'string', 'default' => '', 'ini' => array( 'Converter', 'RemoteUsername' ) ),
            'converter.remote_password' => array( 'type' => 'secret', 'default' => '', 'ini' => array( 'Converter', 'RemotePassword' ) ),
            'import.class_text' => array( 'type' => 'identifier', 'default' => '', 'ini' => array( 'ODFImport', 'DefaultImportClass' ) ),
            'import.class_folder' => array( 'type' => 'identifier', 'default' => 'folder', 'ini' => array( 'BulkImport', 'FolderClass' ) ),
            'import.class_image' => array( 'type' => 'identifier', 'default' => '', 'ini' => array( 'ODFImport', 'DefaultImportImageClass' ) ),
            'import.class_file' => array( 'type' => 'identifier', 'default' => 'file', 'ini' => array( 'BulkImport', 'FileClass' ) ),
            'import.keep_original' => array( 'type' => 'bool', 'default' => false, 'ini' => array( 'BulkImport', 'KeepOriginal' ) ),
            'import.max_files' => array( 'type' => 'int', 'default' => 500, 'min' => 1, 'max' => 10000, 'ini' => array( 'BulkImport', 'MaxFiles' ) ),
            'import.max_batch_megabytes' => array( 'type' => 'int', 'default' => 500, 'min' => 1, 'max' => 20480, 'ini' => array( 'BulkImport', 'MaxBatchMegabytes' ) ),
            'remote.max_megabytes' => array( 'type' => 'int', 'default' => 50, 'min' => 1, 'max' => 1024, 'ini' => array( 'RemoteSettings', 'MaxMegabytes' ) ),
            'remote.timeout' => array( 'type' => 'int', 'default' => 30, 'min' => 5, 'max' => 600, 'ini' => array( 'RemoteSettings', 'Timeout' ) ),
            'remote.allow_private_hosts' => array( 'type' => 'bool', 'default' => false, 'ini' => array( 'RemoteSettings', 'AllowPrivateHosts' ) ),
            'retention.keep_days' => array( 'type' => 'int', 'default' => 14, 'min' => 1, 'max' => 3650, 'ini' => array( 'Retention', 'KeepDays' ) ),
            'ai.enabled' => array( 'type' => 'bool', 'default' => false, 'ini' => array( 'AISettings', 'Enabled' ) ),
        );
    }

    /** @return string the store's file */
    public static function file()
    {
        return expOdfPaths::base() . '/settings.json';
    }

    /** Forgets what was read (tests, a long-lived worker between requests). */
    public static function reset()
    {
        self::$store = null;
    }

    /** @return array the values the administration set */
    public static function store()
    {
        if ( self::$store === null )
        {
            $file = self::file();
            $data = is_file( $file ) ? json_decode( (string)file_get_contents( $file ), true ) : null;
            self::$store = is_array( $data ) ? $data : array();
        }
        return self::$store;
    }

    /**
     * @param string $key
     * @return mixed the effective value, typed
     */
    public static function get( $key )
    {
        $schema = self::schema();
        if ( !isset( $schema[$key] ) )
            throw new InvalidArgumentException( "unknown ezodf setting $key" );
        $store = self::store();
        if ( array_key_exists( $key, $store ) )
            return self::cast( $schema[$key], $store[$key] );
        $ini = self::iniValue( $schema[$key] );
        return $ini === null ? $schema[$key]['default'] : self::cast( $schema[$key], $ini );
    }

    /** @return string where the value comes from: store, ini or default */
    public static function source( $key )
    {
        $schema = self::schema();
        if ( array_key_exists( $key, self::store() ) )
            return 'store';
        return isset( $schema[$key] ) && self::iniValue( $schema[$key] ) !== null ? 'ini' : 'default';
    }

    /** @return array key => effective value of every setting (a secret as '' or '********') */
    public static function all( $maskSecrets = true )
    {
        $out = array();
        foreach ( self::schema() as $key => $spec )
        {
            $value = self::get( $key );
            if ( $maskSecrets && $spec['type'] === 'secret' )
                $value = $value !== '' ? '********' : '';
            $out[$key] = $value;
        }
        return $out;
    }

    /** @return bool whether the web may write the store now */
    public static function writable()
    {
        if ( class_exists( 'expManagedConfiguration' ) && method_exists( 'expManagedConfiguration', 'refusesWrite' ) )
        {
            try
            {
                if ( expManagedConfiguration::refusesWrite() )
                    return false;
            }
            catch ( Exception $e )
            {
            }
        }
        return true;
    }

    /**
     * Validates and stores values. A secret sent as '' keeps the stored one, as '-' removes it.
     *
     * @param array $values key => value
     * @return array key => error message (empty: stored)
     */
    public static function save( array $values )
    {
        if ( !self::writable() )
            return array( '' => 'The configuration is managed by the deployment: settings cannot be changed here.' );
        $schema = self::schema();
        $errors = array();
        $clean = array();
        foreach ( $values as $key => $value )
        {
            if ( !isset( $schema[$key] ) )
            {
                $errors[$key] = 'unknown setting';
                continue;
            }
            $spec = $schema[$key];
            if ( $spec['type'] === 'secret' )
            {
                if ( $value === '' || $value === '********' )
                    continue;
                if ( $value === '-' )
                {
                    $clean[$key] = null;
                    continue;
                }
            }
            $error = self::validate( $spec, $value );
            if ( $error !== null )
                $errors[$key] = $error;
            else
                $clean[$key] = self::cast( $spec, $value );
        }
        if ( $errors )
            return $errors;
        $store = self::store();
        foreach ( $clean as $key => $value )
        {
            if ( $value === null )
                unset( $store[$key] );
            else
                $store[$key] = $value;
        }
        self::write( $store );
        return array();
    }

    /** Removes values from the store, so odf.ini or the default applies again. */
    public static function forget( array $keys )
    {
        if ( !self::writable() )
            return false;
        $store = self::store();
        foreach ( $keys as $key )
            unset( $store[$key] );
        self::write( $store );
        return true;
    }

    protected static function write( array $store )
    {
        expOdfPaths::ensure( dirname( self::file() ) );
        $file = self::file();
        $tmp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
        file_put_contents( $tmp, json_encode( $store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        @chmod( $tmp, 0660 );
        rename( $tmp, $file );
        self::$store = $store;
    }

    /** @return mixed|null the odf.ini value, null when not set */
    protected static function iniValue( array $spec )
    {
        if ( !class_exists( 'eZINI' ) || empty( $spec['ini'] ) )
            return null;
        $ini = eZINI::instance( 'odf.ini' );
        list( $group, $name ) = $spec['ini'];
        if ( !$ini->hasVariable( $group, $name ) )
            return null;
        $value = $ini->variable( $group, $name );
        return $value === '' && $spec['type'] !== 'string' && $spec['type'] !== 'path' ? null : $value;
    }

    /** @return string|null an error, or null when the value is valid */
    public static function validate( array $spec, $value )
    {
        if ( is_array( $value ) )
            return 'a single value is expected';
        $value = trim( (string)$value );
        switch ( $spec['type'] )
        {
            case 'int':
                if ( !preg_match( '/^-?\d+$/', $value ) )
                    return 'a whole number is expected';
                if ( isset( $spec['min'] ) && (int)$value < $spec['min'] )
                    return 'at least ' . $spec['min'];
                if ( isset( $spec['max'] ) && (int)$value > $spec['max'] )
                    return 'at most ' . $spec['max'];
                return null;
            case 'bool':
                return in_array( strtolower( $value ), array( '1', '0', 'true', 'false', 'enabled', 'disabled', 'yes', 'no', 'on', 'off', '' ), true ) ? null : 'yes or no is expected';
            case 'choice':
                return in_array( $value, $spec['choices'], true ) ? null : 'one of ' . implode( ', ', $spec['choices'] );
            case 'url':
                if ( $value === '' )
                    return null;
                $scheme = strtolower( (string)parse_url( $value, PHP_URL_SCHEME ) );
                return in_array( $scheme, array( 'http', 'https' ), true ) && parse_url( $value, PHP_URL_HOST ) ? null : 'an http or https address is expected';
            case 'path':
                if ( $value === '' )
                    return null;
                return $value[0] === '/' && strpos( $value, '..' ) === false && !preg_match( '/[\x00-\x1f;|&`$<>]/', $value ) ? null : 'an absolute path is expected';
            case 'version':
                return $value === '' || preg_match( '/^\d+\.\d+(\.\d+){0,2}$/', $value ) ? null : 'a version such as 26.8.1 is expected';
            case 'identifier':
                return $value === '' || preg_match( '/^[a-z0-9_]{1,50}$/', $value ) ? null : 'a class identifier is expected';
            case 'string':
            case 'secret':
                return strlen( $value ) <= 500 ? null : 'too long';
        }
        return null;
    }

    protected static function cast( array $spec, $value )
    {
        switch ( $spec['type'] )
        {
            case 'int':
                $v = (int)$value;
                if ( isset( $spec['min'] ) )
                    $v = max( $spec['min'], $v );
                if ( isset( $spec['max'] ) )
                    $v = min( $spec['max'], $v );
                return $v;
            case 'bool':
                return is_bool( $value ) ? $value : in_array( strtolower( trim( (string)$value ) ), array( '1', 'true', 'enabled', 'yes', 'on' ), true );
            case 'choice':
                $value = trim( (string)$value );
                return in_array( $value, $spec['choices'], true ) ? $value : $spec['default'];
            default:
                return trim( (string)$value );
        }
    }
}
