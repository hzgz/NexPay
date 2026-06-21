<?php

declare(strict_types=1);

chdir(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backend');
require 'vendor/autoload.php';
require_once 'app/functions.php';

use app\service\payment\LegacyPaymentGatewayService;
use app\service\payment\PluginNotifyLogService;
use app\service\system\JsonStoreService;
use support\Request;

$backup = JsonStoreService::load('plugin_notify_logs', []);
$checks = [];
$details = [];

try {
    JsonStoreService::save('plugin_notify_logs', []);

    $invoke = new ReflectionMethod(LegacyPaymentGatewayService::class, 'strictNotifyFailureResponse');
    $invoke->setAccessible(true);

    $refundResponse = $invoke->invoke(
        null,
        'refundnotify',
        buildRequest('/pay/refundnotify/STRICT-REFUND', [
            'refund_no' => 'STRICT-REFUND',
            'trade_status' => 'TRADE_SUCCESS',
        ]),
        new RuntimeException('Payment plugin action is not implemented: refundnotify'),
        ['trade_no' => 'STRICT-REFUND']
    );

    $transferResponse = $invoke->invoke(
        null,
        'transfernotify',
        buildRequest('/pay/transfernotify/88', [
            'out_biz_no' => 'STRICT-TRANSFER',
            'trade_status' => 'TRADE_SUCCESS',
        ]),
        new RuntimeException('Channel not found: 88'),
        ['channel_id' => 88]
    );

    $logs = PluginNotifyLogService::logs(10);
    $refundLog = firstLogForAction($logs, 'refundnotify');
    $transferLog = firstLogForAction($logs, 'transfernotify');

    $checks = [
        'refund_body_fail' => $refundResponse->rawBody() === 'fail',
        'refund_status_200' => $refundResponse->getStatusCode() === 200,
        'refund_plain_text' => str_contains(strtolower((string)($refundResponse->getHeaders()['Content-Type'] ?? '')), 'text/plain'),
        'refund_log_failed' => (string)($refundLog['status'] ?? '') === 'failed',
        'refund_strict_flag' => ($refundLog['context']['strict_notify'] ?? false) === true,
        'refund_fallback_disabled' => ($refundLog['context']['fallback_disabled'] ?? false) === true,
        'refund_reason_action_missing' => (string)($refundLog['context']['failure_reason'] ?? '') === 'plugin_action_missing',
        'refund_trade_logged' => (string)($refundLog['trade_no'] ?? '') === 'STRICT-REFUND',
        'transfer_body_fail' => $transferResponse->rawBody() === 'fail',
        'transfer_status_200' => $transferResponse->getStatusCode() === 200,
        'transfer_log_failed' => (string)($transferLog['status'] ?? '') === 'failed',
        'transfer_strict_flag' => ($transferLog['context']['strict_notify'] ?? false) === true,
        'transfer_fallback_disabled' => ($transferLog['context']['fallback_disabled'] ?? false) === true,
        'transfer_reason_runtime_exception' => (string)($transferLog['context']['failure_reason'] ?? '') === 'runtime_exception',
        'transfer_channel_logged' => (int)($transferLog['channel_id'] ?? 0) === 88,
    ];

    $details = [
        'refund_response' => [
            'status' => $refundResponse->getStatusCode(),
            'body' => $refundResponse->rawBody(),
            'headers' => $refundResponse->getHeaders(),
        ],
        'transfer_response' => [
            'status' => $transferResponse->getStatusCode(),
            'body' => $transferResponse->rawBody(),
            'headers' => $transferResponse->getHeaders(),
        ],
        'refund_log' => $refundLog,
        'transfer_log' => $transferLog,
    ];
} finally {
    JsonStoreService::save('plugin_notify_logs', $backup);
}

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
$result = [
    'ok' => $failed === [],
    'checks' => $checks,
    'failed' => $failed,
    'details' => $details,
];

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);

function buildRequest(string $path, array $form = []): Request
{
    $body = http_build_query($form);
    $buffer = implode("\r\n", [
        'POST ' . $path . ' HTTP/1.1',
        'Host: localhost',
        'Content-Type: application/x-www-form-urlencoded',
        'Content-Length: ' . strlen($body),
        '',
        $body,
    ]);

    return new Request($buffer);
}

function firstLogForAction(array $logs, string $action): array
{
    foreach ($logs as $log) {
        if ((string)($log['action'] ?? '') === $action) {
            return $log;
        }
    }

    return [];
}
