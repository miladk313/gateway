<?php

namespace Hosseinizadeh\Gateway\SnappPay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Hosseinizadeh\Gateway\PortAbstract;
use Hosseinizadeh\Gateway\PortInterface;

class SnappPay extends PortAbstract implements PortInterface
{
    protected $baseUrl;
    protected $mobileNumber;

    public function __construct()
    {
        $this->baseUrl = config('gateway.snapp.base_url');
        parent::__construct();
    }

    public function set($amount)
    {
        $this->amount = $amount;
        return $this;
    }

    public function ready()
    {
        $this->newTransaction();
        return $this->sendPayRequest();
    }

    public function verify($transaction)
    {
        parent::verify($transaction);
        return $this->verifyPayment($transaction->ref_id);
    }

    public function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    public function getCallback()
    {
        return $this->callbackUrl ?: route('plugin.bank.callback');
    }

    public function setMobileNumber($number)
    {
        $this->mobileNumber = $number;
    }

    protected function getAccessToken()
    {
        return Cache::remember('snapp_token', 3000, function () {
            $clientId = config('gateway.snapp.client_id');
            $clientSecret = config('gateway.snapp.client_secret');
            $username = config('gateway.snapp.username');
            $password = config('gateway.snapp.password');

            $response = Http::asForm()
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode("$clientId:$clientSecret")
                ])
                ->post("{$this->baseUrl}/api/online/v1/oauth/token", [
                    'grant_type' => 'password',
                    'scope'      => 'online-merchant',
                    'username'   => $username,
                    'password'   => $password,
                ]);

            if ($response->failed()) {
                throw new \Exception('Failed to get SnappPay token: ' . $response->body());
            }

            return $response->json()['access_token'];
        });
    }

    protected function sendPayRequest()
    {
        $token = $this->getAccessToken();

        $transactionId = $this->transactionId();
        $this->transactionId = $transactionId;

        $payload = [
            'amount' => $this->amount,
            'cartList' => [
                [
                    'cartId' => 1,
                    'cartItems' => [[
                        'amount' => $this->amount,
                        'category' => 'general',
                        'count' => 1,
                        'id' => 1,
                        'name' => 'SnappPay Order',
                        'commissionType' => 1
                    ]],
                    'isShipmentIncluded' => false,
                    'isTaxIncluded' => false,
                    'shippingAmount' => 0,
                    'taxAmount' => 0,
                    'totalAmount' => $this->amount
                ]
            ],
            'discountAmount' => 0,
            'externalSourceAmount' => 0,
            'mobile' => '+98'.preg_replace('/^0/', '', $this->mobileNumber),
            'paymentMethodTypeDto' => 'INSTALLMENT',
            'returnURL' => $this->getCallback(),
            'transactionId' => $transactionId
        ];

        $response = Http::withToken($token)
            ->post("{$this->baseUrl}/api/online/payment/v1/token", $payload);

        if ($response->failed()) {
            $this->transactionFailed();
            $this->newLog('snapp-token-error', $response->body());
            throw new \Exception('SnappPay purchase failed');
        }

        $result = $response->json()['response'] ?? [];
        $this->refId = $result['paymentToken'] ?? null;
        $this->pamentUrl = $result['paymentPageUrl'] ?? null;

        $this->transactionSetRefId();

        return true;
    }

    public function redirect()
    {
        return redirect()->away($this->pamentUrl);
    }

    public function redirectUrl()
    {
        return $this->pamentUrl;
    }

    public function verifyPayment(string $paymentToken)
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/api/online/payment/v1/verify", [
                'paymentToken' => $paymentToken,
            ]);

        $result = $response->json();

        if (!($result['successful'] ?? false)) {
            $this->transactionFailed();
            $this->newLog($response['errorData']['errorCode'], SnappPayException::$errors[$response['errorData']['errorCode']]);
            throw new SnappPayException($response['errorData']['errorCode']);
        }

        $this->transactionSucceed();
        return true;
    }

    public function settlePayment(string $paymentToken)
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/api/online/payment/v1/settle", [
                'paymentToken' => $paymentToken,
            ]);

        return $response->json();
    }

    public function revertPayment(string $paymentToken)
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/api/online/payment/v1/revert", [
                'paymentToken' => $paymentToken,
            ]);

        return $response->json();
    }

    public function cancelPayment(string $paymentToken)
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/api/online/payment/v1/cancel", [
                'paymentToken' => $paymentToken,
            ]);

        return $response->json();
    }

    public function getPaymentStatus(string $paymentToken)
    {
        $response = Http::withToken($this->getAccessToken())
            ->get("{$this->baseUrl}/api/online/payment/v1/status", [
                'paymentToken' => $paymentToken,
            ]);

        return $response->json();
    }

    public function updatePayment(array $payload)
    {
        $response = Http::withToken($this->getAccessToken())
            ->post("{$this->baseUrl}/api/online/payment/v1/update", $payload);

        return $response->json();
    }
}
