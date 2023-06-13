<?php

namespace Hosseinizadeh\Gateway\Bitycle;

use Hosseinizadeh\Gateway\Exceptions\BankException;

class BitycleException extends BankException
{
    public static $errors = array(
        0   => 'Request finished successfully',
        -2  => 'invalid data',
        -3  => 'try later',
        -10 => 'invalid ref_no',
        -6  => 'low balance',
        -7  => 'less than min withdraw',
    );

    public function __construct($errorId)
    {
        $this->errorId = intval($errorId);

        parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
    }
}
