<?php

namespace Nldou\AliyunOSS;

use Nldou\AliyunOSS\OSSCallback;
use Nldou\AliyunOSS\Exceptions\OSSPostException;

/**
 * Class OSSPostAuth
 * 客户端（web，小程序）直传的授权类
 */
class OSSPost
{
    private $accessId;

    protected $auth;
    protected $key;
    protected $host;
    protected $callback;
    protected $callbackVar;
    protected $policy;
    protected $signature;
    protected $dir;
    protected $id;


    /**
     * 获取直传授权
     *
     * @param array $config 授权配置
     * string $fileType 要上传的文件类型（必需） 取值[image,video,audio]
     * string $callbackUrl 回调地址（必需）
     * string $callbackBody OSS规定的回调参数 [规定参数名 => 回调参数名] 默认[]
     * string $callbackVar 自定义的回调参数 [参数名 => 参数值] 默认[]
     * string $bucket OSS存储空间 默认config('oss.bucket')
     * string $endPoint OSS节点 默认config('oss.endpoint')
     * bool $ssl 是否采用https 默认config('oss.ssl')
     * int $expire 授权过期时间 单位s 默认60s
     * int $fileMaxSize 最大上传文件大小 单位B 默认104857600（100MB）
     */
    public function __construct($config)
    {
        $fileType = $config['fileType'];
        if (!in_array($fileType, ['image', 'video', 'audio'])) {
            throw new OSSPostException('Invalid File Type');
        }
        $dir = $this->parsePostDir($fileType);
        $this->dir = $dir;
        $this->key = $this->parsePostKey($dir);
        // 域名
        $bucket = !isset($config['bucket']) ? config('oss.bucket') : $config['bucket'];
        $endPoint = !isset($config['endPoint']) ? config('oss.endpoint') : $config['endPoint'];
        $ssl = !isset($config['ssl']) ? config('oss.ssl') : $config['ssl'];
        $this->host = $this->parseHost($bucket, $endPoint, $ssl);
        // 回调
        $callbackUrl = $config['callbackUrl'];
        $callbackBody = !isset($config['callbackBody']) ? [] : $config['callbackBody'];
        $callbackVar = !isset($config['callbackVar']) ? [] : $config['callbackVar'];
        $this->callbackVar = $callbackVar;
        $this->callback = OSSCallback::getPostCallback($callbackUrl, $callbackBody, $callbackVar);
        // policy
        $expire = !isset($config['expire']) ? 60 : $config['expire'];
        $expireAt = $this->parseExpireAt($expire);
        $fileMaxSize = !isset($config['fileMaxSize']) ? 104857600 : $config['fileMaxSize'];
        $this->policy = $this->parsePolicy($expireAt, $fileMaxSize, $dir, $this->callback);
        // signature
        $this->accessId = config('oss.access_id');
        $accessSecret = config('oss.access_secret');
        $this->signature = $this->parseSignature($this->policy, $accessSecret);
        // 生成授权
        $this->auth = $this->parseAuth();
    }

    public function getAuth()
    {
        return $this->auth;
    }

    public function parseAuth()
    {
        // 请求地址
        $ret['host'] = $this->host;
        $formData['type'] = 'oss';
        // 用户上传文件的key
        $formData['key'] = $this->key;
        $formData['dir'] = $this->dir;
        $formData['id'] = $this->id;
        // AK
        $formData['OSSAccessKeyId'] = $this->accessId;
        // policy
        $formData['policy'] = $this->policy;
        // 生成签名
        $formData['signature'] = $this->signature;
        $formData['success_action_status'] = "201";
        // callback参数
        if (!empty($this->callback)) {
            $formData['callback'] = $this->callback;
            // 自定义参数
            // 1.必须以x:开头
            // 2.参数名不能有大写
            if (!empty($this->callbackVar)) {
                foreach($this->callbackVar as $k => $v) {
                    $var = strtolower($k);
                    $formData['x:'.$var] = $v;
                }
            }
        }
        $ret['formData'] = $formData;
        return $ret;
    }

    public function parseHost($bucket, $endPoint, $ssl)
    {
        if (strpos($endPoint, 'http://') === 0) {
            $endPoint = substr($endPoint, strlen('http://'));
        } elseif (strpos($endPoint, 'https://') === 0) {
            $endPoint = substr($endPoint, strlen('https://'));
        }
        $hostname = $bucket.'.'.rtrim($endPoint, '/');
        $host =  $ssl ? 'https://'.$hostname : 'http://'.$hostname;
        return $host;
    }

    public function parseExpireAt($expire)
    {
        $end = time() + $expire;
        $expireAt = $this->parseGMTISO8601($end);
        return $expireAt;
    }

    public function parseGMTISO8601($time)
    {
        $dtStr = date("c", $time);
        $dtime = new \DateTime($dtStr);
        $parse = $dtime->format(\DateTime::ISO8601);
        $pos = strpos($parse, '+');
        $parse = substr($parse, 0, $pos);
        return $parse."Z";
    }

    public function parsePostDir($type)
    {
        // 分区
        $dir = str_random(6).'/';
        // 目录
        switch (strtolower($type)) {
            case 'video':
                $dir .= ltrim(config('oss.videoDir'), '/');
                break;
            case 'image':
                $dir .= ltrim(config('oss.imageDir'), '/');
                break;
            case 'audio':
                $dir .= ltrim(config('oss.audioDir'), '/');
                break;
        }
        $dir = rtrim($dir, '\\/');
        // 日期
        $date = date('Ym/d/', time());
        $dir .= '/'.$date;
        return $dir;
    }

    public function parsePostKey($dir)
    {
        // 生成文件名
        $filename = str_random(7).uniqid();
        $this->id = $filename;
        $key = $dir.$filename;
        return $key;
    }

    public function parsePolicy($expireAt, $fileMaxSize = 0, $dir = '', $callback = '')
    {
        $conditions = [];
        // 限制上传文件大小
        if ($fileMaxSize > 0) {
            $conditions[] = ['content-length-range', 0, $fileMaxSize];
        }
        // 限制上传文件的key的前缀，即目录
        if (!empty($dir)) {
            $conditions[] = ['starts-with', '$key', $dir];
        }
        // 防止callback篡改
        if (!empty($callback)) {
            $conditions[] = ['callback' => $callback];
        }
        $policy = json_encode([
            'expiration' => $expireAt,
            'conditions' => $conditions
        ]);
        $base64_policy = base64_encode($policy);
        return $base64_policy;
    }

    public function parseSignature($policy, $accessSecret)
    {
        return base64_encode(hash_hmac('sha1', $policy, $accessSecret, true));
    }
}
