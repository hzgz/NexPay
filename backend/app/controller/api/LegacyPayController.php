<?php

declare(strict_types=1);

namespace app\controller\api;

use app\service\payment\LegacyPaymentGatewayService;
use support\Request;

class LegacyPayController
{
    public function submit(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'submit', $request);
    }

    public function qrcode(Request $request, string $trade_no)
    {
        $type = trim((string)$request->get('type', ''));
        return LegacyPaymentGatewayService::execute($trade_no, 'mapi', $request, $type);
    }

    public function submitWap(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'submit', $request);
    }

    public function jsPay(Request $request, string $trade_no)
    {
        $type = trim((string)$request->get('type', ''));
        return LegacyPaymentGatewayService::execute($trade_no, 'mapi', $request, $type !== '' ? $type : 'wxpay');
    }

    public function pay(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'submit', $request);
    }

    public function alipay(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'alipay', $request, 'alipay');
    }

    public function wxpay(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'wxpay', $request, 'wxpay');
    }

    public function qqpay(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'qqpay', $request, 'qqpay');
    }

    public function bank(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'bank', $request, 'bank');
    }

    public function jdpay(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'jdpay', $request, 'jdpay');
    }

    public function douyinpay(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'douyinpay', $request, 'douyinpay');
    }

    public function notify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'notify', $request);
    }

    public function refundNotify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::executeSoft($trade_no, 'refundnotify', $request);
    }

    public function transferNotify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::executeChannelSoft($trade_no, 'transfernotify', $request);
    }

    public function preauthNotify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::executeSoft($trade_no, 'preauthnotify', $request);
    }

    public function complainNotify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::executeSoft($trade_no, 'complainnotify', $request);
    }

    public function divideNotify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::executeSoft($trade_no, 'dividenotify', $request);
    }

    public function cashierNotify(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::executeSoft($trade_no, 'cashiernotify', $request);
    }

    public function legacyReturn(Request $request, string $trade_no)
    {
        return LegacyPaymentGatewayService::execute($trade_no, 'return', $request);
    }

    public function ok(Request $request, string $trade_no)
    {
        return redirect('/pay/checkout/' . rawurlencode($trade_no));
    }
}
