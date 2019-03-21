<?php

namespace Nldou\AliyunOSS;

use Nldou\AliyunOSS\Util;
use OSS\Core\OssException;
use OSS\OssClient;
use Log;
use Nldou\AliyunOSS\Exceptions\InvalidCallbackParamsException;
use Nldou\AliyunOSS\Exceptions\PutObjectException;
use Nldou\AliyunOSS\Exceptions\PutFileException;
use Nldou\AliyunOSS\Exceptions\InvalidGetObjectOptionsException;
use Nldou\AliyunOSS\Exceptions\ReadFileException;
use Nldou\AliyunOSS\Exceptions\DownloadFileException;

class OSS
{
    const VISIBILITY_PUBLIC = 'public';
    const VISIBILITY_PRIVATE = 'private';

    /**
     * @var Log debug Mode true|false
     */
    protected $debug;

    protected static $callbackMap = [
        'callbackUrl',
        'callbackHost',
        'callbackBody',
        'callbackBodyType'
    ];

    protected static $callbackBodyOptions = [
        'bucket',
        'object',
        'etag',
        'size',
        'mimeType',
        'imageInfo.height',
        'imageInfo.width',
        'imageInfo.format'
    ];

    /**
     * @var array
     */
    protected static $resultMap = [
        'Body'           => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType'    => 'mimetype',
        'Size'           => 'size',
        'StorageClass'   => 'storage_class',
    ];
    /**
     * League\Flysystem\Config对象所能设置的有效值
     * @var array
     */
    // protected static $metaOptions = [
    //     'CacheControl',
    //     'Expires',
    //     'ServerSideEncryption',
    //     'Metadata',
    //     'ACL',
    //     'ContentType',
    //     'ContentDisposition',
    //     'ContentLanguage',
    //     'ContentEncoding',
    //     'PostObjectCallback',
    //     'PostObjectCallbackVar',
    //     'PostObjectFileMaxSize',
    //     'PostObjectExpire'
    // ];
    // protected static $metaMap = [
    //     'CacheControl'         => 'Cache-Control',
    //     'Expires'              => 'Expires',
    //     'ServerSideEncryption' => 'x-oss-server-side-encryption',
    //     'Metadata'             => 'x-oss-metadata-directive',
    //     'ACL'                  => 'x-oss-object-acl',
    //     'ContentType'          => 'Content-Type',
    //     'ContentDisposition'   => 'Content-Disposition',
    //     'ContentLanguage'      => 'response-content-language',
    //     'ContentEncoding'      => 'Content-Encoding',
    //     'PostObjectCallback'   => 'post-object-callback',
    //     'PostObjectCallbackVar'=> 'post-object-callback-var',
    //     'PostObjectFileMaxSize'=> 'post-object-file-max-size',
    //     'PostObjectExpire'     => 'post-object-expire'
    // ];
    //Aliyun OSS Client OssClient
    protected $client;
    //bucket name
    protected $bucket;
    /**
     * AliOssAdapter constructor.
     *
     * @param OssClient $client
     * @param string    $bucket
     * @param bool      $debug
     */
    public function __construct(
        OssClient $client,
        $bucket,
        $debug = false
    )
    {
        $this->debug = $debug;
        $this->client = $client;
        $this->bucket = $bucket;
    }

    protected function loadCallbackOptionsFromConfig($config)
    {
        if (!array_key_exists('callbackUrl', $config) || empty($config['callbackUrl'])) {
            throw new InvalidCallbackParamsException('Invalid callback format: '.'lack callbackUrl');
        }
        if (!array_key_exists('callbackBody', $config)) {
            throw new InvalidCallbackParamsException('Invalid callback format: '.'lack callbackBody');
        }
        if (!is_array($config['callbackBody'])) {
            throw new InvalidCallbackParamsException('Invalid callback format: '.'callbackBody is not array');
        }
        $callback['callbackUrl'] = $config['callbackUrl'];
        if (!array_key_exists('callbackBodyType', $config) || !in_array($config['callbackBodyType'], ['application/x-www-form-urlencoded', 'application/json'])) {
            $callback['callbackBodyType'] = 'application/x-www-form-urlencoded';
        } else {
            $callback['callbackBodyType'] = $config['callbackBodyType'];
        }
        // body参数
        $body = $config['callbackBody'];
        $callbackBody = [];
        foreach (self::$callbackBodyOptions as $op) {
            if (!in_array($op, $body)) {
                continue;
            }
            $callbackBody[] = $op.'=${'.$op.'}';
        }
        $callback['callbackBody'] = implode('&', $callbackBody);
        return $callback;
    }

