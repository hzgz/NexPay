<?php

namespace app\service\system;

class EncodingRepairService
{
    private const CHINESE_CHAR_PATTERN = '/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u';

    private const EXACT_REPLACEMENTS = [
        '鍚?' => '否',
        '鏄?' => '是',
        '姝ｅ紡鐜' => '正式环境',
        '娌欑鐜' => '沙箱环境',
        '鐢熶骇鐜' => '生产环境',
        '娴嬭瘯鐜' => '测试环境',
        '妯℃嫙鐩戝惉' => '模拟监听',
        '鍗曞湴鍧€鏀舵' => '单地址收款',
        'APP 鎷夎捣' => 'APP 拉起',
        '浜岀淮鐮?' => '二维码',
        '鎵嬫満/H5' => '手机/H5',
        'PC/缃戦〉' => 'PC/网页',
        '鏀粯妯″紡' => '支付模式',
        '鏀舵鍦板潃' => '收款地址',
    ];

    public static function repair(mixed $value): mixed
    {
        if (is_array($value)) {
            $repaired = [];
            foreach ($value as $key => $item) {
                $repaired[$key] = self::repair($item);
            }

            return $repaired;
        }

        if (!is_string($value) || $value === '') {
            return $value;
        }

        $trimmed = trim($value);
        if (isset(self::EXACT_REPLACEMENTS[$trimmed])) {
            return self::EXACT_REPLACEMENTS[$trimmed];
        }

        $candidate = self::reinterpretUtf8ChineseBytes($value);
        if ($candidate !== null) {
            return $candidate;
        }

        if (preg_match(self::CHINESE_CHAR_PATTERN, $value) === 1) {
            return $value;
        }

        if (preg_match('/[脙脗芒忙莽猫茅氓盲枚眉茂录]/u', $value) !== 1) {
            return $value;
        }

        foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
            $bytes = @mb_convert_encoding($value, $encoding, 'UTF-8');
            if (!is_string($bytes) || $bytes === '' || !mb_check_encoding($bytes, 'UTF-8')) {
                continue;
            }

            if (preg_match(self::CHINESE_CHAR_PATTERN, $bytes) === 1) {
                return $bytes;
            }
        }

        return $value;
    }

    private static function reinterpretUtf8ChineseBytes(string $value): ?string
    {
        foreach (['CP936', 'GB18030'] as $encoding) {
            $candidate = @iconv('UTF-8', $encoding . '//IGNORE', $value);
            if (!is_string($candidate) || $candidate === '' || !mb_check_encoding($candidate, 'UTF-8')) {
                continue;
            }

            $candidate = trim($candidate);
            if (
                $candidate === ''
                || $candidate === $value
                || preg_match(self::CHINESE_CHAR_PATTERN, $candidate) !== 1
            ) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}