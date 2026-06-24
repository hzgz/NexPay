<?php

namespace app\controller;

use app\constant\StatusCode;
use app\exception\BusinessException;
use app\service\auth\TokenService;
use app\service\system\EncodingRepairService;
use support\Log;
use support\Request;
use support\Response;
use Throwable;

class BaseApiController
{
    protected function success(array $data = [], string $message = '操作成功', int $code = StatusCode::SUCCESS): Response
    {
        return json([
            'code' => $code,
            'message' => (string)EncodingRepairService::repair($message),
            'data' => EncodingRepairService::repair($data),
            'timestamp' => time(),
        ])->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    protected function fail(string $message, int $code = StatusCode::BUSINESS_ERROR, array $data = [], int $httpCode = 200): Response
    {
        return json([
            'code' => $code,
            'message' => (string)EncodingRepairService::repair($message),
            'data' => EncodingRepairService::repair($data),
            'timestamp' => time(),
        ], $httpCode)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    protected function execute(callable $callback): Response
    {
        try {
            return $callback();
        } catch (BusinessException $exception) {
            return $this->fail($exception->getMessage(), $exception->errorCode(), $exception->payload());
        } catch (Throwable $exception) {
            Log::error('API request failed', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            return $this->fail($exception->getMessage(), StatusCode::BUSINESS_ERROR);
        }
    }

    protected function requireGuard(Request $request, string $guard): array
    {
        $token = trim(str_replace('Bearer ', '', (string)$request->header('Authorization')));
        if ($token === '') {
            throw new BusinessException('未提供登录令牌', StatusCode::UNAUTHORIZED);
        }

        $payload = TokenService::decode($token);
        if (!$payload || ($payload['guard'] ?? '') !== $guard) {
            throw new BusinessException('登录状态已失效', StatusCode::UNAUTHORIZED);
        }

        return $payload;
    }

    protected function payload(Request $request): array
    {
        $payload = $request->all();
        if (!is_array($payload)) {
            $payload = [];
        }

        $rawBody = trim((string)$request->rawBody());
        if ($rawBody === '') {
            return $payload;
        }

        $contentType = strtolower((string)$request->header('content-type', ''));
        $firstChar = $rawBody[0] ?? '';
        if (!str_contains($contentType, 'json') && $firstChar !== '{' && $firstChar !== '[') {
            return $payload;
        }

        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = array_replace($payload, $decoded);
        }

        return $payload;
    }
}
