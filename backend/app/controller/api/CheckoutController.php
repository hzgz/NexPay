<?php

namespace app\controller\api;

use app\constant\StatusCode;
use app\controller\BaseApiController;
use app\exception\BusinessException;
use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\OrderService;
use app\service\payment\QrCodeService;
use app\service\system\EncodingRepairService;
use app\service\system\MerchantChannelService;
use app\service\system\PaymentMetaService;
use app\service\system\SettingsService;
use support\Request;
use support\Response;

class CheckoutController extends BaseApiController
{
    private const DEFAULT_VOICE_TEMPLATE = '收到来自{{product_name}}的支付，金额{{paid_amount}}元，支付方式{{payment_method}}';

    private const TEMPLATE_ALIAS_MAP = [
        'classic-blue' => 'nexpay-standard',
        'hg-pay-1' => 'nexpay-standard',
        'hg-pay-2' => 'nexpay-standard',
        'modern-float' => 'nexpay-standard',
        'nexpay-standard' => 'nexpay-standard',
        'nexpay-center' => 'nexpay-standard',
        'nexpay-dialog' => 'nexpay-standard',
        'nexpay-float' => 'nexpay-standard',
    ];

    private const TEMPLATE_TOKEN_ALIAS_MAP = [
        '[平台订单号]' => '{{platform_order_no}}',
        '[商户订单号]' => '{{merchant_order_no}}',
        '[商品名称]' => '{{product_name}}',
        '[实付价格]' => '{{paid_amount}}',
        '[订单价格]' => '{{order_amount}}',
        '[支付方式]' => '{{payment_method}}',
        '{{平台订单号}}' => '{{platform_order_no}}',
        '{{商户订单号}}' => '{{merchant_order_no}}',
        '{{商品名称}}' => '{{product_name}}',
        '{{实付价格}}' => '{{paid_amount}}',
        '{{订单价格}}' => '{{order_amount}}',
        '{{支付方式}}' => '{{payment_method}}',
    ];

