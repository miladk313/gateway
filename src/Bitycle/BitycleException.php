<?php

namespace Hosseinizadeh\Gateway\Bitycle;

use Hosseinizadeh\Gateway\Exceptions\BankException;

class BitycleException extends BankException
{
    public static $errors = array(
        0 => 'Request finished successfully',
    );

    public function __construct($errorId)
    {
        $this->errorId = intval($errorId);

        parent::__construct(@self::$errors[$this->errorId].' #'.$this->errorId, $this->errorId);
    }
}
