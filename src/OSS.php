<?php

namespace Nldou\AliyunOSS;

use Nldou\AliyunOSS\Util;
use Nldou\AliyunOSS\OSSPost;
use OSS\OssClient;
use OSS\Core\OssException;
use Log;
use Nldou\AliyunOSS\Exceptions\InvalidCallbackParamsException;
use Nldou\AliyunOSS\Exceptions\PutObjectException;
use Nldou\AliyunOSS\Exceptions\PutFileException;
use Nldou\AliyunOSS\Exceptions\InvalidGetObjectOptionsException;
use Nldou\AliyunOSS\Exceptions\ReadFileException;
use Nldou\AliyunOSS\Exceptions\DownloadFileException;
use Nldou\AliyunOSS\Exceptions\GetObjectMetaException;
use Nldou\AliyunOSS\Exceptions\CopyObjectException;
use Nldou\AliyunOSS\Exceptions\DeleteObjectException;
use Nldou\AliyunOSS\Exceptions\ListObjectException;
use Nldou\AliyunOSS\Exceptions\PutObjectAclException;
use Nldou\AliyunOSS\Exceptions\CheckObjectExistException;
use Nldou\AliyunOSS\Exceptions\GetObjectAclException;
use Nldou\AliyunOSS\Exceptions\AppendFileException;
use Nldou\AliyunOSS\Exceptions\MultiPartUploadException;
use Nldou\AliyunOSS\Exceptions\AbortMultipartUploadException;
use Nldou\AliyunOSS\Exceptions\ListMultipartUploadsException;
use Nldou\AliyunOSS\Exceptions\ListPartsException;
use Nldou\AliyunOSS\Exceptions\OSSPostException;

class OSS
{
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

    protected static $PostAuthOptions = [
        'bucket',
        'endPoint',
        'ssl',
        'fileType',
        'expire',
        'fileMaxSize',
        'callbackUrl',
        'callbackBody',
        'callbackVar'
    ];

    protected static $resultMap = [
        'Body'           => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType'    => 'mimetype',
        'Size'           => 'size',
        'StorageClass'   => 'storage_class',
    ];

    protected $client;

    protected $bucket;

    protected $endPoint;

    protected $endPointInternal;

    protected $ssl;

    public function __construct(
        OssClient $client,
        $bucket,
        $endPoint,
        $endPointInternal,
        $ssl = false,
        $debug = false
    )
    {
        $this->debug = $debug;
        $this->client = $client;
        $this->bucket = $bucket;
        $this->endPoint = $endPoint;
        $this->endPointInternal = $endPointInternal;
        $this->ssl = $ssl;

    }

    public function setBucket($bucket)
    {
        $this->bucket = $bucket;
        return $this;
    }

    public function setEndPoint($ep)
    {
        $this->endPoint = $ep;
        return $this;
    }

    public function setEndPointInternal($epInternal)
    {
        $this->endPointInternal = $epInternal;
        return $this;
    }

    public function setSSL($ssl)
    {
        $this->ssl = $ssl;
        return $this;
    }

    public function setDebug($debug)
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * 简单上传
     *
     * @param string $object 完整上传路径(包括文件名)
     * @param string $contents 上传内容
     * @param array $options 上传回调参数
     * @param boolean $enableCallback 是否开启上传回调
     *
     * @return array 上传成功返回结构数组
     * @throws InvalidCallbackParamsException
     * @throws PutObjectException
     */
    public function put($object, $contents, $config = [], $enableCallback = false)
    {
        $options = [];
        if ($enableCallback) {
            $callback = json_encode($this->loadCallbackOptionsFromConfig($config));
            $callbackVar = json_encode($this->loadCallbackVarOptionsFromConfig($config));
            $options[OssClient::OSS_CALLBACK] = $callback;
            $options[OssClient::OSS_CALLBACK_VAR] = $callbackVar;
        }
        try {
            // 返回值为数组，当callback存在时，callbackUrl返回的结果存储在数组的body字段
            $res = $this->client->putObject($this->bucket, $object, $contents, $options);
            if ($enableCallback) {
                return json_decode($res['body'], true);
            }
        } catch (OssException $e) {
            throw new PutObjectException($e->getErrorMessage());
        }
        return $this->normalizeResponse($options, $object);
    }

