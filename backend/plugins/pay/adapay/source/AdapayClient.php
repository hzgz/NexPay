<?php

declare(strict_types=1);

namespace plugins\payment\adapay;

/**
 * https://doc.adapay.tech/document/api/#/
 */
class AdapayClient
{
    const SDK_VERSION = 'v1.0.0';
    private string $gateWayUrl = 'https://api.adapay.tech';
    private string $rsaPublicKey = "MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCwN6xgd6Ad8v2hIIsQVnbt8a3JituR8o4Tc3B5WlcFR55bz4OMqrG/356Ur3cPbc2Fe8ArNd/0gZbC9q56Eb16JTkVNA/fye4SXznWxdyBPR7+guuJZHc/VW2fKH2lfZ2P3Tt0QkKZZoawYOGSMdIvO+WqK44updyax0ikK6JlNQIDAQAB";
    private string $api_key;
    private string $rsaPrivateKey;
    private ?string $app_id;

    public function __construct(string $api_key_live, string $rsa_private_key, ?string $app_id = null)
    {
        $this->api_key = $api_key_live;
        $this->rsaPrivateKey = $rsa_private_key;
        $this->app_id = $app_id;
    }

    public function request(string $method, string $endpoint, ?array $params = null, bool $json = true): array
    {
        $req_url = $this->gateWayUrl . $endpoint;
        $headers = [];
        $postData = '';
        if ($method == 'GET') {
            $signstr = '';
            if ($params) {
                ksort($params);
                $req_url .= '?' . http_build_query($params);
                $signstr = http_build_query($params);
            }
        } elseif ($json) {
            $postData = json_encode($params);
            $signstr = $postData;
            $headers[] = 'Content-Type: application/json';
        } else {
            $postData = $params;
            if (is_array($postData)) {
                $signstr = $this->createLinkstring($postData);
            } else {
                $signstr = $postData;
            }
        }
        $headers[] = 'Authorization: ' . $this->api_key;
        $headers[] = 'Signature: ' . $this->generateSignature($endpoint, $signstr);
        $headers[] = 'sdk_version: ' . self::SDK_VERSION;

        $response = get_curl($req_url, $postData, 0, 0, 0, 0, 0, $headers);

        if (!$response || !($result = json_decode($response, true))) {
            throw new \Exception('返回内容为空或解析失败');
        }
        if (!isset($result['data']) && isset($result['message'])) {
            throw new \Exception($result['message']);
        }
        $data = json_decode($result['data'], true);

        if ($data['status'] !== 'succeeded' && $data['status'] !== 'pending' && empty($data['expend'])) {
            if (isset($data['error_code'])) {
                throw new \Exception('[' . $data['error_code'] . ']' . $data['error_msg']);
            } elseif (isset($data['error_msg'])) {
                throw new \Exception($data['error_msg']);
            } else {
                throw new \Exception('返回数据解析失败'.$result['data']);
            }
        }
        return $data;
    }

    //创建支付对象
    public function createPayment(array $params): array
    {
        $endpoint = '/v1/payments';
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($params, $public_params);
        return $this->request('POST', $endpoint, $params);
    }

    //通用请求（page网关）
    public function queryAdapay(array $params): array
    {
        $this->gateWayUrl = "https://page.adapay.tech";
        $adapayFuncCode = $params["adapay_func_code"];
        $endpoint = '/v1/' . str_replace(".", "/", $adapayFuncCode);
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($public_params, $params);
        return $this->request('POST', $endpoint, $params);
    }

    //通用请求（api网关）
    public function requestAdapay(array $params): array
    {
        $adapayFuncCode = $params["adapay_func_code"];
        $endpoint = '/v1/' . str_replace(".", "/", $adapayFuncCode);
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($public_params, $params);
        return $this->request('POST', $endpoint, $params);
    }

    //查询支付对象
    public function queryPayment(string $id): array
    {
        $endpoint = '/v1/payments/' . $id;
        return $this->request('GET', $endpoint, null);
    }

    //创建退款对象
    public function createRefund(array $params): array
    {
        $charge_id = $params['payment_id'] ?? '';
        $endpoint = '/v1/payments/' . $charge_id . '/refunds';
        return $this->request('POST', $endpoint, $params);
    }

    //查询退款对象
    public function queryRefund(array $params): array
    {
        $endpoint = '/v1/payments/refunds';
        return $this->request('GET', $endpoint, $params);
    }

    //创建用户对象
    public function createMember(string $member_id): array
    {
        $params = [
            'app_id' => $this->app_id,
            'member_id' => $member_id,
        ];
        $endpoint = '/v1/members';
        return $this->request('POST', $endpoint, $params);
    }

    //创建结算账户对象
    public function createSettleAccount(string $member_id, array $account_info): array
    {
        $params = [
            'app_id' => $this->app_id,
            'member_id' => $member_id,
            'channel' => 'bank_account',
            'account_info' => $account_info,
        ];
        $endpoint = '/v1/settle_accounts';
        return $this->request('POST', $endpoint, $params);
    }

