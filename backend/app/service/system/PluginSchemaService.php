<?php

namespace app\service\system;

class PluginSchemaService
{
    public static function isFieldVisible(
        array $field,
        string $methodCode = '',
        array $values = [],
        array $availableMethods = []
    ): bool {
        $show = trim((string)($field['show'] ?? ''));
        if ($show === '') {
            return true;
        }

        return self::evaluateShowRule($show, $methodCode, $values, $availableMethods);
    }

    public static function evaluateShowRule(
        string $rule,
        string $methodCode = '',
        array $values = [],
        array $availableMethods = []
    ): bool {
        $rule = trim($rule);
        if ($rule === '') {
            return true;
        }

        $orParts = preg_split('/\s*\|\|\s*/', $rule, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($orParts as $orPart) {
            $andParts = preg_split('/\s*&&\s*/', $orPart, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $matched = true;

            foreach ($andParts as $andPart) {
                if (!self::evaluateSingleRule(trim($andPart), $methodCode, $values, $availableMethods)) {
                    $matched = false;
                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    private static function evaluateSingleRule(
        string $rule,
        string $methodCode,
        array $values,
        array $availableMethods
    ): bool {
        if ($rule === '') {
            return true;
        }

        if (preg_match('/^([a-zA-Z0-9_]+)\s*(==|!=)\s*[\'"]?(.+?)[\'"]?$/', $rule, $matches) === 1) {
            $fieldKey = $matches[1];
            $operator = $matches[2];
            $expected = $matches[3];
            $actual = self::normalizeScalar($values[$fieldKey] ?? null);

            return $operator === '=='
                ? $actual === $expected
                : $actual !== $expected;
        }

        if (self::matchesMethodAlias($rule, $methodCode, $availableMethods)) {
            return true;
        }

        $actual = $values[$rule] ?? null;
        if (is_bool($actual)) {
            return $actual;
        }

        return trim((string)$actual) !== '';
    }

    private static function matchesMethodAlias(string $rule, string $methodCode, array $availableMethods): bool
    {
        if ($methodCode === '' && $availableMethods === []) {
            return false;
        }

        $normalizedRule = PaymentMetaService::normalizeMethodCode($rule);
        if ($normalizedRule === '') {
            return false;
        }

        if ($methodCode !== '' && PaymentMetaService::normalizeMethodCode($methodCode) === $normalizedRule) {
            return true;
        }

        foreach ($availableMethods as $availableMethod) {
            if (PaymentMetaService::normalizeMethodCode((string)$availableMethod) === $normalizedRule) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        return trim((string)$value);
    }
}
