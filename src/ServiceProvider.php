<?php

namespace Nldou\AliyunOSS;

use Nldou\AliyunOSS\OSS;
use OSS\OssClient;
use Illuminate\Support\ServiceProvider as LavarelServiceProvider;
use Illuminate\Contracts\Support\DeferrableProvider;

class ServiceProvider extends LavarelServiceProvider implements DeferrableProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/Config' => config_path()], 'nldou-aliyunoss-config');
        }
    }

    public function register()
    {
        $source = realpath(__DIR__.'/Config/oss.php');
        $this->mergeConfigFrom($source, 'oss');

        $this->app->singleton(OSS::class, function ($app) {
            $config = config('oss');
            $accessId  = $config['access_id'];
            $accessKey = $config['access_secret'];
            $bucket    = $config['bucket'];
            $ssl       = $config['ssl'];
            $debug     = $config['debug'];
            $endPoint  = $config['endpoint'];
            $endPointInternal = $config['endpointInternal'];
            // 生产环境SDK优先使用内网访问
            if ($app->environment('production')) {
                $clientEp = empty($endPointInternal) ? $endPoint : $endPointInternal;
            } else {
                $clientEp = $endPoint;
            }
            // SDK客户端
            $client  = new OssClient($accessId, $accessKey, $clientEp);
            // 是否使用https
            $client->setUseSSL = $ssl;

            return new OSS($client, $bucket, $endPoint, $endPointInternal, $ssl, $debug);
        });
        $this->app->alias(OSS::class, 'oss');
    }

    public function provides()
    {
        return [OSS::class, 'oss'];
    }
}