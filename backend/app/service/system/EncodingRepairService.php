<?php

namespace app\service\system;

class EncodingRepairService
{
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

        if (preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $value) === 1) {
            return $value;
        }

        if (preg_match('/[ÃÂâæçèéåäöüï¼]/u', $value) !== 1) {
            return $value;
        }

        foreach (['Windows-1252', 'ISO-8859-1'] as $encoding) {
            $bytes = @mb_convert_encoding($value, $encoding, 'UTF-8');
            if (!is_string($bytes) || $bytes === '' || !mb_check_encoding($bytes, 'UTF-8')) {
                continue;
            }

            if (preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $bytes) === 1) {
                return $bytes;
            }
        }

        return $value;
    }
}