    /**
     * 文件上传
     *
     * @param string $object 上传路径
     * @param string $filPath 上传文件的路径
     *
     * @return array 上传成功返回结构数组
     * @throws PutFileException
     */
    public function putFile($object, $filePath)
    {
        $options[OssClient::OSS_CHECK_MD5] = true;
        try {
            $this->client->uploadFile($this->bucket, $object, $filePath, $options);
        } catch (OssException $e) {
            throw new PutFileException($e->getErrorMessage());
        }
        return $this->normalizeResponse($options, $object);
    }

    public function multiPartUpload($object, $filePath, $config = [], $enableCallback = false)
    {
        $options = [
            OssClient::OSS_CHECK_MD5 => true,
            OssClient::OSS_PART_SIZE => 10 * 1024 * 1024,
        ];
        if ($enableCallback) {
            $callback = json_encode($this->loadCallbackOptionsFromConfig($config));
            $callbackVar = json_encode($this->loadCallbackVarOptionsFromConfig($config));
            $options[OssClient::OSS_CALLBACK] = $callback;
            $options[OssClient::OSS_CALLBACK_VAR] = $callbackVar;
        }
        try {
            // 返回值为数组，当callback存在时，callbackUrl返回的结果存储在数组的body字段
            $res = $this->client->multiuploadFile($this->bucket, $object, $filePath, $options);
            if ($enableCallback) {
                return json_decode($res['body'], true);
            }
        } catch (OssException $e) {
            throw new MultiPartUploadException($e->getErrorMessage());
        }
        return $this->normalizeResponse($options, $object);
    }

    public function abortMultipartUpload($object, $uploadId)
    {
        try{
            $this->client->abortMultipartUpload($this->bucket, $object, $uploadId);
        } catch(OssException $e) {
            throw new AbortMultipartUploadException($e->getErrorMessage());
        }
        return true;
    }

    public function listMultipartUploads($config)
    {
        $options = [
            'delimiter' => '/',
            'max-uploads' => 100
        ];
        if (array_key_exists('prefix', $config)) {
            $options['prefix'] = $config['prefix'];
        }
        if (array_key_exists('key-marker', $config)) {
            $options['key-marker'] = $config['key-marker'];
            if (array_key_exists('upload-id-marker', $config)) {
                $options['upload-id-marker'] = $config['upload-id-marker'];
            }
        }
        try {
            $list = $this->client->listMultipartUploads($this->bucket, $options);
            $ret = [];
            foreach ($list->getUploads() as $upload){
                $row = [];
                $row['key'] = $upload->getKey();
                $row['uploadId'] = $upload->getUploadId();
                $row['initiated'] = $upload->getInitiated();
                $ret[] = $row;
            }
            return $ret;
        } catch (OssException $e) {
            throw new ListMultipartUploadsException($e->getErrorMessage());
        }
    }

    public function listParts($object, $uploadId)
    {
        try{
            $listPartsInfo = $this->client->listParts($this->bucket, $object, $uploadId);
            $ret = [];
            foreach ($listPartsInfo->getListPart() as $partInfo) {
                $row = [];
                $row['partNumber'] = $partInfo->getPartNumber();
                $row['size'] = $partInfo->getSize();
                $row['eTag'] = $partInfo->getETag();
                $row['lastModified'] = $partInfo->getLastModified();
                $ret[] = $row;
            }
            return $ret;
        } catch(OssException $e) {
            throw new ListPartsException($e->getErrorMessage());
        }
    }

