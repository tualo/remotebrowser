<?php

namespace Tualo\Office\RemoteBrowser\Routes;

use Spatie\Browsershot\Browsershot;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Tualo\Office\RemoteBrowser\RemotePDF;

class Route implements IRoute
{

    public static function register()
    {


        BasicRoute::add('/remote/pdf/(?P<tablename>[\w\-\_]+)/(?P<template>[\w\-\_]+)/(?P<id>.+)', function ($matches) {

            $db = App::get('session')->getDB();
            $sessiondb = App::get('session')->db;
            try {
                $res = RemotePDF::get($matches['tablename'], $matches['template'], $matches['id']);
                if (isset($res['filename'])) {
                    header('Content-type: application/pdf');
                    header('Content-Disposition: inline; filename="' . $matches['id'] . '.pdf"');
                    header('Content-Transfer-Encoding: binary');
                    header('Access-Control-Allow-Headers: Content-Type, Authorization');
                    header('Access-Control-Allow-Credentials: true');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    header('Expires: 0');
                    header('Content-Length: ' . filesize($res['filename']));
                    readfile($res['filename']);
                    unlink($res['filename']);
                }
                exit();
            } catch (\Exception $e) {
                App::result('last_sql', $db->last_sql);
                App::result('msg', $e->getMessage());
            }
            App::contenttype('application/json');
        }, ['get', 'post'], true);
    }
}
