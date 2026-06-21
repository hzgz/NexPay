<?php
namespace plugins\payment\helipay;

class DES3
{
    // 数据加密
    public function encrypt($input, $key)
    {
        $key = str_pad($key, 24, '0');
        $iv = openssl_random_pseudo_bytes(8);
        $input = $this->pkcs5_pad($input, 8);
        $encrypted = openssl_encrypt(
            $input,
            'des-ede3-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        return base64_encode($iv . $encrypted);
    }

    // 数据解密
    public function decrypt($encrypted, $key)
    {
        $encrypted = base64_decode($encrypted);
        $key = str_pad($key, 24, '0');
        $iv = substr($encrypted, 0, 8);
        $encrypted = substr($encrypted, 8);
        $decrypted = openssl_decrypt(
            $encrypted,
            'des-ede3-cbc',
            $key,
            OPENSSL_RAW_DATA,
            $iv
        );
        return $this->pkcs5_unpad($decrypted);
    }

    // PKCS5 填充
    private function pkcs5_pad($text, $blocksize)
    {
        $pad = $blocksize - (strlen($text) % $blocksize);
        return $text . str_repeat(chr($pad), $pad);
    }

    // PKCS5 去除填充
    private function pkcs5_unpad($text)
    {
        $pad = ord($text[strlen($text) - 1]);
        if ($pad > strlen($text)) {
            return false;
        }
        if (strspn($text, chr($pad), strlen($text) - $pad) != $pad) {
            return false;
        }
        return substr($text, 0, -1 * $pad);
    }

    public function encrypt2($input, $key)
    {
        $key = str_pad($key, 24, '0');
        $encrypted = openssl_encrypt($input, 'des-ede3', $key, OPENSSL_RAW_DATA);
        return base64_encode($encrypted);
    }

    public function decrypt2($encrypted, $key)
    {
        $key = str_pad($key, 24, '0');
        $encrypted = base64_decode($encrypted);
        $decrypted = openssl_decrypt($encrypted, 'des-ede3', $key, OPENSSL_RAW_DATA);
        return $decrypted;
    }
}
