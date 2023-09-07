<?php

namespace Tualo\Office\RemoteBrowser\Routes;

use Spatie\Browsershot\Browsershot;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;
use Tualo\Office\RemoteBrowser\RemotePDF;
class Route implements IRoute{

    public static function register(){

        
        BasicRoute::add('/remote/pdf/(?P<tablename>[\w\-\_]+)/(?P<template>[\w\-\_]+)/(?P<id>.+)',function($matches){

            $db = App::get('session')->getDB();
            $sessiondb = App::get('session')->db;

            try{
                $res = RemotePDF::get($matches['tablename'],$matches['template'],$matches['id']);
                if (isset($res['filename'])){
                    readfile($res['filename']);
                    unlink($res['filename']);
                }
                exit();       
            }catch(\Exception $e){
                App::result('last_sql', $db->last_sql );
                App::result('msg', $e->getMessage());
            }
            App::contenttype('application/json');
        },['get','post'],true);



    }
}
