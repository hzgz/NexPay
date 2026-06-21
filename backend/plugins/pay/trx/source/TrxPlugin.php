<?php

declare(strict_types=1);

namespace plugins\payment\trx;

use app\common\PaymentContext;
use plugins\payment\common\ChainPaymentPlugin;

class TrxPlugin extends ChainPaymentPlugin
{
    protected const QR_PAGE = 'usdt_pay';
    protected const WAP_PAGE = 'usdt_pay';
    protected const NETWORK_NAME = 'TRON';
    protected const TOKEN_SYMBOL = 'TRX';
    protected const DEFAULT_RATE = 0.5;
    protected const DEFAULT_DECIMALS = 2;
    protected const DEFAULT_AMOUNT_FIELD = 'trx_amount';

    public function trx(PaymentContext $ctx): array
    {
        return $this->renderPaymentPage($ctx);
    }

    protected function validateAddress(string $address): bool
    {
        return preg_match('/^T[A-Za-z0-9]{33}$/', $address) === 1;
    }

    protected function getDisplayTitle(string $typename = '', array $channel = [], ?PaymentContext $ctx = null, string $token = ''): string
    {
        return 'TRX支付(TRON)';
    }

    protected function getDisplayHint(string $typename = '', array $channel = [], ?PaymentContext $ctx = null, string $token = ''): string
    {
        return '请使用TRON网络向上方地址转账';
    }

    protected function resolveDisplayToken(string $typename = '', array $channel = [], ?PaymentContext $ctx = null): string
    {
        return 'TRX';
    }

    protected function getAmountField(string $typename = '', string $token = ''): string
    {
        return 'trx_amount';
    }

    protected function resolveContractAddress(string $typename = '', array $channel = [], ?PaymentContext $ctx = null, string $token = ''): string
    {
        return '';
    }

    protected function fetchTransactions(string $address, int $hours = 2): array
    {
        $result = [];
        $startTime = time() - (max(1, $hours) * 3600);
        $apiUrl = 'https://apilist.tronscan.org/api/transaction?sort=-timestamp&count=true&limit=50&start=0&address=' . rawurlencode($address);

        try {
            $response = get_curl($apiUrl);
            if (!$response) {
                return [];
            }

            $data = json_decode($response, true);
            if (!is_array($data) || empty($data['data'])) {
                return [];
            }

            foreach ($data['data'] as $item) {
                $toList = $item['toAddressList'] ?? [];
                if (!is_array($toList) || empty($item['amount'])) {
                    continue;
                }

                $timestamp = (int)floor(((int)($item['timestamp'] ?? 0)) / 1000);
                $amount = ((float)($item['amount'] ?? 0)) / 1000000;
                if ($timestamp < $startTime || $amount <= 0) {
                    continue;
                }

                foreach ($toList as $toAddress) {
                    if (strcasecmp((string)$toAddress, $address) !== 0) {
                        continue;
                    }

                    $result[] = [
                        'tx_id' => (string)($item['hash'] ?? ''),
                        'from' => (string)($item['ownerAddress'] ?? ''),
                        'to' => (string)$toAddress,
                        'amount' => $amount,
                        'timestamp' => $timestamp,
                        'token' => 'TRX',
                        'contract' => '',
                        'confirmed' => true,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $result;
    }

    protected function getTransactionLink(array $transaction): string
    {
        $txId = trim((string)($transaction['tx_id'] ?? ''));
        return $txId !== '' ? 'https://tronscan.org/#/transaction/' . rawurlencode($txId) : '';
    }
}
