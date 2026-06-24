<?php

declare(strict_types=1);

namespace app\controller\api;

use support\Request;
use support\Response;

class AlipayBridgeController
{
    public function show(Request $request): Response
    {
        $userId = trim((string)$request->get('user_id', ''));
        $price = trim((string)$request->get('price', ''));
        $tradeNo = trim((string)$request->get('trade_no', ''));

        if ($userId === '') {
            return new Response(400, ['Content-Type' => 'text/plain; charset=utf-8'], 'missing user_id');
        }

        $bizData = [
            's' => 'money',
            'u' => $userId,
        ];
        if ($price !== '') {
            $bizData['a'] = $price;
        }
        if ($tradeNo !== '') {
            $bizData['m'] = $tradeNo;
        }

        $innerUrl = 'alipays://platformapi/startapp?appId=20000123&actionType=scan&biz_data='
            . rawurlencode(json_encode($bizData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $alipayUrl = 'alipayqr://platformapi/startapp?saId=20000032&url=' . urlencode($innerUrl);

        $title = '正在唤起支付宝';
        $desc = '如未自动跳转，请点击下方按钮继续。';

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>body{margin:0;font-family:\"Segoe UI\",\"Microsoft YaHei\",sans-serif;background:#f5f7fb;color:#172033}.wrap{max-width:520px;margin:12vh auto 0;padding:28px 24px;text-align:center;background:#fff;border-radius:18px;box-shadow:0 16px 40px rgba(15,23,42,.08);border:1px solid #dbe7f6}.title{font-size:22px;font-weight:700;margin-bottom:10px}.desc{font-size:14px;line-height:1.8;color:#63758d;margin-bottom:18px}.btn{display:inline-flex;align-items:center;justify-content:center;min-width:156px;height:42px;padding:0 18px;border-radius:12px;background:#1677ff;color:#fff;text-decoration:none;font-weight:600}</style></head><body>'
            . '<div class=\"wrap\"><div class=\"title\">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</div><div class=\"desc\">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<a class=\"btn\" href=\"' . htmlspecialchars($alipayUrl, ENT_QUOTES, 'UTF-8') . '\">立即打开支付宝</a></div>'
            . '<script>(function(){var ua=navigator.userAgent||\"\";var isMobile=/(phone|pad|pod|iPhone|iPod|ios|iPad|Android|Mobile|BlackBerry|IEMobile|Windows Phone)/i.test(ua);if(isMobile){window.location.href=' . json_encode($alipayUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';}})();</script>'
            . '</body></html>';

        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