    /**
     * 文件追加上传
     * 上传的Object为Appendable Object类型，与其他上传方式生成的Normal Object类型Object不同
     * @param string $object 上传路径
     * @param array $filPaths 上传文件的路径
     *
     * @return array 上传成功返回结构数组
     * @throws AppendFileException
     */
    public function appendFile($object, $filePaths)
    {
        $limit = 5 * 1024 * 1024 * 1024;
        foreach ($filePaths as $file) {
            if (filesize($file) > $limit) {
                throw new AppendFileException('Append File: file oversized limit 5G');
                break;
            }
        }
        if ($this->has($object)) {
            $pos = $this->getObjectSize($object);
        } else {
            $pos = 0;
        }
        try {
            foreach ($filePaths as $file) {
                $pos = $this->client->appendFile($this->bucket, $object, $file, $pos);
            }
        } catch (OssException $e) {
            throw new AppendFileException($e->getErrorMessage());
        }

        return $this->normalizeResponse([], $object);
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
            throw new ReadFileException($e->getErrorMessage());
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
            throw new DownloadFileException($e->getErrorMessage());
        }
        return $this->normalizeResponse($options, $object);
    }

    /**
     * 重命名文件
     * @param string $object 原文件
     * @param string $newObject 新文件
     *
     * @return boolean 执行结果
     * @throws GetObjectMetaException
     * @throws CopyObjectException
     * @throws DeleteObjectException
     */
    public function rename($object, $newObject)
    {
        $this->copy($object, $newObject);
        return $this->delete($object);
    }

    /**
     * 拷贝文件
     * @param string $fromObject 拷贝文件
     * @param string $toObject 目标文件
     * @param string $toBucket 目标bucket
     *
     * @return boolean true 执行成功
     * @throws CopyObjectException
     */
    public function copy($fromObject, $toObject, $toBucket = null)
    {
        if (!$toBucket) {
            $toBucket = $this->bucket;
        }
        // 获取文件大小
        $size = $this->getObjectSize($fromObject);
        // 小于1G简单拷贝
        if ($size < 1024*1024*1024) {
            try{
                $this->client->copyObject($this->bucket, $fromObject, $toBucket, $toObject);
            } catch (OssException $e) {
                throw new CopyObjectException($e->getErrorMessage());
            }

        } else {
            throw new CopyObjectException('Copy object: oversized limit 1G');
        }
        return true;
    }

    /**
     * 删除单个文件
     * @param string $object 删除文件
     *
     * @return boolean 删除结果
     * @throws DeleteObjectException
     */
    public function delete($object)
    {
        try{
            $this->client->deleteObject($this->bucket, $object);
        }catch (OssException $e) {
            throw new DeleteObjectException($e->getErrorMessage());
        }
        return ! $this->has($object);
    }

    /**
     * 删除多个文件
     * @param string $object 删除文件
     *
     * @return mixed 删除结果 返回true或失败的文件数组
     * @throws DeleteObjectException
     */
    public function multiDelete(Array $objects)
    {
        $bucket = $this->bucket;
        try{
            $this->client->deleteObjects($bucket, $objects);
        }catch (OssException $e) {
            throw new DeleteObjectException($e->getErrorMessage());
        }
        $fails = [];
        foreach ($objects as $obj) {
            if ($this->has($obj)) {
                $fails[] = $obj;
            }
        }
        return empty($fails) ? true : $fails;
    }

    /**
     * 删除目录及文件
     * @param string $dirname
     *
     * @return boolean 删除结果
     * @throws ListObjectException
     * @throws DeleteObjectException
     *
     */
    public function deleteDir($dirname)
    {
        $dirname = rtrim($dirname, '/').'/';
        $dirObjects = $this->listDirObjects($dirname, true);
        // 删除文件
        if(count($dirObjects['objects']) > 0 ){
            foreach($dirObjects['objects'] as $object)
            {
                $objects[] = $object['Key'];
            }
            try {
                $this->client->deleteObjects($this->bucket, $objects);
            } catch (OssException $e) {
                throw new DeleteObjectException($e->getErrorMessage());
            }
        }
        // 删除目录
        try {
            $this->client->deleteObject($this->bucket, $dirname);
        } catch (OssException $e) {
            throw new DeleteObjectException($e->getErrorMessage());
        }
        return true;
    }

    /**
     * 列举文件夹内文件列表；可递归获取子文件夹；
     * @param string $dirname 目录
     * @param bool $recursive 是否递归
     *
     * @return array 文件列表
     * @throws ListObjectException
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
                throw new ListObjectException($e->getErrorMessage());
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
     * 设置文件ACL
     * @param string $object
     * @param string $visibility 文件权限
     *
     * @return array 更改后的文件权限
     * @throws PutObjectAclException
     *
     */
    public function setVisibility($object, $visibility)
    {
        if (!in_array($visibility,
            [
                OssClient::OSS_ACL_TYPE_PRIVATE,
                OssClient::OSS_ACL_TYPE_PUBLIC_READ,
                OssClient::OSS_ACL_TYPE_PUBLIC_READ_WRITE
            ])) {
                throw new PutObjectAclException('put object acl: visibility is illgal');
            }
        try {
            $this->client->putObjectAcl($this->bucket, $object, $visibility);
        } catch (OssException $e) {
            throw new PutObjectAclException($e->getErrorMessage());
        }
        return compact('visibility');
    }
    /**
     * 判断文件是否存在
     * @param string $object
     *
     * @return boolean 文件是否存在
     * @throws CheckObjectExistException
     */
    public function has($object)
    {
        try {
            return $this->client->doesObjectExist($this->bucket, $object);
        } catch (OssException $e) {
            throw new CheckObjectExistException($e->getErrorMessage());
        }

    }

    /**
     * 获取文件元信息
     * @param string $object
     *
     * @return array 文件元信息
     * @throws GetObjectMetaException
     */
    public function getObjectMeta($object)
    {
        try {
            if ($this->has($object)){
                $objectMeta = $this->client->getObjectMeta($this->bucket, $object);
            } else {
                throw new GetObjectMetaException('object do not exist');
            }
        } catch (OssException $e) {
            throw new GetObjectMetaException($e->getErrorMessage());
        }
        return $objectMeta;
    }
    /**
     * 获取文件大小
     * @param string $object
     *
     * @return int 文件大小 单位B
     * @throws GetObjectMetaException
     */
    public function getObjectSize($object)
    {
        $meta = $this->getObjectMeta($object);
        return (int)$meta['content-length'];
    }
    /**
     * 获取文件类型
     * @param string $object
     *
     * @return string 文件MIME类型
     * @throws GetObjectMetaException
     */
    public function getMimetype($object)
    {
        $meta = $this->getObjectMeta($object);
        return $meta['content-type'];
    }
    /**
     * 获取文件上次修改时间
     * @param string $object
     *
     * @return int 时间戳
     * @throws GetObjectMetaException
     */
    public function getLastmodified($object)
    {
        $meta = $this->getObjectMeta($object);
        return strtotime( $meta['last-modified'] );
    }
    /**
     * 获取文件ACL
     * @param string $object
     *
     * @return string 文件访问权限
     */
    public function getVisibility($object)
    {
        try {
            return $this->client->getObjectAcl($this->bucket, $object);
        } catch (OssException $e) {
            throw new GetObjectAclException($e->getErrorCode());
        }
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

    public function getPostAuth($config)
    {
        $options['bucket'] = $this->bucket;
        $options['endPoint'] = $this->endPoint;
        $options['ssl'] = $this->ssl;
        $res = $this->loadPostOptionsFromConfig($config);
        $options = array_merge($options, $res);
        $post = new OSSPost($options);
        return $post->getAuth();
    }

    public function loadPostOptionsFromConfig($config)
    {
        $options = [];
        foreach (self::$PostAuthOptions as $op) {
            if (!in_array($op, $config)) {
                continue;
            }
            if ($op === 'fileType' && !in_array($config[$op], ['image', 'video', 'audio'])) {
                throw new OSSPostException('Invalid File Type');
                break;
            }
            $options[$op] = $config[$op];
        }
        return $options;
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
        // 自定义参数
        if (array_key_exists('callbackVar', $config)) {
            if (!is_array($config['callbackVar'])) {
                throw new InvalidCallbackParamsException('Invalid callback format: '.'callbackVar is not array');
            }
            // 自定义参数
            foreach ($config['callbackVar'] as $k => $v) {
                $k = strtolower($k);
                $key = 'x:'.$k;
                $callbackBody[] = $k.'=${'.$key.'}';
            }
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
            // 3.参数值必须是string
            foreach ($config['callbackVar'] as $k => $v) {
                $key = 'x:'.strtolower($k);
                $callbackVar[$key] = (string)$v;
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
}
