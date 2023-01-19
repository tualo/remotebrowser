<?php

namespace Tualo\Office\RemoteBrowser\Routes;

use Spatie\Browsershot\Browsershot;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\IRoute;

class Route implements IRoute{

    public static function register(){

        
        BasicRoute::add('/remote/pdf/(?P<tablename>[\w\-\_]+)/(?P<template>[\w\-\_]+)/(?P<id>.+)',function($matches){

            $db = App::get('session')->getDB();
            $sessiondb = App::get('session')->db;

            try{
                // an image will be saved
                
                $localfilename = App::get('tempPath').'/'.$db->singleValue('select uuid() s',[],'s').'.pdf';
                $pollingInMilliseconds = 30;
                $timeoutInMilliseconds = 3000;

                $url = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].''.dirname($_SERVER['SCRIPT_NAME']) .'/_/'.$db->singleValue('select @sessionid s',[],'s').'/pugreporthtml/'.$matches['tablename'].'/'.$matches['template'].'/'.$matches['id'].'';
                header('Content-type: application/pdf');
                Browsershot::url( $url )
                    ->showBackground()
                    ->waitUntilNetworkIdle()
                    ->format('A4')
                    ->save( $localfilename );
                readfile($localfilename);
                unlink($localfilename);
                exit();       
            }catch(\Exception $e){
                App::result('last_sql', $db->last_sql );
                App::result('msg', $e->getMessage());
            }
            App::contenttype('application/json');
        },['get','post'],true);



    }
}
