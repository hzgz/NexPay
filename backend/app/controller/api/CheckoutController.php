<?php

namespace app\controller\api;

use app\constant\StatusCode;
use app\controller\BaseApiController;
use app\exception\BusinessException;
use app\service\payment\OrderService;
use app\service\payment\QrCodeService;
use app\service\system\MerchantChannelService;
use app\service\system\SettingsService;
use support\Request;
use support\Response;

class CheckoutController extends BaseApiController
{
    public function show(Request $request, string $trade_no): Response
    {
        $order = OrderService::findByTradeNo($trade_no);
        $settings = MerchantChannelService::all((int)$order->merchant_id)['payment_settings'] ?? [];
        $displayAddress = QrCodeService::displayValueForOrder($order);
        $rawAddress = $displayAddress !== '' ? $displayAddress : (string)$order->payment_address;
        $qrImageUrl = QrCodeService::hasDisplayableQr($order)
            ? htmlspecialchars(QrCodeService::imageUrl($trade_no, 320), ENT_QUOTES, 'UTF-8')
            : '';

        $subject = htmlspecialchars((string)$order->subject, ENT_QUOTES, 'UTF-8');
        $amount = htmlspecialchars((string)$order->payable_amount, ENT_QUOTES, 'UTF-8');
        $address = htmlspecialchars($rawAddress, ENT_QUOTES, 'UTF-8');
        $returnUrl = htmlspecialchars((string)$order->return_url, ENT_QUOTES, 'UTF-8');
        $statusUrl = '/pay/status/' . rawurlencode($trade_no);
        $expireAt = htmlspecialchars((string)$order->expire_time, ENT_QUOTES, 'UTF-8');
        $autoRedirect = !empty($settings['auto_redirect']) ? 'true' : 'false';
        $addressCard = $this->renderPaymentAddress($qrImageUrl, $address);
        $themeVars = '--bg1:#f4f8ff;--bg2:#edf4fd;--panel:#ffffff;--panel-soft:#f8fbff;--line:#dbe7f6;--text:#172033;--muted:#607089;--accent:#1677ff;--accent-soft:#eaf3ff;--ok:#00a76f;';

        $html = <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexPay 支付收银台</title>
  <style>
    :root{{$themeVars}}
    *{box-sizing:border-box}
    body{margin:0;font-family:"Segoe UI",PingFang SC,Microsoft YaHei,sans-serif;background:radial-gradient(circle at top,rgba(22,119,255,.12),transparent 26%),linear-gradient(180deg,var(--bg1),var(--bg2) 58%);color:var(--text)}
    .wrap{max-width:1080px;margin:0 auto;padding:40px 18px}
    .card{background:var(--panel);border:1px solid var(--line);border-radius:28px;padding:28px;box-shadow:0 28px 80px rgba(22,44,77,.10)}
    .eyebrow{display:inline-flex;padding:8px 14px;border-radius:999px;background:var(--accent-soft);color:var(--accent);font-weight:700;font-size:12px;letter-spacing:.08em;text-transform:uppercase}
    h1{margin:16px 0 10px;font-size:34px;line-height:1.1}
    .grid{display:grid;grid-template-columns:1.25fr .95fr;gap:18px;margin-top:24px}
    .panel{background:var(--panel-soft);border:1px solid var(--line);border-radius:20px;padding:22px}
    .label{color:var(--muted);font-size:13px;letter-spacing:.04em;text-transform:uppercase}
    .value{font-size:28px;font-weight:800;margin-top:10px;word-break:break-all}
    .mono{font-family:Consolas,Monaco,monospace;font-size:14px;line-height:1.7;word-break:break-all}
    .status{display:inline-flex;gap:8px;align-items:center;font-weight:700;color:var(--accent)}
    .dot{width:10px;height:10px;border-radius:50%;background:var(--accent)}
    .address-box{margin-top:16px;padding:14px 16px;border-radius:16px;background:#fff;border:1px solid var(--line)}
    .address-box img{display:block;width:100%;max-width:260px;border-radius:16px;border:1px solid var(--line);background:#fff}
    .meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}
    .meta .panel{padding:16px}
    @media (max-width: 820px){.grid{grid-template-columns:1fr}.meta{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <span class="eyebrow">NexPay 收银台</span>
      <h1>{$subject}</h1>
      <div class="grid">
        <div class="panel">
          <div class="label">支付金额</div>
          <div class="value">{$amount}</div>
          <div class="label" style="margin-top:20px">收款二维码</div>
          {$addressCard}
        </div>
        <div class="panel">
          <div class="label">订单状态</div>
          <div id="status" class="status"><span class="dot"></span>等待支付</div>
          <div class="meta">
            <div class="panel">
              <div class="label">平台订单号</div>
              <div class="mono">{$trade_no}</div>
            </div>
            <div class="panel">
              <div class="label">过期时间</div>
              <div class="mono">{$expireAt}</div>
            </div>
          </div>
          <div class="label" style="margin-top:20px">支付内容原文</div>
          <div class="mono">{$address}</div>
        </div>
      </div>
    </div>
  </div>
  <script>
    const returnUrl = '{$returnUrl}';
    const autoRedirect = {$autoRedirect};
    let redirected = false;

    const statusEl = document.getElementById('status');

    function setStatus(text, ok) {
      statusEl.innerHTML = '<span class="dot"></span>' + text;
      statusEl.style.color = ok ? 'var(--ok)' : 'var(--accent)';
      statusEl.querySelector('.dot').style.background = ok ? 'var(--ok)' : 'var(--accent)';
    }

    const poll = async () => {
      const resp = await fetch('{$statusUrl}');
      const json = await resp.json();
      if (json?.data?.status_text) {
        const paid = json.data.status === 1;
        setStatus(json.data.status_text, paid);
        if (paid && autoRedirect && returnUrl && !redirected) {
          redirected = true;
          setTimeout(() => window.location.href = returnUrl, 1800);
        }
      }
    };

    poll();
    setInterval(poll, 3000);
  </script>
</body>
</html>
HTML;

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public function status(Request $request, string $trade_no)
    {
        return $this->execute(function () use ($trade_no) {
            $order = OrderService::findByTradeNo($trade_no);
            $settings = MerchantChannelService::all((int)$order->merchant_id)['payment_settings'] ?? [];
            $texts = [
                0 => '等待支付',
                1 => '支付成功',
                2 => '支付失败',
                3 => '订单过期',
                4 => '订单关闭',
            ];

            return $this->success([
                'trade_no' => $order->trade_no,
                'status' => (int)$order->status,
                'status_text' => $texts[(int)$order->status] ?? '未知状态',
                'pay_time' => (string)$order->pay_time,
                'expire_time' => (string)$order->expire_time,
                'return_url' => (string)$order->return_url,
                'callback_status' => (int)$order->callback_status,
                'auto_redirect' => !empty($settings['auto_redirect']),
            ]);
        });
    }

    public function mockComplete(Request $request, string $trade_no)
    {
        return $this->execute(function () use ($trade_no) {
            $settings = SettingsService::all(false);
            $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];
            if (
                !(bool)config('app.debug')
                || empty($payment['payment_test_enabled'])
                || empty($payment['test_auto_complete'])
            ) {
                throw new BusinessException('资源不存在', StatusCode::NOT_FOUND);
            }

            $target = OrderService::findByTradeNo($trade_no);
            $requestPayload = is_array($target->request_payload ?? null) ? $target->request_payload : [];
            $meta = is_array($requestPayload['_meta'] ?? null) ? $requestPayload['_meta'] : [];
            if (($meta['business'] ?? '') !== 'homepage_payment_test') {
                throw new BusinessException('仅测试支付订单允许模拟完成', StatusCode::BUSINESS_ERROR);
            }

            $order = OrderService::completeByTradeNo($trade_no, [
                'source' => 'debug-manual',
                'confirmations' => 1,
                'txid' => 'MOCK-' . strtoupper(substr(md5($trade_no . time()), 0, 20)),
            ]);

            return $this->success([
                'trade_no' => $order->trade_no,
                'status' => (int)$order->status,
            ], '模拟完成');
        });
    }

    public function qrImage(Request $request, string $trade_no): Response
    {
        $size = (int)$request->get('size', 320);
        return QrCodeService::imageResponseByTradeNo($trade_no, $size);
    }

    private function renderPaymentAddress(string $imageUrl, string $escapedAddress): string
    {
        if ($imageUrl !== '') {
            return '<div class="address-box"><img src="' . $imageUrl . '" alt="支付二维码"></div>';
        }

        if ($escapedAddress !== '') {
            return '<div class="address-box mono">' . $escapedAddress . '</div>';
        }

        return '<div class="address-box mono">未生成支付内容</div>';
    }
}
