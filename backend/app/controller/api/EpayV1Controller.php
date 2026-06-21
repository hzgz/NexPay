<?php

namespace app\controller\api;

use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\GatewayCompatService;
use support\Request;
use support\Response;
use Throwable;

class EpayV1Controller
{
    public function submit(Request $request)
    {
        try {
            $result = GatewayCompatService::createForV1(array_merge($request->get(), $request->post()));
            return redirect(LegacyPaymentGatewayService::entryUrl((string)$result['trade_no'], 'submit'));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function submit2(Request $request)
    {
        try {
            $result = GatewayCompatService::createForV1Fallback(array_merge($request->get(), $request->post()));
            return redirect(LegacyPaymentGatewayService::entryUrl((string)$result['trade_no'], 'submit', ['retry' => 1]));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function create(Request $request)
    {
        try {
            $payload = array_merge($request->get(), $request->post());
            $result = GatewayCompatService::createForV1($payload);
            $method = trim((string)($payload['type'] ?? ''));
            $legacy = LegacyPaymentGatewayService::run((string)$result['trade_no'], 'mapi', $request, $method);
            return $this->jsonResponse($this->formatMapiResponse((string)$result['trade_no'], $legacy));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function query(Request $request)
    {
        try {
            return $this->jsonResponse(GatewayCompatService::queryForV1(array_merge($request->get(), $request->post())));
        } catch (Throwable $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function errorResponse(Throwable $exception): Response
    {
        return $this->jsonResponse([
            'code' => -1,
            'msg' => $exception->getMessage(),
        ]);
    }

    private function jsonResponse(array $payload): Response
    {
        return json($payload)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private function formatMapiResponse(string $tradeNo, array $legacy): array
    {
        $type = strtolower(trim((string)($legacy['type'] ?? '')));
        $response = [
            'code' => 1,
            'msg' => 'success',
            'trade_no' => $tradeNo,
        ];

        return match ($type) {
            'jump', 'redirect', 'return' => $response + [
                'payurl' => (string)($legacy['url'] ?? LegacyPaymentGatewayService::entryUrl($tradeNo, 'submit')),
            ],
            'qrcode' => $response + [
                'qrcode' => (string)($legacy['url'] ?? ''),
            ],
            'scheme' => $response + [
                'urlscheme' => (string)($legacy['url'] ?? ''),
            ],
            'html' => $response + [
                'html' => (string)($legacy['data'] ?? ''),
            ],
            default => [
                'code' => -2,
                'msg' => (string)($legacy['msg'] ?? '支付插件执行失败'),
                'trade_no' => $tradeNo,
            ],
        };
    }
}
