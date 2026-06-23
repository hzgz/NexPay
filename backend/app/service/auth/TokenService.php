<?php

namespace app\service\auth;

class TokenService
{
    public static function issue(string $guard, int $subjectId, array $claims = []): string
    {
        $payload = array_merge($claims, [
            'guard' => $guard,
            'sub' => $subjectId,
            'iat' => time(),
            'exp' => time() + 86400,
        ]);

        $body = self::base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_UNICODE));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $body, self::secret(), true));

        return $body . '.' . $signature;
    }

    public static function decode(string $token): ?array
    {
        [$body, $signature] = array_pad(explode('.', $token, 2), 2, null);
        if (!$body || !$signature) {
            return null;
        }

        $expected = self::base64UrlEncode(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    private static function secret(): string
    {
        return (string)env('TOKEN_SECRET', 'epay-plus-secret');
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
