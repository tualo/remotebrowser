<?php

namespace Tualo\Office\RemoteBrowser\CMSMiddleware;

use Tualo\Office\RemoteBrowser\RemotePDF;

class PDF
{

    public static function pdf(): callable
    {
        return function (string $table, string $template, string $id): string {
            return $res = RemotePDF::get($table, $template, $id);
        };
    }



    public static function run(&$request, &$result)
    {
        $result['pdf'] = self::pdf();
    }
}
