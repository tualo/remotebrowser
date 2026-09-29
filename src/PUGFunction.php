<?php

namespace Tualo\Office\RemoteBrowser;

use Tualo\Office\PUG\IPUGFunction;
use Tualo\Office\RemoteBrowser\RemotePDF;

class PUGFunction implements IPUGFunction
{

    public static function register()
    {

        return [
            'pug_name' => 'remotebrowser',
            'function' => self::fn()
        ];
    }

    public static function fn(): mixed
    {
        return function (string $tablename, string $template, string $id, bool $getTitle = false): string {
            $res = RemotePDF::get($tablename, $template, $id, $getTitle);
            $data = file_get_contents($res['filename']);
            unlink($res['filename']);
            return $data;
        };
    }
}
