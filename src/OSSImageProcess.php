<?php

namespace Nldou\AliyunOSS;

class OSSImageProcess
{
    protected $process;
    // 操作的有效参数
    const MAP_RESIZE_OPTIONS = [
        'mode' => 'm',
        'width' => 'w',
        'height' => 'h',
        'largeBorder' => 'l',
        'smallBorder' => 's',
        'oversizeProcess' => 'limit',
        'padColor' => 'color'
    ];

    const MAP_CROP_OPTIONS = [
        'posX' => 'x',
        'posY' => 'y',
        'width' => 'w',
        'height' => 'h',
        'origin' => 'g'
    ];
    const MAP_ORIGIN = [
        'nw',
        'north',
        'ne',
        'west',
        'center',
        'east',
        'sw',
        'south',
        'se'
    ];

    public function __construct()
    {
        $this->process = ['image'];
    }

    public function parseOptions($map, $options, $type)
    {
        $process[] = $type;
        foreach ( $map as $op => $v) {
            if (array_key_exists($op, $options)) {
                $process[] = $v.'_'.$options[$op];
            }
        }
        return implode(',', $process);
    }

    /**
     * 图片缩放
     * @param array $options 操作参数 key有效取值为[mode,width,height,largeBorder,smallBorder,oversizeProcess,padColor]
     */
    public function resize($options)
    {
        $process = $this->parseOptions(self::MAP_RESIZE_OPTIONS, $options, 'resize');
        $this->process[] = $process;
        return $this;
    }

    /**
     * 图片裁剪
     * @param array $options 操作参数 key有效取值为[posX,posY,width,height,origin]
     */
    public function crop($options)
    {
        // 保证原点参数是有效值
        if (isset($options['origin']) && !in_array($options['origin'], self::MAP_ORIGIN)) {
            unset($options['origin']);
        }
        $process = $this->parseOptions(self::MAP_CROP_OPTIONS, $options, 'crop');
        $this->process[] = $process;
        return $this;
    }

    /**
     * 图片裁剪-索引切割
     * @param int $horStep 水平切割间距 水平垂直切割只能两选一
     * @param int $verStep 垂直切割间距
     * @param int $index   取出指定索引的的图片
     */
    public function indexCrop($horStep, $verStep, $index = 0)
    {
        $process = 'indexcrop';
        if ($horStep > 0 && $verStep <= 0) {
            $process .= ',x_'.$horStep;
        }
        if ($verStep > 0 && $horStep <= 0) {
            $process .= ',y_'.$verStep;
        }
        if ($horStep > 0 && $verStep > 0) {
            $process .= ',x_'.$horStep;
        }
        $process .= ',i_'.$index;
        $this->process[] = $process;
    }

    /**
     * 图片裁剪-内切圆
     * @param int $r 半径 不能超过原图的最小边的一半
     */
    public function circle($r)
    {
        $this->process[] = 'circle,r_'.$r;
        return $this;
    }

    /**
     * 图片裁剪-圆角矩形
     * @param int $r 圆角半径 取值[1, 4096] 不能超过原图最小边的一半
     */
    public function roundedCorners($r)
    {
        $this->process[] = 'rounded-corners,r_'.$r;
        return $this;
    }

    /**
     * 图片旋转
     * @param int $value 图片按顺时针旋转的角度 取值[0, 360]
     */
    public function rotate($value)
    {
        $this->process[] = 'rotate,'.$value;
        return $this;
    }

    /**
     * 图片格式转换
     * 对于普通缩略请求，建议format参数放到处理参数串最后，例如：image/resize,w_100/format,jpg
     * 对于缩略+水印的请求，建议format参数跟缩略参数放在一起，例如：image/reisze,w_100/format,jpg/watermark,...
     * @param string $format 图片格式 取值[jpg,png,gif,bmp,tiff,webp]
     * @param int $interlace 渐进显示 只有在图片格式为jpg时有效 取值[0,1]
     * @param int $qulity 图片的绝对质量 只有在图片格式为jpg,webp时有效 取值[1,100]
     */
    public function format($format, $interlace = 1, $qulity = 90)
    {
        $format = strtolower($format);
        $this->process[] = 'format,'.$format;
        if ($format === 'jpg' && $interlace == 1) {
            $this->process[] = 'interlace,1';
        }
        if ($format === 'webp' || $format === 'jpg') {
            $this->process[] = 'quality,Q_'.$qulity;
        }
        return $this;
    }
    /**
     * 使用图片样式
     * @param string $style 样式名 可以在OSS控制台定义
     * @param mixed $definedSeperater 自定义分隔符 若为false则采用?x-oss-process=的参数形式
     */
    public static function style($style, $definedSeperater = false)
    {
        if (!empty($definedSeperater)) {
            return $definedSeperater.$style;
        } else {
            return '?x-oss-process='.$style;
        }
    }

    /**
     * 图片转存
     * 将处理后的图片保存到OSS
     * @param string $object 目标object名称
     * @param string $interlace 渐进显示 目标bucket名称 不指定默认保存到当前bucket
     */
    public function saveAs($object, $bucket = '')
    {
        $processStr = implode('/', $this->process);
        $this->process = ['image'];
        $object = base64_encode($object);
        $processStr .= '|sys/saveas,o_'.$object;
        if (!empty($bucket)) {
            $bucket = base64_encode($bucket);
            $processStr .= ',b_'.$bucket;
        }
        return $processStr;
    }

    public function get()
    {
        $processStr = implode('/', $this->process);
        $this->process = [];
        return $processStr;
    }
}