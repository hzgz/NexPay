<?php

declare(strict_types=1);

namespace plugins\payment\aptos;

use app\common\PaymentContext;
use plugins\payment\common\ChainPaymentPlugin;

class AptosPlugin extends ChainPaymentPlugin
{
    protected const QR_PAGE = 'usdt_pay';
    protected const WAP_PAGE = 'usdt_pay';
    protected const NETWORK_NAME = 'Aptos';
    protected const TOKEN_SYMBOL = 'USDT';
    protected const CONTRACT_ADDRESS = self::APTOS_ASSET_TYPE;
    protected const DEFAULT_RATE = 7.2;
    protected const DEFAULT_DECIMALS = 4;
    protected const DEFAULT_AMOUNT_FIELD = 'usdt_amount';

    private const APTOS_GRAPHQL_URL = 'https://api.mainnet.aptoslabs.com/v1/graphql';
    private const APTOS_ASSET_TYPE = '0x357b0b74bc833e95a115ad22604854d6b0fca151cecd94111770e5d6ffc9dc2b';

    public function usdtaptos(PaymentContext $ctx): array
    {
        return $this->renderPaymentPage($ctx);
    }

    protected function validateAddress(string $address): bool
    {
        return preg_match('/^0x[0-9a-fA-F]{64}$/', $address) === 1;
    }

    protected function fetchTransactions(string $address, int $hours = 2): array
    {
        $result = [];
        $startTime = time() - (max(1, $hours) * 3600);

        $graphqlQuery = 'query AccountTransactionsData($address: String, $limit: Int, $offset: Int) {'
            . ' account_transactions('
            . ' where: {account_address: {_eq: $address}}'
            . ' order_by: {transaction_version: desc}'
            . ' limit: $limit'
            . ' offset: $offset'
            . ' ) {'
            . ' transaction_version'
            . ' fungible_asset_activities('
            . ' where: {'
            . ' asset_type: {_eq: "' . self::APTOS_ASSET_TYPE . '"}'
            . ' is_transaction_success: {_eq: true}'
            . ' }'
            . ' ) {'
            . ' amount'
            . ' asset_type'
            . ' is_transaction_success'
            . ' type'
            . ' owner_address'
            . ' }'
            . ' user_transaction { timestamp }'
            . ' }'
            . '}';

        $payload = [
            'query' => $graphqlQuery,
            'variables' => [
                'address' => $address,
                'limit' => 100,
                'offset' => 0,
            ],
            'operationName' => 'AccountTransactionsData',
        ];
        $headers = ['Content-Type: application/json'];

        try {
            $resp = get_curl(self::APTOS_GRAPHQL_URL, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 0, 0, 0, 0, $headers);
            if (!$resp) {
                return [];
            }

            $data = json_decode($resp, true);
            if (!is_array($data) || empty($data['data']['account_transactions'])) {
                return [];
            }

            foreach ($data['data']['account_transactions'] as $tx) {
                $version = (string)($tx['transaction_version'] ?? '');
                if ($version === '') {
                    continue;
                }

                $userTransaction = $tx['user_transaction'] ?? [];
                $timestampStr = (string)($userTransaction['timestamp'] ?? '');
                if ($timestampStr === '') {
                    continue;
                }

                $txTimestamp = 0;
                try {
                    $parsedTime = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.u', $timestampStr, new \DateTimeZone('UTC'));
                    if (!$parsedTime) {
                        $parsedTime = new \DateTimeImmutable($timestampStr, new \DateTimeZone('UTC'));
                    }
                    $txTimestamp = $parsedTime->getTimestamp();
                } catch (\Throwable $e) {
                    continue;
                }

                if ($txTimestamp < $startTime) {
                    continue;
                }

                $fungibleActivities = $tx['fungible_asset_activities'] ?? [];
                foreach ($fungibleActivities as $activity) {
                    $type = (string)($activity['type'] ?? '');
                    if (stripos($type, '::Deposit') === false) {
                        continue;
                    }

                    $ownerAddress = (string)($activity['owner_address'] ?? '');
                    if ($ownerAddress === '' || strcasecmp($ownerAddress, $address) !== 0) {
                        continue;
                    }

                    $amount = ((float)($activity['amount'] ?? 0)) / 1000000;
                    if ($amount <= 0) {
                        continue;
                    }

                    $result[] = [
                        'tx_id' => $version,
                        'from' => $this->extractSenderFromTransaction($fungibleActivities, $activity),
                        'to' => $address,
                        'amount' => $amount,
                        'timestamp' => $txTimestamp,
                        'token' => 'USDT',
                        'contract' => self::APTOS_ASSET_TYPE,
                        'confirmed' => true,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $result;
    }

    private function extractSenderFromTransaction(array $fungibleActivities, array $depositActivity): string
    {
        $depositAmount = (string)($depositActivity['amount'] ?? '');
        $depositAssetType = (string)($depositActivity['asset_type'] ?? '');

        foreach ($fungibleActivities as $activity) {
            $type = (string)($activity['type'] ?? '');
            if (stripos($type, '::Withdraw') === false) {
                continue;
            }

            if ((string)($activity['amount'] ?? '') !== $depositAmount) {
                continue;
            }
            if ((string)($activity['asset_type'] ?? '') !== $depositAssetType) {
                continue;
            }

            $sender = trim((string)($activity['owner_address'] ?? ''));
            return $sender !== '' ? $sender : 'unknown_sender';
        }

        return 'unknown_sender';
    }

    protected function getTransactionLink(array $transaction): string
    {
        $version = trim((string)($transaction['tx_id'] ?? ''));
        return $version !== '' ? 'https://explorer.aptoslabs.com/txn/' . rawurlencode($version) : '';
    }
}