    protected function loadCallbackVarOptionsFromConfig($config)
    {
        $callbackVar = [];
        if (array_key_exists('callbackVar', $config)) {
            if (!is_array($config['callbackVar'])) {
                throw new InvalidCallbackParamsException('Invalid callback format: '.'callbackVar is not array');
            }
            // 自定义参数
            // 1.必须以x:开头
            // 2.参数名不能有大写
            foreach ($config['callbackVar'] as $k => $v) {
                $key = 'x:'.strtolower($k);
                $callbackVar[$key] = $v;
            }
        }
        return $callbackVar;
    }

    protected function loadGetObjectOptionsFromConfig($config)
    {
        $options = [];
        // 限制下载范围
        if (array_key_exists(OssClient::OSS_RANGE, $config) && !empty($config[OssClient::OSS_RANGE])) {
            $options[OssClient::OSS_RANGE] = $config[OssClient::OSS_RANGE];
        }
        // 指定时间早于文件实际修改时间，否则报错412
        if (array_key_exists(OssClient::OSS_LAST_MODIFIED, $config) && !empty($config[OssClient::OSS_LAST_MODIFIED])) {
            if(!Util::is_timestamp($config[OssClient::OSS_LAST_MODIFIED])) {
                throw new InvalidGetObjectOptionsException('Invalid getObject options: '.OssClient::OSS_LAST_MODIFIED.' is not timestamp');
            }
            $options[OssClient::OSS_LAST_MODIFIED] = gmdate('D, d M Y H:i:s T', $config[OssClient::OSS_LAST_MODIFIED]);
        }
        // 指定的Etag要和文件不匹配，否则报错
        if (array_key_exists(OssClient::OSS_ETAG, $config) && !empty($config[OssClient::OSS_ETAG])) {
            $options[OssClient::OSS_ETAG] = $config[OssClient::OSS_ETAG];
        }
        // 指定图片处理模式
        if (array_key_exists(OssClient::OSS_PROCESS, $config) && !empty($config[OssClient::OSS_PROCESS])) {
            $options[OssClient::OSS_PROCESS] = $config[OssClient::OSS_PROCESS];
        }
        return $options;
    }

    /**
     * 简单上传
     *
     * @param string $object 完整上传路径(包括文件名)
     * @param string $contents 上传内容
     * @param array $options 上传回调参数
     * @param boolean $enableCallback 是否开启上传回调
     * @return array 上传成功返回结构数组
     * @throws InvalidCallbackParamsException
     * @throws PutObjectException
     */
    public function put($object, $contents, $config = [], $enableCallback = false)
    {
        $options = null;
        if ($enableCallback) {
            $callback = json_encode($this->loadCallbackOptionsFromConfig($config));
            $callbackVar = json_encode($this->loadCallbackVarOptionsFromConfig($config));
            $options = [
                OssClient::OSS_CALLBACK => $callback,
                OssClient::OSS_CALLBACK_VAR => $callbackVar
            ];
        }
        try {
            $this->client->putObject($this->bucket, $object, $contents, $options);
        } catch (OssException $e) {
            throw new PutObjectException($e->getErrorMessage(), $e->getErrorCode(), $e);
        }
        return $this->normalizeResponse($options, $object);
    }

    /**
     * 文件上传
     *
     * @param string $object 上传路径
     * @param string $filPath 上传文件的路径
     * @return array 上传成功返回结构数组
     * @throws PutFileException
     */
    public function putFile($object, $filePath)
    {
        $options[OssClient::OSS_CHECK_MD5] = true;
        try {
            $this->client->uploadFile($this->bucket, $object, $filePath, $options);
        } catch (OssException $e) {
            throw new PutFileException($e->getErrorMessage(), $e->getErrorCode(), $e);
        }
        return $this->normalizeResponse($options, $object);
    }

