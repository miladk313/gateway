<?php

namespace Hosseinizadeh\Gateway\Azkivam;

use Hosseinizadeh\Gateway\Exceptions\BankException;

class AzkivamException extends BankException
{
    public static $errors = array(
        0 => 'Request finished successfully',
        1 => 'Internal Server Error',
        2 => 'Resource Not Found',
        4 => 'Malformed Data',
        5 => 'Data Not Found',
        15 => 'Access Denied',
        16 => 'Transaction already reversed',
        17 => 'Ticket Expired',
        18 => 'Signature Invalid',
        19 => 'Ticket unpayable',
        20 => 'Ticket customer mismatch',
        21 => 'Insufficient Credit',
        28 => 'Unverifiable ticket due to status',
        32 => 'Invalid Invoice Data',
        33 => 'Contract is not started',
        34 => 'Contract is expired',
        44 => 'Validation exception',
        51 => 'Request data is not valid',
        59 => 'Transaction not reversible',
        60 => 'Transaction must be in verified state',
    );

    public function __construct($errorId)
    {
        $this->errorId = intval($errorId);

        parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
    }
}
