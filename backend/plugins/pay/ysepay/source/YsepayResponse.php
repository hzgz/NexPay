<?php

declare(strict_types=1);

namespace plugins\payment\ysepay;

class YsepayResponse
{
    /**
     * 响应签名节点名
     */
    const SIGN_NODE = 'sign';

    /**
     * 响应数据节点后缀
     */
    const RESPONSE_SUFFIX = '_response';

    /**
     * 原始响应
     */
    protected string $raw;

    /**
     * 已解析的响应
     */
    protected ?array $parsed;

    /**
     * 数据节点名称
     */
    protected string $nodeName;

    /**
     * 待验签数据
     */
    protected string $signData;

    /**
     * @param $raw     原始数据
     * @param $apiName 接口名称
     */
    public function __construct(string $raw, string $apiName)
    {
        $this->raw = $raw;
        $this->parsed = json_decode($raw, true);
        if (!$this->parsed) {
            $error = function_exists('json_last_error_msg') ? json_last_error_msg() : json_last_error();
            throw new \Exception('返回数据解析失败:' . $error);
        }
        $this->parseResponseData($apiName);
    }

    /**
     * 获取原始响应的被签名数据，用于验证签名.
     */
    protected function parseResponseData(string $apiName): void
    {
        $nodeName = str_replace(".", "_", $apiName) . self::RESPONSE_SUFFIX;
        $nodeIndex = strpos($this->raw, $nodeName);
        if (!$nodeIndex) {
            throw new \Exception('Response data not found');
        }
        $this->nodeName = $nodeName;

        $signDataStartIndex = $nodeIndex + strlen($nodeName) + 2;
        $signIndex = strrpos($this->raw, '"' . static::SIGN_NODE . '"');

        $signDataEndIndex = $signIndex - 1;
        $indexLen = $signDataEndIndex - $signDataStartIndex;
        if ($indexLen < 0) {
            $signIndex = strrpos($this->raw, "}");
            $signDataEndIndex = $signIndex;
            $indexLen = $signDataEndIndex - $signDataStartIndex;
        }

        $this->signData = substr($this->raw, $signDataStartIndex, $indexLen);
    }

    /**
     * 获取待验签数据
     */
    public function getSignData(): string
    {
        return $this->signData;
    }

    /**
     * 获取响应内的签名.
     */
    public function getSign(): ?string
    {
        if (isset($this->parsed[static::SIGN_NODE])) {
            return $this->parsed[static::SIGN_NODE];
        }
        return null;
    }

    /**
     * 获取响应内的数据.
     */
    public function getData(bool $assoc = true): mixed
    {
        if (!isset($this->parsed[$this->nodeName])) {
            return null;
        }
        $result = $this->parsed[$this->nodeName];
        if ($assoc == false) {
            $result = (object) ($result);
        }
        return $result;
    }

    /**
     * 判断响应是否成功.
     */
    public function isSuccess(): bool
    {
        if (!isset($this->parsed[$this->nodeName])) {
            return false;
        }
        $data = $this->parsed[$this->nodeName];
        return isset($data['code']) && $data['code'] == '10000';
    }

    /**
     * 获取原始响应.
     */
    public function getRaw(): string
    {
        return $this->raw;
    }
}