    /**
     * 下载到本地内存
     *
     * @param string $object 文件路径
     * @param string $config 配置项 range|lastmodified|etag|x-oss-process
     *
     * @return array 下载成功返回结构数组
     * @throws ReadFileException
     */
    public function read($object, $config = [])
    {
        $options = $this->loadGetObjectOptionsFromConfig($config);
        try {
            $result['Body'] = $this->client->getObject($this->bucket, $object, $options);
        } catch (OssException $e) {
            throw new ReadFileException($e->getErrorMessage(), $e->getErrorCode(), $e);
        }
        $result = array_merge($result, ['type' => 'file']);
        return $this->normalizeResponse($result, $object);
    }

    /**
     * 下载到本地文件
     *
     * @param string $object 文件路径
     * @param string $filePath 下载文件存储路径
     * @param string $config 配置项 range|lastmodified|etag|x-oss-process
     *
     * @return array 下载成功返回结构数组
     * @throws DownloadFileException
     */
    public function download($object, $filePath, $config = [])
    {
        $options = $this->loadGetObjectOptionsFromConfig($config);
        $options[OssClient::OSS_FILE_DOWNLOAD] = $filePath;
        try {
            $this->client->getObject($this->bucket, $object, $options);
        } catch (OssException $e) {
            throw new DownloadFileException($e->getErrorMessage(), $e->getErrorCode(), $e);
        }
        return $this->normalizeResponse($options, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($object, $newObject)
    {
        if (! $this->copy($object, $newObject)){
            return false;
        }
        return $this->delete($object);
    }
    /**
     * {@inheritdoc}
     */
    public function copy($object, $newObject)
    {
        try{
            $this->client->copyObject($this->bucket, $object, $this->bucket, $newObject);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return true;
    }
    /**
     * {@inheritdoc}
     */
    public function delete($object)
    {
        $bucket = $this->bucket;
        try{
            $this->client->deleteObject($bucket, $object);
        }catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return ! $this->has($object);
    }
    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $dirname = rtrim($dirname, '/').'/';
        $dirObjects = $this->listDirObjects($dirname, true);
        if(count($dirObjects['objects']) > 0 ){
            foreach($dirObjects['objects'] as $object)
            {
                $objects[] = $object['Key'];
            }
            try {
                $this->client->deleteObjects($this->bucket, $objects);
            } catch (OssException $e) {
                $this->logErr(__FUNCTION__, $e);
                return false;
            }
        }
        try {
            $this->client->deleteObject($this->bucket, $dirname);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return true;
    }
    /**
     * 列举文件夹内文件列表；可递归获取子文件夹；
     * @param string $dirname 目录
     * @param bool $recursive 是否递归
     * @return mixed
     * @throws OssException
     */
    public function listDirObjects($dirname = '', $recursive =  false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;
        //存储结果
        $result = [];
        while(true){
            $options = [
                'delimiter' => $delimiter,
                'prefix'    => $dirname,
                'max-keys'  => $maxkeys,
                'marker'    => $nextMarker,
            ];
            try {
                $listObjectInfo = $this->client->listObjects($this->bucket, $options);
            } catch (OssException $e) {
                $this->logErr(__FUNCTION__, $e);
                // return false;
                throw $e;
            }
            $nextMarker = $listObjectInfo->getNextMarker(); // 得到nextMarker，从上一次listObjects读到的最后一个文件的下一个文件开始继续获取文件列表
            $objectList = $listObjectInfo->getObjectList(); // 文件列表
            $prefixList = $listObjectInfo->getPrefixList(); // 目录列表
            if (!empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix']       = $dirname;
                    $object['Key']          = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag']         = $objectInfo->getETag();
                    $object['Type']         = $objectInfo->getType();
                    $object['Size']         = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();
                    $result['objects'][] = $object;
                }
            }else{
                $result["objects"] = [];
            }
            if (!empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            }else{
                $result['prefix'] = [];
            }
            //递归查询子目录所有文件
            if($recursive){
                foreach( $result['prefix'] as $pfix){
                    $next  =  $this->listDirObjects($pfix , $recursive);
                    $result["objects"] = array_merge($result['objects'], $next["objects"]);
                }
            }
            //没有更多结果了
            if ($nextMarker === '') {
                break;
            }
        }
        return $result;
    }
    /**
     * {@inheritdoc}
     */
    public function createDir($object, Config $config)
    {
        $options = $this->getOptionsFromConfig($config);
        try {
            $this->client->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return ['path' => $dirname, 'type' => 'dir'];
    }
    /**
     * {@inheritdoc}
     */
    public function setVisibility($object, $visibility)
    {
        $acl = ( $visibility === self::VISIBILITY_PUBLIC ) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
        $this->client->putObjectAcl($this->bucket, $object, $acl);
        return compact('visibility');
    }
    /**
     * {@inheritdoc}
     */
    public function has($object)
    {
        return $this->client->doesObjectExist($this->bucket, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $dirObjects = $this->listDirObjects($directory, true);
        $contents = $dirObjects["objects"];
        $result = array_map([$this, 'normalizeResponse'], $contents);
        $result = array_filter($result, function ($value) {
            return $value['path'] !== false;
        });
        return Util::emulateDirectories($result);
    }
    /**
     * {@inheritdoc}
     */
    public function getMetadata($object)
    {
        try {
            $objectMeta = $this->client->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }
        return $objectMeta;
    }
    /**
     * {@inheritdoc}
     */
    public function getSize($object)
    {
        $meta = $this->getMetadata($object);
        $meta['size'] = $meta['content-length'];
        return $meta;
    }
    /**
     * {@inheritdoc}
     */
    public function getMimetype($object)
    {
        if( $meta = $this->getMetadata($object))
            $meta['mimetype'] = $meta['content-type'];
        return $meta;
    }
    /**
     * {@inheritdoc}
     */
    public function getTimestamp($object)
    {
        if( $meta = $this->getMetadata($object))
            $meta['timestamp'] = strtotime( $meta['last-modified'] );
        return $meta;
    }
    /**
     * {@inheritdoc}
     */
    public function getVisibility($object)
    {
        try {
            $acl = $this->client->getObjectAcl($this->bucket, $object);
        } catch (OssException $e) {
            $this->logErr(__FUNCTION__, $e);
            return false;
        }

        if ($acl == OssClient::OSS_ACL_TYPE_PUBLIC_READ ){
            $res['visibility'] = self::VISIBILITY_PUBLIC;
        }else{
            $res['visibility'] = self::VISIBILITY_PRIVATE;
        }
        return $res;
    }

    /**
     * The the ACL visibility.
     *
     * @param string $object
     *
     * @return string
     */
    protected function getObjectACL($object)
    {
        $metadata = $this->getVisibility($object);
        return $metadata['visibility'] === self::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
    }
    /**
     * Normalize a result from OSS.
     *
     * @param array  $options
     * @param string $object
     *
     * @return array file metadata
     */
    protected function normalizeResponse(array $options, $object = null)
    {
        $result = ['path' => $object ?: (isset($options['Key']) ? $options['Key'] : $options['Prefix'])];
        $result['dirname'] = Util::dirname($object);
        if (isset($options['LastModified'])) {
            $result['timestamp'] = strtotime($options['LastModified']);
        }
        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');
            return $result;
        }

        $result = array_merge($result, Util::map($options, static::$resultMap), ['type' => 'file']);
        return $result;
    }
    /**
     * Get options for a OSS call. done
     *
     * @param array  $options
     *
     * @return array OSS options
     */
    protected function getOptions(array $options = [], Config $config = null)
    {
        if ($config) {
            $options = array_merge($options, $this->getOptionsFromConfig($config));
        }
        return array(OssClient::OSS_HEADERS => $options);
    }
    /**
     * Retrieve options from a Config instance. done
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];
        foreach (static::$metaOptions as $option) {
            if (! $config->has($option)) {
                continue;
            }
            $options[static::$metaMap[$option]] = $config->get($option);
        }
        if ($visibility = $config->get('visibility')) {
            // For local reference
            // $options['visibility'] = $visibility;
            // For external reference
            $options['x-oss-object-acl'] = $visibility === self::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
        }
        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            // $options['mimetype'] = $mimetype;
            // For external reference
            $options['Content-Type'] = $mimetype;
        }
        return $options;
    }
    /**
     * @param $fun string function name : __FUNCTION__
     * @param $e
     */
    protected function logErr($fun, $e){
        if( $this->debug ){
            Log::error($fun . ": FAILED");
            Log::error($e->getMessage());
        }
    }
}
