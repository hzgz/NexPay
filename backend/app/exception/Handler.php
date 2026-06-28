<?php

namespace app\exception;

use app\service\system\EncodingRepairService;
use support\exception\Handler as BaseHandler;
use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;

class Handler extends BaseHandler
{
    public $dontReport = [
        BusinessException::class,
        SecurityException::class,
    ];

    public function render(Request $request, Throwable $exception): Response
    {
        if ($exception instanceof BusinessException) {
            return $this->renderBusinessException($request, $exception);
        }

        return parent::render($request, $exception);
    }

    private function renderBusinessException(Request $request, BusinessException $exception): Response
    {
        $message = (string)EncodingRepairService::repair($exception->getMessage());
        $payload = EncodingRepairService::repair($exception->payload());
        $httpStatus = $exception instanceof SecurityException ? $exception->httpStatus() : 200;

        if ($this->shouldRenderJson($request)) {
            $body = json_encode([
                'code' => $exception->errorCode(),
                'message' => $message,
                'data' => $payload,
                'timestamp' => time(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return new Response(
                $httpStatus,
                ['Content-Type' => 'application/json; charset=utf-8'],
                $body === false ? '{"code":1000,"message":"系统异常","data":[],"timestamp":0}' : $body
            );
        }

        return new Response(
            $exception instanceof SecurityException ? $httpStatus : 400,
            ['Content-Type' => 'text/plain; charset=utf-8'],
            $message
        );
    }

    private function shouldRenderJson(Request $request): bool
    {
        if ($request->expectsJson()) {
            return true;
        }

        $path = '/' . ltrim((string)$request->path(), '/');
        if (str_starts_with($path, '/api/')) {
            return true;
        }

        $accept = strtolower((string)$request->header('accept', ''));
        $contentType = strtolower((string)$request->header('content-type', ''));
        $requestedWith = strtolower((string)$request->header('x-requested-with', ''));

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || $requestedWith === 'xmlhttprequest';
    }
}
