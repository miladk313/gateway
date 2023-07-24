<?php

namespace Hosseinizadeh\Gateway\Bitycle;

use DateTime;
use GuzzleHttp\Client;
use Hosseinizadeh\Gateway\Enum;
use Hosseinizadeh\Gateway\Parsian\ParsianErrorException;
use Hosseinizadeh\Gateway\Parsian\ParsianResult;
use Hosseinizadeh\Gateway\Zarinpalwages\ZarinpalwagesException;
use Illuminate\Support\Facades\Redis;
use SoapClient;
use Hosseinizadeh\Gateway\PortAbstract;
use Hosseinizadeh\Gateway\PortInterface;

class Bitycle extends PortAbstract implements PortInterface
{

    /**
     * Address of main SOAP server
     *
     * @var string
     */
    protected $serverUrl = 'https://pay-api.bitycle.com/api/v1/';

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

    protected $redirectUrl;

    //set ttl for redis
    const ACCESS_TOKEN_TTL = 4 * 60 * 60;

    const REFRESH_TOKEN_TTL = 24 * 60 * 60;

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * @param string $network
     * @return $this
     */
    public function setNetwork(string $network)
    {
        $this->network = $network;

        return $this;
    }

    /**
     * @param string $currency
     * @return $this
     */
    public function setCurrency(string $currency)
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * @param string $uid
     * @return $this
     */
    public function setUid(string $uid)
    {
        $this->uid = $uid;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->client = new Client(['base_uri' => config('gateway.bitycle.url')]);
        return $this->sendPayRequest();
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        $this->client = new Client(['base_uri' => config('gateway.bitycle.url')]);
        parent::verify($transaction);
        return $this->verifyPayment();
        return $this;
    }

    /**
     * Sets callback url
     * @param $url
     * @return Bitycle
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
            $this->callbackUrl = $this->config->get('gateway.bitycle.callback-url');

        $url = $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);

        return $url;
    }

    /**
     * Sets redirect url
     * @param $url
     * @return Bitycle
     */
    function setRedirect($url)
    {
        $this->redirectUrl = $url;
        return $this;
    }

    /**
     * Gets redirect url
     * @return string
     */
    function getRedirect()
    {
        if (!$this->redirectUrl)
            $this->redirectUrl = $this->config->get('gateway.bitycle.redirect_url');

        $url = $this->makeCallback($this->redirectUrl, ['orderId' => $this->providerId]);

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
        $orderId = $this->transactionId();

        $fields = array(
            'u_id' => $this->uid,
            'amount' => $this->amount,
            'currency' => 'USDT',
            'client_ref_no' => $orderId,
            'callback_url' => $this->getCallback(),
            'redirect_url' => $this->getRedirect(),
        );

        try {
            list($result, $err) = $this->walletsAssignWallet($this->network, $fields);
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
            if ($result['code'] == 0) {
                $this->refId = $result['data']["ref_no"];
                $this->pamentUrl = 'https://pay.bitycle.com/assign/'.$result['data']["ref_no"];
                $this->transactionSetRefId();
                return true;
            }
            $this->transactionFailed();
            $this->newLog($result['code'], BitycleException::$errors[$result['code']]);
            throw new BitycleException($result['code']);
        }
    }

    /**
     * Verify payment
     * @authority == Token
     * @throws BitycleException
     */
    protected function verifyPayment()
    {
        $fields = array(
            'ref_no' => $this->refId,
        );

        try {
            return $this->walletsVerifyDeposit($fields);
            list($result, $err) = $this->walletsVerifyDeposit($fields);
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
            if ($result['code'] == 0) {
                $this->transactionSucceed();
                $this->newLog($result['code'], Enum::TRANSACTION_SUCCEED_TEXT);
                return true;
            }
            $this->transactionFailed();
            $this->newLog($result['code'], BitycleException::$errors[$result['code']]);
            throw new BitycleException($result['code']);
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


    protected function sendRequest($url, $method, $params = null)
    {
        try {
            $response = $this->client->request($method, $url, [
                'headers' => ['Authorization' => $this->getLoginToken()],
                'json' => $params,
            ]);
            $result = json_decode($response->getBody()->getContents(), true);
            return array($result, null);
        } catch (Exception $e) {
            return array(null, $e->getMessage());
        }
    }


    protected function saveToRedis($key, $value, $ttl)
    {
        Redis::SETEX($key, $ttl, $value);
    }

    public function getLoginToken()
    {
        $accessToken = Redis::get('access_token');
        $refreshToken = Redis::get('refresh_token');
        if (!$accessToken) {
            // Access token is not present or expired, try to refresh using refresh_token
            if (!$refreshToken) {
                // Refresh token is not present or expired, login again
                $params = [
                    "username" => $this->config->get('gateway.bitycle.username'),
                    "password" => $this->config->get('gateway.bitycle.password'),
                ];

                $login_result = $this->accountsLogin($params);
                if ($login_result['code'] !== '0') {
                    return [
                        'status' => false,
                        'message' => 'Login failed',
                    ];
                }
                // Save tokens to Redis with TTL of 5 hours
                $this->saveToRedis('access_token', $login_result['data']['access_token'], $this::ACCESS_TOKEN_TTL);
                $this->saveToRedis('refresh_token', $login_result['data']['refresh_token'], $this::REFRESH_TOKEN_TTL);
            } else {
                // Refresh access_token using refresh_token
                $refreshToken = $this->accountsRefreshToken(['refresh_token' => $refreshToken]);
                if ($refreshToken['code'] !== '0') {
                    return [
                        'status' => false,
                        'message' => 'Refresh token failed',
                    ];
                }
                $accessToken = $refreshToken['data']['access_token'];
                // Save access_token to Redis with TTL of 5 hours
                $this->saveToRedis('access_token', $accessToken, $this::ACCESS_TOKEN_TTL);
            }
        }
        return $accessToken;
    }

    // start accounts api methods
    public function accountsLogin($params)
    {
        $response = $this->client->request('POST', 'accounts/login', [
            'json' => $params,
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function accountsRefreshToken($params)
    {
        $response = $this->client->request('POST', 'accounts/refresh_token', [
            'json' => $params,
        ]);
        return json_decode($response->getBody()->getContents(), true);
    }

    public function walletsAssignWallet($network, $params)
    {
        return $this->sendRequest('wallets/purchase/' . $network, 'POST', $params);
    }

    public function walletsVerifyDeposit($params)
    {
        $this->client = new Client(['base_uri' => config('gateway.bitycle.url')]);
        return $this->sendRequest('wallets/verify_deposit', 'POST', $params);
    }

    public function walletsVerifyPurchase($params)
    {
        $this->client = new Client(['base_uri' => config('gateway.bitycle.url')]);
        return $this->sendRequest('wallets/verify_purchase', 'POST', $params);
    }

    public function fakeCryptoTransaction($params) {
        $this->client = new Client(['base_uri' => config('gateway.bitycle.url')]);
        return $this->sendRequest('wallets/new_crypto_transaction', 'POST', $params);
    }
}
