<?php

namespace Hosseinizadeh\Gateway\Azkivam;

use DateTime;
use Hosseinizadeh\Gateway\Enum;
use Hosseinizadeh\Gateway\Parsian\ParsianErrorException;
use Hosseinizadeh\Gateway\Parsian\ParsianResult;
use Hosseinizadeh\Gateway\Zarinpalwages\ZarinpalwagesException;
use SoapClient;
use Hosseinizadeh\Gateway\PortAbstract;
use Hosseinizadeh\Gateway\PortInterface;

class Azkivam extends PortAbstract implements PortInterface
{
    const subUrls = [
        'purchase'      => '/payment/purchase',
        'paymentStatus' => '/payment/status',
        'verify'        => '/payment/verify',
    ];

    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://api.azkiloan.com';

    /**
     * Payment providerId
     *
     * @var string
     */
    protected $providerId;

    /**
     * Payment Description
     *
     * @var string
     */
    protected $description;

    /**
     * Payer Mobile Number
     *
     * @var string
     */
    protected $mobileNumber;

    /**
     * Payer Pament Url
     *
     * @var string
     */
    protected $pamentUrl;

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        return $this->sendPayRequest();
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);
        return $this->verifyPayment();
        return $this;
    }

    /**
     * Sets callback url
     * @param $url
     * @return Azkivam
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.azkivam.callback-url');

        $url = $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);

        return $url;
    }

    /**
     * Gets Pament url
     * @return string
     */
    function getPamentUrl()
    {
        return $this->pamentUrl;
    }

    /**
     * Send pay request to parsian gateway
     *
     * authority  === Token
     * @return bool
     *
     * @throws \SoapFault
     */
    protected function sendPayRequest()
    {
        $this->newTransaction();
        if (!isset($this->items) || !is_array($this->items)) {
            return false;
        }

        $fields = array(
            'merchant_id' => $this->config->get('gateway.azkivam.merchant-id'),
            'amount' => $this->amount,
            'redirect_uri' => $this->getCallback(),
            'fallback_uri' => $this->getCallback(),
            'provider_id' => $this->providerId,
            'mobile_number' => $this->mobileNumber ? $this->mobileNumber : $this->config->get('gateway.azkivam.mobile', ''),
            'items' => $this->items,
        );
        $jsonData = json_encode($fields);

        try {
            list($result, $err) = $this->curlPost($jsonData,self::subUrls['purchase']);
        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('curl', $e->getMessage());
            throw $e;
        }
        if ($err) {
            $this->transactionFailed();
            $this->newLog('curl', $err);
            throw $err;
        } else {
            if ($result['rsCode'] == 0) {
                $this->refId = $result['result']["ticket_id"];
                $this->pamentUrl = $result['result']["payment_uri"];
                $this->transactionSetRefId();
                return true;
            }
            $this->transactionFailed();
            $this->newLog($result['rsCode'], AzkivamException::$errors[$result['rsCode']]);
            throw new AzkivamException($result['rsCode']);
        }
    }

    public function redirect()
    {
        // TODO: Implement redirect() method.
    }

    /**
     * Set providerId
     *
     * @param $providerId
     * @return void
     */
    public function setProviderId($providerId)
    {
        $this->providerId = $providerId;
    }

    /**
     * Set Description
     *
     * @param $description
     * @return void
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Set Payer Mobile Number
     *
     * @param $number
     * @return void
     */
    public function setMobileNumber($number)
    {
        $this->mobileNumber = $number;
    }

    /**
     * @param array $items
     * @return $this
     */
    public function setItems(array $items)
    {
        $this->items = $items;
        return $this;
    }

    /**
     * @param $jsonData
     * @param $subUrl
     * @return array
     */
    protected function curlPost($jsonData, $subUrl)
    {
        $ch = curl_init($this->serverUrl . $subUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonData),
            'Signature: ' . $this->makeSignature($subUrl,'POST'),
            'MerchantId: ' . $this->config->get('gateway.azkivam.merchant-id')
        ));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $result = json_decode($result, true);
        curl_close($ch);
        return array($result, $err);
    }

    protected function makeSignature($sub_url, $request_method = 'POST')
    {
        $time = time();
        $key  = $this->config->get('gateway.azkivam.key');

        $plain_signature = "{$sub_url}#{$time}#{$request_method}#{$key}";
        $encrypt_method  = "AES-256-CBC";
        $secret_key      = hex2bin($key);
        $secret_iv       = str_repeat(0, 16);

        $digest = @openssl_encrypt($plain_signature, $encrypt_method, $secret_key, OPENSSL_RAW_DATA);

        return bin2hex($digest);
    }

    /**
     * Verify payment
     * @authority == Token
     * @throws AzkivamException
     */
    protected function verifyPayment()
    {
        $fields = array(
            'ticket_id' => $this->refId,
        );
        $jsonData = json_encode($fields);

        try {
            list($result, $err) =  $this->curlPost($jsonData, self::subUrls['verify']);
        } catch (\Exception $e) {
            $this->transactionFailed();
            $this->newLog('curl', $e->getMessage());
            throw $e;
        }
        if ($err) {
            $this->transactionFailed();
            $this->newLog('curl', $err);
            throw $err;
        } else {
            if ($result['rsCode'] == 0) {
                $this->transactionSucceed();
                $this->newLog($result['rsCode'], Enum::TRANSACTION_SUCCEED_TEXT);
                return true;
            }
            $this->transactionFailed();
            $this->newLog($result['rsCode'], AzkivamException::$errors[$result['rsCode']]);
            throw new AzkivamException($result['rsCode']);
        }
    }
}
