<?php

declare(strict_types=1);

namespace plugins\payment\jdpay;

class JdpayService
{
    private string $desKey;
    private string $privateKeyPath;
    private string $publicKeyPath;

    public function __construct(string $desKey, string $privateKeyPath, string $publicKeyPath)
    {
        $this->desKey = $desKey;
        $this->privateKeyPath = $privateKeyPath;
        $this->publicKeyPath = $publicKeyPath;
        if (!file_exists($this->privateKeyPath)) {
            throw new \Exception('商户RSA私钥文件不存在');
        }
        if (!file_exists($this->publicKeyPath)) {
            throw new \Exception('京东支付公钥文件不存在');
        }
    }

    public function encryptByPrivateKey(string $data): string
    {
        $pi_key = openssl_pkey_get_private(file_get_contents($this->privateKeyPath));
        openssl_private_encrypt($data, $encrypted, $pi_key, OPENSSL_PKCS1_PADDING);
        return base64_encode($encrypted);
    }

    public function decryptByPublicKey(string $data): string
    {
        $pu_key = openssl_pkey_get_public(file_get_contents($this->publicKeyPath));
        $data = base64_decode($data);
        openssl_public_decrypt($data, $decrypted, $pu_key);
        return $decrypted;
    }

    public function encrypt2HexStr(string $keys, string $sourceData): string
    {
        $length = strlen($sourceData);
        $result = '';
        for ($i = 0; $i < 4; $i++) {
            $shift = (4 - 1 - $i) * 8;
            $result .= chr(($length >> $shift) & 0x000000FF);
        }
        $result .= $sourceData;
        $add = 8 - ($length + 4) % 8;
        if ($add > 0) {
            for ($i = 0; $i < $add; $i++) {
                $result .= chr(0);
            }
        }
        $desdata = $this->tdesEncrypt($result, $keys);
        return bin2hex($desdata);
    }

    public function decrypt4HexStr(string $keys, string $data): string
    {
        $unDesResult = $this->tdesDecrypt(hex2bin($data), $keys);
        $length = 0;
        for ($i = 0; $i < 4; $i++) {
            $shift = (4 - 1 - $i) * 8;
            $length += (ord($unDesResult[$i]) & 0x000000FF) << $shift;
        }
        return substr($unDesResult, 4, $length);
    }

    public function tdesEncrypt(string $input, string $key): string
    {
        return openssl_encrypt($input, 'des-ede3', $key, OPENSSL_NO_PADDING, "");
    }

    public function tdesDecrypt(string $encrypted, string $key): string
    {
        return openssl_decrypt($encrypted, 'des-ede3', $key, OPENSSL_NO_PADDING, "");
    }

    public function signWithoutToHex(array $params, array $unSignKeyList): string
    {
        ksort($params);
        $sourceSignString = $this->signString($params, $unSignKeyList);
        $sha256SourceSignString = hash("sha256", $sourceSignString);
        return $this->encryptByPrivateKey($sha256SourceSignString);
    }

    public function signString(array $data, array $unSignKeyList): string
    {
        $linkStr = "";
        ksort($data);
        foreach ($data as $key => $value) {
            if ($value == "" || in_array($key, $unSignKeyList)) {
                continue;
            }
            $linkStr .= $key . "=" . $value . "&";
        }
        return substr($linkStr, 0, -1);
    }

    public function arrtoxml(array $arr, ?\DOMDocument $dom = null, ?\DOMElement $item = null): \DOMDocument
    {
        if (!$dom) {
            $dom = new \DOMDocument("1.0", "UTF-8");
        }
        if (!$item) {
            $item = $dom->createElement("jdpay");
            $item = $dom->appendChild($item);
        }
        foreach ($arr as $key => $val) {
            $itemx = $dom->createElement(is_string($key) ? $key : "item");
            $itemx = $item->appendChild($itemx);
            if (!is_array($val)) {
                $text = $dom->createTextNode((string) $val);
                $itemx->appendChild($text);
            } else {
                $this->arrtoxml($val, $dom, $itemx);
            }
        }
        return $dom;
    }

    public function xmlToString(\DOMDocument $dom): string
    {
        $xmlStr = $dom->saveXML();
        $xmlStr = str_replace(["\r", "\n", "\t"], "", $xmlStr);
        $xmlStr = preg_replace("/>\s+</", "><", $xmlStr);
        $xmlStr = preg_replace("/\s+\/>/", "/>", $xmlStr);
        $xmlStr = str_replace("=utf-8", "=UTF-8", $xmlStr);
        return $xmlStr;
    }

    public function encryptReqXml(array $param): string
    {
        $dom = $this->arrtoxml($param);
        $xmlStr = $this->xmlToString($dom);
        $sha256SourceSignString = hash("sha256", $xmlStr);
        $sign = $this->encryptByPrivateKey($sha256SourceSignString);
        $rootDom = $dom->getElementsByTagName("jdpay");
        $signDom = $dom->createElement("sign");
        $signDom = $rootDom[0]->appendChild($signDom);
        $signText = $dom->createTextNode($sign);
        $signDom->appendChild($signText);
        $data = $this->xmlToString($dom);
        $keys = base64_decode($this->desKey);
        $encrypt = $this->encrypt2HexStr($keys, $data);
        $encrypt = base64_encode($encrypt);
        $reqParam = [];
        $reqParam["version"] = $param["version"];
        $reqParam["merchant"] = $param["merchant"];
        $reqParam["encrypt"] = $encrypt;
        $reqDom = $this->arrtoxml($reqParam);
        return $this->xmlToString($reqDom);
    }

    public function decryptResXml(string $resultData, array &$resData): bool
    {
        $resultXml = simplexml_load_string($resultData);
        $resultObj = json_decode(json_encode($resultXml), true);
        $encryptStr = base64_decode($resultObj["encrypt"]);
        $keys = base64_decode($this->desKey);
        $reqBody = $this->decrypt4HexStr($keys, $encryptStr);
        $bodyXml = simplexml_load_string($reqBody);
        $resData = json_decode(json_encode($bodyXml), true);
        $inputSign = $resData["sign"];
        $xml = $reqBody;
        $startIndex = strpos($reqBody, "<sign>");
        $endIndex = strpos($reqBody, "</sign>");
        if ($startIndex !== false && $endIndex !== false) {
            $xml = substr($reqBody, 0, $startIndex) . substr($reqBody, $endIndex + 7);
        }
        $sha256SourceSignString = hash("sha256", $xml);
        $decryptStr = $this->decryptByPublicKey($inputSign);
        $flag = ($decryptStr == $sha256SourceSignString);
        $resData["version"] = $resultObj["version"];
        $resData["merchant"] = $resultObj["merchant"];
        $resData["result"] = $resultObj["result"];
        return $flag;
    }

    public function httpPost(string $url, string $data): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 28);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml;charset=utf-8']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $return_content = curl_exec($ch);
        $return_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$return_code, $return_content];
    }
}