    public function show(Request $request, string $trade_no): Response
    {
        $order = $this->normalizeCheckoutOrderState(OrderService::findByTradeNo($trade_no));
        $settings = MerchantChannelService::all((int) $order->merchant_id)['payment_settings'] ?? [];
        $template = $this->normalizeTemplateCode((string) ($settings['template'] ?? ''));
        $templateVariables = $this->checkoutTemplateVariables($order);

        $voiceTemplate = trim((string) ($settings['voice_content'] ?? ''));
        if ($voiceTemplate === '') {
            $voiceTemplate = self::DEFAULT_VOICE_TEMPLATE;
        }

        $view = [
            'template' => $template,
            'subject' => (string) ($order->subject ?? ''),
            'amount' => $this->normalizeAmountText($order->payable_amount ?? $order->amount ?? '0.00'),
            'trade_no' => (string) ($order->trade_no ?? $trade_no),
            'out_trade_no' => (string) ($order->out_trade_no ?? ''),
            'method_name' => $this->methodName((string) ($order->channel_code ?? '')),
            'created_at' => (string) ($order->created_at ?? ''),
            'pay_time' => (string) ($order->pay_time ?? ''),
            'expire_time' => (string) ($order->expire_time ?? ''),
            'return_url' => (string) ($order->return_url ?? ''),
            'status_url' => '/pay/status/' . rawurlencode($trade_no),
            'status_code' => (int) ($order->status ?? 0),
            'status_text' => $this->statusLabel((int) ($order->status ?? 0)),
            'auto_redirect' => !empty($settings['auto_redirect']),
            'voice_enabled' => !empty($settings['voice_enabled']),
            'voice_text' => $this->renderTextTemplate($voiceTemplate, $templateVariables),
            'cashier_notice' => $this->renderNoticeHtml((string) ($settings['cashier_notice'] ?? ''), $templateVariables),
            'image_data_uri' => QrCodeService::hasDisplayableQr($order)
                ? QrCodeService::imageDataUriForOrder($order, 320)
                : '',
        ];

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            $this->renderCheckoutHtml($view)
        );
    }

    public function status(Request $request, string $trade_no): Response
    {
        return $this->execute(function () use ($trade_no) {
            $order = $this->normalizeCheckoutOrderState(OrderService::findByTradeNo($trade_no));
            $settings = MerchantChannelService::all((int) $order->merchant_id)['payment_settings'] ?? [];

            return $this->success([
                'trade_no' => (string) $order->trade_no,
                'out_trade_no' => (string) ($order->out_trade_no ?? ''),
                'subject' => (string) ($order->subject ?? ''),
                'method_name' => $this->methodName((string) ($order->channel_code ?? '')),
                'amount' => $this->normalizeAmountText($order->payable_amount ?? $order->amount ?? '0.00'),
                'status' => (int) $order->status,
                'status_text' => $this->statusLabel((int) $order->status),
                'pay_time' => (string) ($order->pay_time ?? ''),
                'created_at' => (string) ($order->created_at ?? ''),
                'expire_time' => (string) ($order->expire_time ?? ''),
                'return_url' => (string) ($order->return_url ?? ''),
                'callback_status' => (int) ($order->callback_status ?? 0),
                'auto_redirect' => !empty($settings['auto_redirect']),
            ]);
        });
    }

    public function mockComplete(Request $request, string $trade_no): Response
    {
        return $this->execute(function () use ($trade_no) {
            $settings = SettingsService::all(false);
            $payment = is_array($settings['payment'] ?? null) ? $settings['payment'] : [];

            if (
                !(bool) config('app.debug')
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
                'trade_no' => (string) $order->trade_no,
                'status' => (int) $order->status,
            ], '模拟完成');
        });
    }

    public function qrImage(Request $request, string $trade_no): Response
    {
        $size = (int) $request->get('size', 320);
        return QrCodeService::imageResponseByTradeNo($trade_no, $size);
    }

    private function renderCheckoutHtml(array $view): string
    {
        $template = $this->normalizeTemplateCode((string) ($view['template'] ?? ''));
        $tradeNoValue = (string) ($view['trade_no'] ?? '');
        $subject = $this->escapeHtml((string) ($view['subject'] ?? '支付订单'));
        $amount = $this->escapeHtml((string) ($view['amount'] ?? '0.00'));
        $tradeNo = $this->escapeHtml($tradeNoValue);
        $outTradeNo = $this->escapeHtml((string) ($view['out_trade_no'] ?? ''));
        $methodName = $this->escapeHtml((string) ($view['method_name'] ?? '-'));
        $createdAt = $this->escapeHtml((string) ($view['created_at'] ?? '-'));
        $payTime = $this->escapeHtml((string) ($view['pay_time'] ?? ''));
        $payTimeDisplay = $payTime !== '' ? $payTime : '-';
        $expireTime = $this->escapeHtml((string) ($view['expire_time'] ?? '-'));
        $statusCode = (int) ($view['status_code'] ?? 0);
        $statusText = $this->escapeHtml((string) ($view['status_text'] ?? $this->statusLabel($statusCode)));
        $imageDataUri = trim((string) ($view['image_data_uri'] ?? ''));
        $noticeHtml = trim((string) ($view['cashier_notice'] ?? ''));
        $returnUrl = $this->normalizeReturnUrl((string) ($view['return_url'] ?? ''), $tradeNoValue);

        $imageBlock = $imageDataUri !== ''
            ? '<div class="qr-box"><img src="' . $this->escapeHtml($imageDataUri) . '" alt="支付二维码"></div>'
            : '<div class="qr-box qr-box--empty">暂未生成支付二维码</div>';

        $noticeSection = $noticeHtml !== ''
            ? '<div class="notice-section"><div class="section-label">收银提醒</div><div class="notice-box">' . $noticeHtml . '</div></div>'
            : '';

        $maskAction = $returnUrl !== ''
            ? '<a class="action-link" href="' . $this->escapeHtml($returnUrl) . '">返回重新下单</a>'
            : '';
        $successAction = $returnUrl !== ''
            ? '<div class="success-actions"><a class="action-link action-link--success" href="' . $this->escapeHtml($returnUrl) . '">返回商户页面</a></div>'
            : '';

        $returnUrlJs = $this->toJsString($returnUrl);
        $statusUrlJs = $this->toJsString((string) ($view['status_url'] ?? ''));
        $voiceTextJs = $this->toJsString((string) ($view['voice_text'] ?? ''));
        $autoRedirectJs = !empty($view['auto_redirect']) ? 'true' : 'false';
        $voiceEnabledJs = !empty($view['voice_enabled']) ? 'true' : 'false';
        $initialStateJs = $this->toJsValue([
            'subject' => (string) ($view['subject'] ?? ''),
            'trade_no' => (string) ($view['trade_no'] ?? ''),
            'out_trade_no' => (string) ($view['out_trade_no'] ?? ''),
            'method_name' => (string) ($view['method_name'] ?? ''),
            'amount' => (string) ($view['amount'] ?? '0.00'),
            'pay_time' => (string) ($view['pay_time'] ?? ''),
            'created_at' => (string) ($view['created_at'] ?? ''),
            'expire_time' => (string) ($view['expire_time'] ?? ''),
            'return_url' => $returnUrl,
            'status' => $statusCode,
            'status_text' => (string) ($view['status_text'] ?? ''),
        ]);

        return <<<HTML
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>NexPay 收银台</title>
  <style>
    :root{
      --bg:#eef4ff;
      --panel:#ffffff;
      --panel-soft:#f7faff;
      --line:#d5e3f8;
      --text:#172033;
      --muted:#63758d;
      --accent:#1677ff;
      --accent-soft:#e8f1ff;
      --success:#00a76f;
      --danger:#e04f5f;
    }
    *{box-sizing:border-box}
    [hidden]{display:none !important}
    body{
      margin:0;
      font-family:"Segoe UI",PingFang SC,"Microsoft YaHei",sans-serif;
      background:linear-gradient(180deg,#f6f9ff 0%,var(--bg) 100%);
      color:var(--text);
    }
    .page{
      max-width:1080px;
      margin:0 auto;
      padding:28px 16px;
    }
    .shell{
      background:var(--panel);
      border:1px solid rgba(181,205,239,.9);
      border-radius:24px;
      padding:24px;
      box-shadow:0 18px 48px rgba(17,38,71,.08);
    }
    .badge{
      display:inline-flex;
      align-items:center;
      padding:8px 14px;
      border-radius:999px;
      background:var(--accent-soft);
      color:var(--accent);
      font-size:12px;
      font-weight:700;
    }
    .badge--success{
      background:rgba(0,167,111,.12);
      color:var(--success);
    }
    .title{
      margin:14px 0 0;
      font-size:28px;
      line-height:1.25;
      color:#14274d;
    }
    .title--success{
      margin-top:16px;
    }
    .layout{
      display:grid;
      grid-template-columns:1fr .96fr;
      gap:18px;
      margin-top:20px;
    }
    .panel{
      background:var(--panel-soft);
      border:1px solid var(--line);
      border-radius:20px;
      padding:20px;
    }
    .section-label{
      color:#4f6688;
      font-size:12px;
      letter-spacing:.04em;
    }
    .amount{
      margin-top:10px;
      font-size:36px;
      line-height:1.1;
      font-weight:800;
      color:#12264b;
    }
    .subtext{
      margin-top:14px;
      font-size:13px;
      color:var(--muted);
    }
    .qr-stage{
      position:relative;
      margin-top:14px;
    }
    .qr-box{
      min-height:320px;
      padding:18px;
      display:flex;
      align-items:center;
      justify-content:center;
      background:#fff;
      border:1px solid var(--line);
      border-radius:18px;
      overflow:hidden;
    }
    .qr-box img{
      display:block;
      width:100%;
      max-width:320px;
      border-radius:14px;
      border:1px solid #d9e6f7;
      background:#fff;
      transition:filter .18s ease, opacity .18s ease, transform .18s ease;
    }
    .qr-box--empty{
      color:var(--muted);
      font-size:14px;
      text-align:center;
    }
    .qr-stage.is-blocked .qr-box img{
      filter:blur(2px) saturate(.96) contrast(.95);
      opacity:.42;
      transform:scale(.992);
    }
    .qr-mask{
      position:absolute;
      inset:0;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:24px;
      border-radius:18px;
      overflow:hidden;
      background:
        radial-gradient(circle at 50% 50%, rgba(255,255,255,.24) 0%, rgba(239,245,255,.18) 42%, rgba(214,227,246,.14) 100%),
        rgba(221,232,248,.16);
      backdrop-filter:blur(8px) saturate(1.02);
      -webkit-backdrop-filter:blur(8px) saturate(1.02);
    }
    .qr-mask::before{
      content:"";
      position:absolute;
      inset:0;
      border-radius:inherit;
      background:
        linear-gradient(90deg,
          rgba(255,255,255,.14) 0 25%,
          rgba(233,240,251,.08) 25% 50%,
          rgba(255,255,255,.12) 50% 75%,
          rgba(225,236,249,.06) 75% 100%),
        linear-gradient(0deg,
          rgba(255,255,255,.10) 0 25%,
          rgba(216,228,245,.06) 25% 50%,
          rgba(255,255,255,.08) 50% 75%,
          rgba(206,221,242,.04) 75% 100%);
      background-size:34px 34px;
      opacity:.9;
      mix-blend-mode:screen;
      pointer-events:none;
    }
    .qr-mask::after{
      content:"";
      position:absolute;
      inset:0;
      border-radius:inherit;
      border:1px solid rgba(255,255,255,.22);
      box-shadow:inset 0 1px 0 rgba(255,255,255,.16);
      pointer-events:none;
    }
    .qr-mask__inner{
      max-width:300px;
      text-align:center;
      color:#fff;
      position:relative;
      z-index:1;
      padding:24px 22px;
      border-radius:18px;
      border:1px solid rgba(255,255,255,.18);
      background:rgba(98,118,158,.22);
      box-shadow:0 16px 32px rgba(90,114,158,.12);
      backdrop-filter:blur(3px);
      -webkit-backdrop-filter:blur(3px);
    }
    .qr-mask__title{
      display:block;
      font-size:20px;
      line-height:1.3;
      font-weight:800;
      text-shadow:0 1px 2px rgba(16,25,40,.18);
    }
    .qr-mask__copy{
      margin:10px 0 0;
      font-size:13px;
      line-height:1.8;
      color:rgba(255,255,255,.92);
    }
    .status{
      display:inline-flex;
      align-items:center;
      gap:10px;
      margin-top:12px;
      color:var(--accent);
      font-size:15px;
      font-weight:700;
    }
    .status-dot{
      width:11px;
      height:11px;
      border-radius:50%;
      background:var(--accent);
      flex:0 0 auto;
    }
    .meta-grid,
    .success-grid{
      display:grid;
      grid-template-columns:repeat(2,minmax(0,1fr));
      gap:12px;
      margin-top:18px;
    }
    .meta-item,
    .success-item{
      min-height:96px;
      padding:14px 16px;
      background:#fff;
      border:1px solid var(--line);
      border-radius:16px;
    }
    .meta-value,
    .success-item__value{
      margin-top:10px;
      font-family:Consolas,Monaco,"Microsoft YaHei",sans-serif;
      font-size:13px;
      line-height:1.7;
      word-break:break-all;
      color:#183052;
      font-weight:600;
    }
    .success-stage{
      padding:18px 4px 4px;
    }
    .success-copy{
      margin:12px 0 0;
      font-size:14px;
      line-height:1.8;
      color:var(--muted);
    }
    .success-item__label{
      color:#4f6688;
      font-size:12px;
      letter-spacing:.04em;
    }
    .notice-section{
      margin-top:18px;
    }
    .notice-box{
      margin-top:10px;
      padding:14px 16px;
      border-radius:16px;
      background:#fff;
      border:1px solid var(--line);
      color:#284564;
      line-height:1.8;
      word-break:break-word;
    }
    .notice-box p{margin:0 0 10px}
    .notice-box p:last-child{margin-bottom:0}
    .notice-box ul,.notice-box ol{margin:0;padding-left:20px}
    .action-link{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:42px;
      padding:0 18px;
      margin-top:16px;
      border-radius:12px;
      background:#fff;
      border:1px solid rgba(255,255,255,.18);
      color:#10294f;
      font-size:13px;
      font-weight:700;
      text-decoration:none;
    }
    .action-link--success{
      margin-top:0;
      border-color:var(--line);
      background:var(--accent);
      color:#fff;
    }
    .success-actions{
      display:flex;
      justify-content:flex-start;
      margin-top:20px;
    }
    @media (max-width: 960px){
      .page{padding:16px 12px}
      .shell{padding:18px;border-radius:20px}
      .title{font-size:24px}
      .layout{grid-template-columns:1fr}
      .panel{padding:18px}
      .amount{font-size:32px}
      .meta-grid,
      .success-grid{grid-template-columns:1fr}
      .qr-box{min-height:auto}
    }
  </style>
</head>
<body data-checkout-template="{$template}">
  <main class="page">
    <section class="shell">
      <div id="checkoutStage">
        <span class="badge">NexPay 收银台</span>
        <h1 class="title">{$subject}</h1>
        <div class="layout">
          <section class="panel">
            <div class="section-label">支付金额</div>
            <div class="amount">{$amount}</div>
            <div class="subtext">请使用对应支付方式完成扫码付款</div>
            <div id="qrStage" class="qr-stage">
              {$imageBlock}
              <div id="qrMask" class="qr-mask" hidden>
                <div class="qr-mask__inner">
                  <strong id="qrMaskTitle" class="qr-mask__title">订单已超时</strong>
                  <p id="qrMaskCopy" class="qr-mask__copy">当前订单已超时，请重新下单。</p>
                  {$maskAction}
                </div>
              </div>
            </div>
          </section>
          <section class="panel">
            <div class="section-label">订单状态</div>
            <div id="status" class="status"><span class="status-dot"></span>{$statusText}</div>
            <div class="meta-grid">
              <div class="meta-item">
                <div class="section-label">平台订单号</div>
                <div class="meta-value">{$tradeNo}</div>
              </div>
              <div class="meta-item">
                <div class="section-label">商户订单号</div>
                <div class="meta-value">{$outTradeNo}</div>
              </div>
              <div class="meta-item">
                <div class="section-label">支付方式</div>
                <div class="meta-value">{$methodName}</div>
              </div>
              <div class="meta-item">
                <div class="section-label">订单创建时间</div>
                <div class="meta-value">{$createdAt}</div>
              </div>
              <div class="meta-item">
                <div class="section-label">过期时间</div>
                <div class="meta-value">{$expireTime}</div>
              </div>
              <div class="meta-item">
                <div class="section-label">当前金额</div>
                <div class="meta-value">{$amount}</div>
              </div>
            </div>
            {$noticeSection}
          </section>
        </div>
      </div>

      <section id="successStage" class="success-stage" hidden>
        <span class="badge badge--success">NexPay 支付结果</span>
        <h1 class="title title--success">订单已支付成功</h1>
        <p class="success-copy">支付已完成，以下为本次订单信息。</p>
        <div class="success-grid">
          <div class="success-item">
            <div class="success-item__label">商品名称</div>
            <div id="successSubject" class="success-item__value">{$subject}</div>
          </div>
          <div class="success-item">
            <div class="success-item__label">订单号</div>
            <div id="successTradeNo" class="success-item__value">{$tradeNo}</div>
          </div>
          <div class="success-item">
            <div class="success-item__label">支付方式</div>
            <div id="successMethodName" class="success-item__value">{$methodName}</div>
          </div>
          <div class="success-item">
            <div class="success-item__label">金额</div>
            <div id="successAmount" class="success-item__value">{$amount}</div>
          </div>
          <div class="success-item">
            <div class="success-item__label">支付时间</div>
            <div id="successPayTime" class="success-item__value">{$payTimeDisplay}</div>
          </div>
          <div class="success-item">
            <div class="success-item__label">订单创建时间</div>
            <div id="successCreatedAt" class="success-item__value">{$createdAt}</div>
          </div>
        </div>
        {$successAction}
      </section>
    </section>
  </main>
  <script>
    const returnUrl = {$returnUrlJs};
    const statusUrl = {$statusUrlJs};
    const autoRedirect = {$autoRedirectJs};
    const voiceEnabled = {$voiceEnabledJs};
    const voiceText = {$voiceTextJs};
    const initialState = {$initialStateJs};

    let redirected = false;
    let voicePlayed = false;
    let paidDetected = Number(initialState.status || 0) === 1;
    let pollTimer = 0;

    const checkoutStageEl = document.getElementById('checkoutStage');
    const successStageEl = document.getElementById('successStage');
    const statusEl = document.getElementById('status');
    const qrStageEl = document.getElementById('qrStage');
    const qrMaskEl = document.getElementById('qrMask');
    const qrMaskTitleEl = document.getElementById('qrMaskTitle');
    const qrMaskCopyEl = document.getElementById('qrMaskCopy');
    const successSubjectEl = document.getElementById('successSubject');
    const successTradeNoEl = document.getElementById('successTradeNo');
    const successMethodNameEl = document.getElementById('successMethodName');
    const successAmountEl = document.getElementById('successAmount');
    const successPayTimeEl = document.getElementById('successPayTime');
    const successCreatedAtEl = document.getElementById('successCreatedAt');

    function stopPolling() {
      if (pollTimer) {
        window.clearInterval(pollTimer);
        pollTimer = 0;
      }
    }

    function setStatus(text, statusCode) {
      let tone = 'var(--accent)';
      if (Number(statusCode) === 1) {
        tone = 'var(--success)';
      } else if (Number(statusCode) === 2 || Number(statusCode) === 3 || Number(statusCode) === 4) {
        tone = 'var(--danger)';
      }

      statusEl.innerHTML = '<span class="status-dot"></span>' + text;
      statusEl.style.color = tone;
      const dot = statusEl.querySelector('.status-dot');
      if (dot) {
        dot.style.background = tone;
      }
    }

    function playSuccessVoice() {
      if (!voiceEnabled || !voiceText || voicePlayed || !('speechSynthesis' in window)) {
        return;
      }

      try {
        window.speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(voiceText);
        utterance.lang = 'zh-CN';
        utterance.rate = 1;
        utterance.pitch = 1;
        utterance.volume = 1;
        voicePlayed = true;
        window.speechSynthesis.speak(utterance);
      } catch (error) {
        console.warn('voice playback failed', error);
      }
    }

    function scheduleRedirect() {
      if (!autoRedirect || !returnUrl || redirected) {
        return;
      }

      redirected = true;
      const delay = voiceEnabled && voiceText ? 5200 : 4200;
      window.setTimeout(() => {
        window.location.href = returnUrl;
      }, delay);
    }

    function setQrMaskState(statusCode) {
      const code = Number(statusCode || 0);
      if (code !== 2 && code !== 3 && code !== 4) {
        qrStageEl.classList.remove('is-blocked');
        qrMaskEl.hidden = true;
        return;
      }

      let title = '订单已超时';
      let copy = '当前订单已超时，请重新下单。';
      if (code === 2) {
        title = '支付未完成';
        copy = '当前订单支付失败，请重新下单。';
      } else if (code === 4) {
        title = '订单已关闭';
        copy = '当前订单已关闭，请重新下单。';
      }

      qrMaskTitleEl.textContent = title;
      qrMaskCopyEl.textContent = copy;
      qrStageEl.classList.add('is-blocked');
      qrMaskEl.hidden = false;
    }

    function showSuccessStage(data) {
      const state = data || initialState;
      checkoutStageEl.hidden = true;
      successStageEl.hidden = false;
      successSubjectEl.textContent = state.subject || initialState.subject || '-';
      successTradeNoEl.textContent = state.trade_no || initialState.trade_no || '-';
      successMethodNameEl.textContent = state.method_name || initialState.method_name || '-';
      successAmountEl.textContent = state.amount || initialState.amount || '0.00';
      successPayTimeEl.textContent = state.pay_time || '-';
      successCreatedAtEl.textContent = state.created_at || initialState.created_at || '-';
      document.title = '支付成功 - NexPay 收银台';
    }

    function applyState(data) {
      if (!data || typeof data !== 'object') {
        return;
      }

      const statusCode = Number(data.status || 0);
      const statusText = data.status_text || '等待支付';

      paidDetected = paidDetected || statusCode === 1;
      setStatus(statusText, statusCode);

      if (statusCode === 1) {
        setQrMaskState(0);
        playSuccessVoice();
        showSuccessStage(data);
        stopPolling();
        scheduleRedirect();
        return;
      }

      setQrMaskState(statusCode);
      if (statusCode === 2 || statusCode === 3 || statusCode === 4) {
        stopPolling();
      }
    }

    function tryPlayPendingVoice() {
      if (paidDetected) {
        playSuccessVoice();
      }
    }

    async function poll() {
      try {
        const resp = await fetch(statusUrl, { cache: 'no-store' });
        const json = await resp.json();
        if (!json || !json.data || !json.data.status_text) {
          return;
        }

        applyState(json.data);
      } catch (error) {
        console.warn('checkout status poll failed', error);
      }
    }

    if ('speechSynthesis' in window) {
      window.speechSynthesis.getVoices();
    }

    document.addEventListener('click', tryPlayPendingVoice);
    document.addEventListener('keydown', tryPlayPendingVoice);
    document.addEventListener('touchstart', tryPlayPendingVoice, { passive: true });

    applyState(initialState);

    if (![1, 2, 3, 4].includes(Number(initialState.status || 0))) {
      poll();
      pollTimer = window.setInterval(poll, 3000);
    }
  </script>
</body>
</html>
HTML;
    }

    private function normalizeCheckoutOrderState(object $order): object
    {
        $order = OrderService::syncHomepageTestOrder($order);
        $order = $this->bootstrapPendingQrSource($order);
        if (
            (int) ($order->status ?? 0) === OrderService::STATUS_PENDING
            && $this->isOrderExpired($order)
        ) {
            $order = OrderService::saveOrder($order, ['status' => OrderService::STATUS_EXPIRED]);
        }

        return $order;
    }

    private function bootstrapPendingQrSource(object $order): object
    {
        if ((int)($order->status ?? 0) !== OrderService::STATUS_PENDING) {
            return $order;
        }

        if (QrCodeService::hasDisplayableQr($order)) {
            return $order;
        }

        try {
            $result = LegacyPaymentGatewayService::run((string)($order->trade_no ?? ''), 'submit', request());
            $resultType = strtolower(trim((string)($result['type'] ?? '')));
            if (in_array($resultType, ['qrcode', 'jump', 'redirect', 'return', 'scheme'], true)) {
                $source = trim(QrCodeService::extractGatewaySource($result));
                if ($source !== '') {
                    QrCodeService::rememberOrderSource(
                        (string)($order->trade_no ?? ''),
                        $source,
                        array_filter([
                            'type' => $resultType,
                            'page' => trim((string)($result['page'] ?? '')),
                        ], static fn(mixed $value): bool => is_string($value) && $value !== '')
                    );
                }
            }
        } catch (\Throwable) {
            return $order;
        }

        return OrderService::findByTradeNo((string)($order->trade_no ?? ''));
    }

    private function checkoutTemplateVariables(object $order): array
    {
        $subject = trim((string) ($order->subject ?? ''));

        return [
            '{{platform_order_no}}' => (string) ($order->trade_no ?? ''),
            '{{merchant_order_no}}' => (string) ($order->out_trade_no ?? ''),
            '{{product_name}}' => $subject !== '' ? $subject : '支付订单',
            '{{paid_amount}}' => $this->normalizeAmountText($order->payable_amount ?? $order->amount ?? '0.00'),
            '{{order_amount}}' => $this->normalizeAmountText($order->amount ?? $order->payable_amount ?? '0.00'),
            '{{payment_method}}' => $this->methodName((string) ($order->channel_code ?? '')),
        ];
    }

    private function renderTextTemplate(string $template, array $variables): string
    {
        $template = (string)EncodingRepairService::repair($template);
        $normalized = strtr($template, self::TEMPLATE_TOKEN_ALIAS_MAP);
        return trim(strtr($normalized, $variables));
    }

    private function renderNoticeHtml(string $template, array $variables): string
    {
        $rendered = trim($this->renderTextTemplate($template, $variables));
        if ($rendered === '') {
            return '';
        }

        $sanitized = preg_replace(
            '#<(script|style|iframe|object|embed|form|input|button|textarea|select)[^>]*>.*?</\1>#is',
            '',
            $rendered
        );
        $sanitized = is_string($sanitized) ? $sanitized : $rendered;
        $sanitized = strip_tags($sanitized, '<br><p><div><span><strong><b><em><i><u><small><ul><ol><li><code>');

        $patterns = [
            '/\s+on[a-z]+\s*=\s*(["\']).*?\1/iu',
            '/\s+on[a-z]+\s*=\s*[^\s>]+/iu',
            '/\s+style\s*=\s*(["\']).*?\1/iu',
            '/\s+style\s*=\s*[^\s>]+/iu',
        ];

        foreach ($patterns as $pattern) {
            $replaced = preg_replace($pattern, '', $sanitized);
            $sanitized = is_string($replaced) ? $replaced : $sanitized;
        }

        $sanitized = str_ireplace(['javascript:', 'vbscript:', 'data:text/html'], '', $sanitized);
        return trim($sanitized);
    }

    private function normalizeTemplateCode(string $template): string
    {
        $normalized = trim($template);
        if ($normalized === '') {
            return 'nexpay-standard';
        }

        return self::TEMPLATE_ALIAS_MAP[$normalized] ?? 'nexpay-standard';
    }

    private function normalizeReturnUrl(string $returnUrl, string $tradeNo): string
    {
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '' || $tradeNo === '') {
            return $returnUrl;
        }

        $checkoutPath = '/pay/checkout/' . rawurlencode($tradeNo);
        $normalizedCheckoutPath = rtrim($checkoutPath, '/');
        $normalizedReturnUrl = rtrim($returnUrl, '/');
        if ($normalizedReturnUrl === $normalizedCheckoutPath) {
            return '';
        }

        $returnPath = (string) (parse_url($returnUrl, PHP_URL_PATH) ?? '');
        if ($returnPath !== '' && rtrim($returnPath, '/') === $normalizedCheckoutPath) {
            return '';
        }

        return $returnUrl;
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            OrderService::STATUS_SUCCESS => '支付成功',
            OrderService::STATUS_FAILED => '支付失败',
            OrderService::STATUS_EXPIRED => '订单已过期',
            OrderService::STATUS_CLOSED => '订单已关闭',
            default => '等待支付',
        };
    }

    private function methodName(string $channelCode): string
    {
        return match (PaymentMetaService::normalizeMethodCode($channelCode)) {
            'wxpay' => '微信支付',
            'alipay' => '支付宝',
            'qqpay' => 'QQ钱包',
            'bank' => '银行卡 / 云闪付',
            'jdpay' => '京东支付',
            'paypal' => 'PayPal',
            'douyinpay' => '抖音支付',
            'usdtaptos' => 'USDT-Aptos',
            'usdtpolygon' => 'USDT-Polygon',
            'usdttrc20' => 'USDT-TRC20',
            'trx' => 'TRX',
            'erc20' => 'USDT-ERC20',
            'bsc' => 'USDT-BSC',
            'avaxc' => 'USDT-AVAXC',
            default => strtoupper(PaymentMetaService::normalizeMethodCode($channelCode)),
        };
    }

    private function normalizeAmountText(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function isOrderExpired(object $order): bool
    {
        $expireTime = trim((string) ($order->expire_time ?? ''));
        if ($expireTime === '') {
            return false;
        }

        $timestamp = strtotime($expireTime);
        return $timestamp !== false && $timestamp <= time();
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function toJsString(string $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?: '""';
    }

    private function toJsValue(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?: 'null';
    }
}
