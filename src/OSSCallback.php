<?php

namespace Nldou\AliyunOSS;

use GuzzleHttp\Client;

class OSSCallback
{
    protected $callbackUrl;
    protected $callbackBody;
    protected $callbackBodyType;

    protected static $bodyOptions = [
        'bucket',
        'object',
        'etag',
        'size',
        'mimeType',
        'imageInfo.height',
        'imageInfo.width',
        'imageInfo.format'
    ];

    public static function getPostCallback($url, $body = [], $params = [], $bodyType = 'application/x-www-form-urlencoded')
    {

        $callbackBodyArr = [];
        // body参数
        foreach (self::$bodyOptions as $op) {
            if (!array_key_exists($op, $body)) {
                continue;
            }
            $callbackBodyArr[] = empty($body[$op]) ? $op.'=${'.$op.'}' : $body[$op].'=${'.$op.'}';
        }
        // 自定义参数
        // 1.必须以x:开头
        // 2.参数名不能有大写
        foreach ($params as $k => $p) {
            $var = strtolower($k);
            $callbackBodyArr[] = $var.'=${x:'.$var.'}';
        }
        $callbackBody = implode('&', $callbackBodyArr);

        $callback = [
            "callbackUrl" => $url,
            "callbackBody" => $callbackBody,
            "callbackBodyType" => $bodyType
        ];

        $callback_json = json_encode($callback);
        $callback_base64 = base64_encode($callback_json);
        return $callback_base64;
    }

    public static function checkSignature($path, $authorizationBase64, $pubKeyUrlBase64, $body = "")
    {
        // 获取OSS的签名, 公钥url和requestUri
        if (empty($authorizationBase64) || empty($pubKeyUrlBase64 || empty($path))) {
            return false;
        }
        // 获取OSS的签名
        $authorization = base64_decode($authorizationBase64);
        // 获取公钥
        $pubKeyUrl = base64_decode($pubKeyUrlBase64);
        $client = new Client();
        $pubKey = (string) $client->get($pubKeyUrl)->getBody();
        if ($pubKey == "") {
            return false;
        }
        // 拼接待签名字符串
        $authStr = '';
        if ($pos = strpos($path, '?')) {
            $authStr = urldecode($path)."\n".$body;
        } else {
            $authStr = urldecode(substr($path, 0, $pos)).substr($path, $pos, strlen($path) - $pos)."\n".$body;
        }
        // 验证签名
        $ok = openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5);
        return $ok == 1;
    }
}
