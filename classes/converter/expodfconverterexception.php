<?php
/**
 * File containing the expOdfConverterException class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * A conversion that failed. code() is one of too_large, timeout, unsupported, crashed, no_backend, convert, sandbox,
 * remote; the message says why in one sentence (never a secret or a server path of another user).
 */
class expOdfConverterException extends RuntimeException
{
    /** @var string */
    protected $reason;

    /** @var array backend => message, when several were tried */
    public $tried = array();

    public function __construct( $reason, $message )
    {
        parent::__construct( (string)$message );
        $this->reason = (string)$reason;
    }

    /** @return string the code of the failure */
    public function code()
    {
        return $this->reason;
    }
}
