<?php

declare(strict_types=1);

namespace plugins\payment\common;

use app\common\BasePayment;
use app\common\PaymentContext;
use RuntimeException;

abstract class ChainPaymentPlugin extends BasePayment
{
    protected const PAY_CONTEXT_VERSION = 1;
    protected const DEFAULT_RATE = 7.00;
    protected const DEFAULT_DECIMALS = 2;
    protected const DEFAULT_PAYTIME = 360;
    protected const DEFAULT_AMOUNT_FIELD = 'pay_amount';
    protected const NETWORK_NAME = 'Chain';
    protected const TOKEN_SYMBOL = 'USDT';
    protected const CONTRACT_ADDRESS = '';
    protected const QR_PAGE = 'usdt_pay';
    protected const WAP_PAGE = 'usdt_pay';

    public function submit(PaymentContext $ctx): array
    {
        return ['type' => 'jump', 'url' => '/pay/qrcode/' . $ctx->order['trade_no'] . '/?type=' . $ctx->order['typename']];
    }

    public function mapi(PaymentContext $ctx): array
    {
        $typename = trim((string)($ctx->order['typename'] ?? ''));
        if ($typename === '' || !method_exists($this, $typename)) {
            return ['type' => 'error', 'msg' => '不支持的链上支付方式'];
        }

        return $this->{$typename}($ctx);
    }

    public function cron(array $channel): int
    {
        unset($channel);
        return 0;
    }

    protected function renderPaymentPage(PaymentContext $ctx): array
    {
        $context = $this->getPayContext($ctx);
        $ctx->order = array_merge($ctx->order, $context);

        return [
            'type' => 'qrcode',
            'page' => $ctx->isMobile ? static::WAP_PAGE : static::QR_PAGE,
            'url' => (string)($context['address'] ?? ''),
            'expire' => (int)($context['expire_time'] ?? time() + static::DEFAULT_PAYTIME),
        ];
    }

    protected function getPayContext(PaymentContext $ctx): array
    {
        $tradeNo = (string)($ctx->order['trade_no'] ?? '');
        $data = static::lockPayData($tradeNo, function () use ($ctx) {
            return $this->buildPayContext($ctx);
        });

        if (!is_array($data)) {
            $data = $this->buildPayContext($ctx);
            $this->persistPayContext($tradeNo, $data);
        }

        return $data;
    }

    protected function buildPayContext(PaymentContext $ctx): array
    {
        $address = trim((string)($this->channel['appid'] ?? ''));
        if (!$this->validateAddress($address)) {
            throw new RuntimeException($this->getNetworkName() . ' 收款地址格式错误');
        }

        $rate = (float)($this->channel['appkey'] ?? static::DEFAULT_RATE);
        $rate = $rate > 0 ? $rate : static::DEFAULT_RATE;
        $decimals = (int)($this->channel['xiaoshu'] ?? static::DEFAULT_DECIMALS);
        $decimals = max(2, min(6, $decimals));
        $timeout = (int)($this->channel['appurl'] ?? static::DEFAULT_PAYTIME);
        $timeout = $timeout > 0 ? $timeout : static::DEFAULT_PAYTIME;
        $fiatAmount = (float)($ctx->order['realmoney'] ?? 0);
        if ($fiatAmount <= 0) {
            throw new RuntimeException('订单金额无效');
        }

        $amount = round($fiatAmount / $rate, $decimals);
        $createdAt = strtotime((string)($ctx->order['addtime'] ?? ''));
        $createdAt = $createdAt !== false ? $createdAt : time();
        $expireTime = $createdAt + $timeout;

        return [
            'version' => static::PAY_CONTEXT_VERSION,
            'address' => $address,
            'network' => $this->getNetworkName(),
            'network_name' => $this->getNetworkName(),
            'token' => static::TOKEN_SYMBOL,
            'token_symbol' => static::TOKEN_SYMBOL,
            'amount_field' => static::DEFAULT_AMOUNT_FIELD,
            'pay_amount' => $amount,
            static::DEFAULT_AMOUNT_FIELD => $amount,
            'display_amount' => number_format($amount, $decimals, '.', ''),
            'contract' => static::CONTRACT_ADDRESS,
            'contract_address' => static::CONTRACT_ADDRESS,
            'contract_preview' => static::CONTRACT_ADDRESS,
            'display_title' => $this->getNetworkName() . ' ' . static::TOKEN_SYMBOL . ' 支付',
            'display_hint' => '请使用 ' . $this->getNetworkName() . ' 网络向上方地址转账',
            'pay_time' => $timeout,
            'expire_time' => $expireTime,
            'expires_in' => max(0, $expireTime - time()),
            'decimals' => $decimals,
            'status' => 0,
        ];
    }

    protected function persistPayContext(string $tradeNo, array $payload): void
    {
        $this->updateOrderExt($tradeNo, $payload);
    }

    protected function getNetworkName(): string
    {
        return static::NETWORK_NAME;
    }

    abstract protected function validateAddress(string $address): bool;

    abstract protected function fetchTransactions(string $address, int $hours = 2): array;
}
