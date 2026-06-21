<?php

declare(strict_types=1);

namespace plugins\payment\trc20;

use app\common\PaymentContext;
use plugins\payment\common\ChainPaymentPlugin;

class Trc20Plugin extends ChainPaymentPlugin
{
    protected const QR_PAGE = 'usdt_pay';
    protected const WAP_PAGE = 'usdt_pay';
    protected const NETWORK_NAME = 'TRC20';
    protected const TOKEN_SYMBOL = 'USDT';
    protected const CONTRACT_ADDRESS = self::USDT_CONTRACT;
    protected const DEFAULT_RATE = 7.2;
    protected const DEFAULT_DECIMALS = 2;
    protected const DEFAULT_AMOUNT_FIELD = 'usdt_amount';

    private const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    public function usdttrc20(PaymentContext $ctx): array
    {
        return $this->renderPaymentPage($ctx);
    }

    protected function validateAddress(string $address): bool
    {
        return preg_match('/^T[A-Za-z0-9]{33}$/', $address) === 1;
    }

    protected function fetchTransactions(string $address, int $hours = 2): array
    {
        $result = [];
        $startTime = time() - (max(1, $hours) * 3600);
        $apiUrl = 'https://apilist.tronscan.org/api/transfer?sort=-timestamp&count=true&limit=50&start=0&address=' . rawurlencode($address);

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
                $toAddress = (string)($item['toAddress'] ?? '');
                if ($toAddress === '' || strcasecmp($toAddress, $address) !== 0) {
                    continue;
                }

                $tokenInfo = $item['tokenInfo'] ?? [];
                if (strcasecmp((string)($tokenInfo['tokenAbbr'] ?? ''), 'USDT') !== 0) {
                    continue;
                }

                $timestamp = (int)floor(((int)($item['timestamp'] ?? 0)) / 1000);
                $amount = ((float)($item['amount'] ?? 0)) / 1000000;
                if ($timestamp < $startTime || $amount <= 0) {
                    continue;
                }

                $result[] = [
                    'tx_id' => (string)($item['transactionHash'] ?? ''),
                    'from' => (string)($item['transferFromAddress'] ?? ''),
                    'to' => $toAddress,
                    'amount' => $amount,
                    'timestamp' => $timestamp,
                    'token' => 'USDT',
                    'contract' => (string)($item['contract_address'] ?? self::USDT_CONTRACT),
                    'confirmed' => true,
                ];
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