    //查询结算账户对象
    public function querySettleAccount(string $member_id, string $settle_account_id): array
    {
        $params = [
            'app_id' => $this->app_id,
            'member_id' => $member_id,
            'settle_account_id' => $settle_account_id,
        ];
        $endpoint = '/v1/settle_accounts/' . $settle_account_id;
        return $this->request('GET', $endpoint, $params);
    }

    //删除结算账户对象
    public function deleteSettleAccount(string $member_id, string $settle_account_id): array
    {
        $params = [
            'app_id' => $this->app_id,
            'member_id' => $member_id,
            'settle_account_id' => $settle_account_id,
        ];
        $endpoint = '/v1/settle_accounts/delete';
        return $this->request('POST', $endpoint, $params);
    }

    //创建支付确认对象
    public function createPaymentConfirm(array $params): array
    {
        $endpoint = '/v1/payments/confirm';
        return $this->request('POST', $endpoint, $params);
    }

    //查询支付确认对象
    public function queryPaymentConfirm(string $payment_confirm_id): array
    {
        $params = [
            'payment_confirm_id' => $payment_confirm_id,
        ];
        $endpoint = '/v1/payments/confirm/' . $payment_confirm_id;
        return $this->request('GET', $endpoint, $params);
    }

    //创建支付撤销对象
    public function createPaymentReverse(array $params): array
    {
        $endpoint = '/v1/payments/reverse';
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($params, $public_params);
        return $this->request('POST', $endpoint, $params);
    }

    //查询支付撤销对象
    public function queryPaymentReverse(string $reverse_id): array
    {
        $params = [
            'reverse_id' => $reverse_id,
        ];
        $endpoint = '/v1/payments/reverse/' . $reverse_id;
        return $this->request('GET', $endpoint, $params);
    }

    //创建取现对象
    public function createDrawCash(array $params): array
    {
        $endpoint = '/v1/cashs';
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($params, $public_params);
        return $this->request('POST', $endpoint, $params);
    }

    //查询取现对象
    public function queryDrawCash(string $order_no): array
    {
        $endpoint = '/v1/cashs/stat';
        $params = [
            'order_no' => $order_no,
        ];
        return $this->request('GET', $endpoint, $params);
    }

    //查询账户余额
    public function queryBalance(string $member_id, ?string $settle_account_id = null): array
    {
        $endpoint = '/v1/settle_accounts/balance';
        $params = [
            'app_id' => $this->app_id,
            'member_id' => $member_id,
        ];
        if ($settle_account_id) {
            $params['settle_account_id'] = $settle_account_id;
        }
        return $this->request('GET', $endpoint, $params);
    }

    //钱包登录
    public function walletLogin(string $member_id, string $ip): array
    {
        $endpoint = '/v1/walletLogin';
        $params = [
            'app_id' => $this->app_id,
            'member_id' => $member_id,
            'ip' => $ip,
        ];
        return $this->request('GET', $endpoint, $params);
    }

    //账户转账
    public function createTransfer(array $params): array
    {
        $endpoint = '/v1/settle_accounts/transfer';
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($params, $public_params);
        return $this->request('POST', $endpoint, $params);
    }

    //账户转账查询
    public function queryTransfer(array $params): array
    {
        $endpoint = '/v1/settle_accounts/transfer/list';
        $public_params = [
            'app_id' => $this->app_id,
        ];
        $params = array_merge($public_params, $params);
        return $this->request('GET', $endpoint, $params);
    }

    private function generateSignature(string $endpoint, string $postData): string
    {
        $data = $this->gateWayUrl . $endpoint . $postData;
        return $this->SHA1withRSA($data);
    }

    private function createLinkstring(array $params): string
    {
        ksort($params);
        $arg = "";
        foreach ($params as $key => $val) {
            if ($val instanceof \CURLFile || $val === '' || $val === null) continue;
            $arg .= $key . "=" . $val . "&";
        }
        $arg = substr($arg, 0, -1);
        return $arg;
    }

    private function SHA1withRSA(string $data): string
    {
        $privKey = trim($this->rsaPrivateKey);
        $key = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($privKey, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        $keyid = openssl_pkey_get_private($key);
        if (!$keyid) {
            throw new \Exception('签名失败，商户私钥不正确');
        }
        openssl_sign($data, $signature, $keyid, OPENSSL_ALGO_SHA1);
        return base64_encode($signature);
    }

    public function verifySign(string $signature, string $data): bool
    {
        $pubKey = trim($this->rsaPublicKey);
        $key = "-----BEGIN PUBLIC KEY-----\n" . wordwrap($pubKey, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
        $keyid = openssl_pkey_get_public($key);
        if (!$keyid) {
            throw new \Exception('验签失败，AdaPay公钥不正确');
        }
        $result = openssl_verify($data, base64_decode($signature), $keyid, OPENSSL_ALGO_SHA1);
        return $result === 1;
    }

    public function getPublicKey(): string
    {
        $privKey = trim($this->rsaPrivateKey);
        $key = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($privKey, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
        $keyid = openssl_pkey_get_private($key);
        if (!$keyid) {
            throw new \Exception('商户私钥不正确');
        }
        $details = openssl_pkey_get_details($keyid);
        return pemToBase64($details['key']);
    }
}
