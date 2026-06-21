<?php

declare(strict_types=1);

namespace plugins\payment\polygon;

use app\common\PaymentContext;
use plugins\payment\common\ChainPaymentPlugin;

class PolygonPlugin extends ChainPaymentPlugin
{
    protected const QR_PAGE = 'usdt_pay';
    protected const WAP_PAGE = 'usdt_pay';
    protected const NETWORK_NAME = 'Polygon';
    protected const TOKEN_SYMBOL = 'USDT';
    protected const CONTRACT_ADDRESS = self::USDT_CONTRACT;
    protected const DEFAULT_RATE = 7.0;
    protected const DEFAULT_DECIMALS = 2;
    protected const DEFAULT_AMOUNT_FIELD = 'usdt_amount';

    private const USDT_CONTRACT = '0xc2132D05D31c914a87C6611C10748AEb04B58e8F';
    private const API_URL = 'https://api.etherscan.io/v2/api';

    public function usdtpolygon(PaymentContext $ctx): array
    {
        return $this->renderPaymentPage($ctx);
    }

    protected function validateAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }

    protected function fetchTransactions(string $address, int $hours = 2): array
    {
        $apiKey = trim((string)($this->channel['apikey'] ?? ''));
        if ($apiKey === '') {
            return [];
        }

        $hours = max(1, $hours);
        $transactions = $this->fetchPolygonUSDTTransactions($address, $apiKey, $hours);

        usort($transactions, static function (array $left, array $right): int {
            return ($right['timestamp'] ?? 0) <=> ($left['timestamp'] ?? 0);
        });

        return $transactions;
    }

    private function fetchPolygonUSDTTransactions(string $address, string $apiKey, int $hours): array
    {
        $result = [];
        $startTimestamp = time() - ($hours * 3600);
        $params = [
            'chainid' => 137,
            'module' => 'account',
            'action' => 'tokentx',
            'contractaddress' => self::USDT_CONTRACT,
            'address' => $address,
            'page' => 1,
            'offset' => 50,
            'startblock' => 0,
            'endblock' => 99999999,
            'sort' => 'desc',
            'apikey' => $apiKey,
        ];

        try {
            $response = $this->makeApiRequest(self::API_URL . '?' . http_build_query($params));
            if ($response === '') {
                return [];
            }

            $data = json_decode($response, true);
            if (!is_array($data) || (string)($data['status'] ?? '0') !== '1' || empty($data['result'])) {
                return [];
            }

            foreach ($data['result'] as $transaction) {
                $timestamp = (int)($transaction['timeStamp'] ?? 0);
                $to = (string)($transaction['to'] ?? '');
                if ($timestamp < $startTimestamp || strcasecmp($to, $address) !== 0) {
                    continue;
                }

                $amount = ((float)($transaction['value'] ?? 0)) / 1000000;
                if ($amount <= 0) {
                    continue;
                }

                $result[] = [
                    'tx_id' => (string)($transaction['hash'] ?? ''),
                    'from' => (string)($transaction['from'] ?? ''),
                    'to' => $to,
                    'amount' => $amount,
                    'timestamp' => $timestamp,
                    'token' => 'USDT',
                    'contract' => self::USDT_CONTRACT,
                    'confirmed' => true,
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $result;
    }
    private function makeApiRequest(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; PolygonPlugin/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return is_string($response) ? $response : '';
    }

    protected function getTransactionLink(array $transaction): string
    {
        $txId = trim((string)($transaction['tx_id'] ?? ''));
        return $txId !== '' ? 'https://polygonscan.com/tx/' . rawurlencode($txId) : '';
    }
}
