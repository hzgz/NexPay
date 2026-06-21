<?php

declare(strict_types=1);

namespace plugins\payment\stripe;

use Exception;

class Webhook
{
    const DEFAULT_TOLERANCE = 300;

    const EXPECTED_SCHEME = 'v1';

    public static function constructEvent(string $payload, string $sigHeader, string $secret, int $tolerance = self::DEFAULT_TOLERANCE): array
    {
        if (!self::verifyHeader($payload, $sigHeader, $secret, $tolerance)) {
            throw new Exception('Invalid signature');
        }

        $data = json_decode($payload, true);
        if (!$data) {
            throw new Exception('Invalid payload');
        }

        return $data;
    }

    public static function verifyHeader(string $payload, string $header, string $secret, ?int $tolerance = null): bool
    {
        // Extract timestamp and signatures from header
        $timestamp = self::getTimestamp($header);
        $signatures = self::getSignatures($header, self::EXPECTED_SCHEME);
        if (!$timestamp || empty($signatures)) {
            return false;
        }

        // Check if expected signature is found in list of signatures from
        // header
        $signedPayload = "{$timestamp}.{$payload}";
        $expectedSignature = self::computeSignature($signedPayload, $secret);
        $signatureFound = false;
        foreach ($signatures as $signature) {
            if ($expectedSignature === $signature) {
                $signatureFound = true;
                break;
            }
        }
        if (!$signatureFound) {
            return false;
        }

        // Check if timestamp is within tolerance
        if (($tolerance > 0) && (abs(time() - $timestamp) > $tolerance)) {
            return false;
        }

        return true;
    }

    private static function getTimestamp(string $header): int|false
    {
        $items = explode(',', $header);

        foreach ($items as $item) {
            $itemParts = explode('=', $item, 2);
            if ('t' === $itemParts[0]) {
                if (!is_numeric($itemParts[1])) {
                    return false;
                }

                return (int) ($itemParts[1]);
            }
        }

        return false;
    }

    private static function getSignatures(string $header, string $scheme): array
    {
        $signatures = [];
        $items = explode(',', $header);

        foreach ($items as $item) {
            $itemParts = explode('=', $item, 2);
            if (trim($itemParts[0]) === $scheme) {
                $signatures[] = $itemParts[1];
            }
        }

        return $signatures;
    }

    private static function computeSignature(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
