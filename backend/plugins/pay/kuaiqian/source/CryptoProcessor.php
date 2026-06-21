<?php

declare(strict_types=1);

namespace plugins\payment\kuaiqian;

use Exception;

class CryptoProcessor
{
    //商户证书
    private string $merchantCert;

    //商户私钥
    private mixed $merchantKey;

    //快钱证书
    private string $kuaiqianCert;

    private string $temp_path;

    public function __construct(string $merchantCertPath, string $merchantCertPath_password, string $kuaiqianCertPath, string $temp_path)
    {
        $pfx = file_get_contents($merchantCertPath);
        if (!openssl_pkcs12_read($pfx, $certs, $merchantCertPath_password)) {
            throw new Exception("商户证书读取失败！");
        }
        $this->merchantCert = $certs['cert'];
        $this->merchantKey = $certs['pkey'];
        $this->kuaiqianCert = file_get_contents($kuaiqianCertPath);
        $this->temp_path = $temp_path;
    }

    /**
     * 商户端加密加签
     * @param string $originalData 加密前明文
     * @param string $salt 盐值，防止并发请求下，加解密txt文件的内容被覆写
     * @return array 请求快钱的body
     */
    public function seal(string $originalData, string $salt): array
    {
        $Body_final['signedData'] = $this->getSignedData($originalData, $salt);
        $Body_final['envelopedData'] = $this->getEnvelopedData($originalData, $salt);
        if (0 == strlen($Body_final['signedData']) || 0 == strlen($Body_final['envelopedData'])) {
            throw new Exception("请求出错，signedData或envelopedData为空！");
        }
        return $Body_final;
    }

    /**
     * 商户端解密验签
     * @param string $signedData 快钱返回的签名
     * @param string $envelopedData 快钱返回的密文
     * @param string $salt 盐值
     * @return string 解密后的明文
     */
    public function unseal(string $signedData, string $envelopedData, string $salt): string
    {
        $responseDecryptData = $this->getDecryptData($envelopedData, $salt);
        $verifyResult = $this->getVerifyFlag($responseDecryptData, $signedData, $salt);
        if (0 == strlen($responseDecryptData)) {
            throw new Exception("客户端解密失败！");
        }
        if (!$verifyResult) {
            throw new Exception("客户端验签失败！");
        }
        return $responseDecryptData;
    }

    /**
     * 获取密文 快钱证书加密
     */
    public function getEnvelopedData(string $originalData, string $salt): string
    {
        //定义一个data文件，写入明文body
        $originalDataPath = $this->temp_path . 'data_' . $salt . '.txt';
        if (!file_put_contents($originalDataPath, $originalData)) {
            throw new Exception("获取密文失败，写入文件失败！");
        }
        //获取证书内容
        $publickey = $this->kuaiqianCert;
        //定义一个endata文件，存放加密后数据
        $enDataPath = $this->temp_path . 'endata_' . $salt . '.txt';
        openssl_pkcs7_encrypt($originalDataPath, $enDataPath, $publickey, null,
            PKCS7_BINARY, OPENSSL_CIPHER_AES_128_CBC);
        //获取密文及字符处理
        $enData = file_get_contents($enDataPath);
        $finalEnData = str_replace(["\r\n", "\r", "\n", "\\"], "",
            substr($enData, 191, strlen($enData)));
        //返回
        unlink($originalDataPath);
        unlink($enDataPath);
        return $finalEnData;
    }

    /**
     * 获取签名 商户证书签名
     */
    public function getSignedData(string $originalData, string $salt): string
    {
        $originalDataPath = $this->temp_path . 'origdata_' . $salt . '.txt';
        if (!file_put_contents($originalDataPath, $originalData)) {
            throw new Exception("获取签名失败，写入文件失败！");
        }
        $signdataPath = $this->temp_path . 'signdata_' . $salt . '.txt';
        openssl_pkcs7_sign($originalDataPath, $signdataPath,
            $this->merchantCert,
            $this->merchantKey,
            [],
            PKCS7_BINARY);
        $signdata = file_get_contents($signdataPath);
        $finalsigndata = str_replace(["\r\n", "\r", "\n"], [""],
            substr($signdata, 186, strlen($signdata)));
        unlink($originalDataPath);
        unlink($signdataPath);
        return $finalsigndata;
    }

    /**
     * 返回解密 商户证书解密
     */
    public function getDecryptData(string $encryptoData, string $salt): string
    {
        $respdecryptoDataPath = $this->temp_path . 'respDecryptoData_' . $salt . '.txt';
        //txt内容须遵守SMIME格式规范，请勿做增删、对齐等操作
        $txt = "MIME-Version: 1.0
Content-Disposition: attachment; filename=\"smime.p7m\"
Content-Type: application/x-pkcs7-mime; smime-type=enveloped-data; name=\"smime.p7m\"
Content-Transfer-Encoding: base64" . "\n\n\n" . $encryptoData;
        if (!file_put_contents($respdecryptoDataPath, $txt)) {
            throw new Exception("返回解密失败，写入文件失败！");
        }
        $decryptoDataPath = $this->temp_path . 'decryptoData_' . $salt . '.txt';
        if (openssl_pkcs7_decrypt($respdecryptoDataPath, $decryptoDataPath,
            $this->merchantCert,
            $this->merchantKey)) {
            $decryptoData = file_get_contents($decryptoDataPath);
            unlink($decryptoDataPath);
            unlink($respdecryptoDataPath);
            return $decryptoData;
        } else {
            unlink($respdecryptoDataPath);
            if (file_exists($decryptoDataPath)) unlink($decryptoDataPath);
            throw new Exception('返回数据解密失败！failed to decrypt!');
        }
    }

    /**
     * 返回验签 快钱证书验签
     */
    public function getVerifyFlag(string $decryptoData, string $signedData, string $salt): bool
    {
        $respsignedDataPath = $this->temp_path . 'respSignedData_' . $salt . '.txt';
        $txt = $signedData;
        file_put_contents($respsignedDataPath, $this->formatSmimeSignData($txt, $decryptoData));

        $unSignDataPath = $this->temp_path . 'unSignData_' . $salt . '.txt';

        $flag = openssl_pkcs7_verify($respsignedDataPath, PKCS7_NOVERIFY, $unSignDataPath);

        unlink($respsignedDataPath);
        if (file_exists($unSignDataPath)) unlink($unSignDataPath);

        return $flag == 1;
    }

    /**
     * 内容须遵守SMIME格式规范，请勿做增删、对齐等操作
     */
    public function formatSmimeSignData(string $txt, string $decryptoData): string
    {
        $signData = chunk_split($txt, 76, "\n");
        $boundary = "----" . md5($signData);
        $signData = <<<EOD
MIME-Version: 1.0
Content-Type: multipart/signed; protocol="application/x-pkcs7-signature"; micalg=sha256; boundary="$boundary"

This is an S/MIME signed message

--$boundary
$decryptoData
--$boundary
Content-Type: application/x-pkcs7-signature; name="smime.p7s"
Content-Transfer-Encoding: base64
Content-Disposition: attachment; filename="smime.p7s"

$signData

--$boundary--


EOD;

        return $signData;
    }
}
