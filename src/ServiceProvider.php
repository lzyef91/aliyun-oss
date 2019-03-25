<?php

namespace Nldou\AliyunOSS;

use Nldou\AliyunOSS\OSS;
use OSS\OssClient;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    protected $defer = true;

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/config' => config_path()], 'nldou-aliyunoss-config');
        }
    }

    public function register()
    {
        $this->app->singleton(OSS::class, function ($app) {
            $config = config('oss');
            $accessId  = $config['access_id'];
            $accessKey = $config['access_secret'];
            $bucket    = $config['bucket'];
            $ssl       = $config['ssl'];
            $debug     = $config['debug'];

            $endPoint  = $config['endpoint'];
            // 生产环境优先使用内网访问
            if ($app->environment('production')) {
                $ep = empty($config['endpointInternal']) ? $endPoint : $config['endpointInternal'];
            } else {
                $ep = $endPoint;
            }

            $client  = new OssClient($accessId, $accessKey, $ep);
            // 是否使用https
            $client->setUseSSL = $ssl;

            return new OSS($client, $bucket, $debug);
        });
        $this->app->alias(OSS::class, 'oss');
    }

    public function provides()
    {
        return [OSS::class, 'oss'];
    }
}