<?php
namespace Tualo\Office\RemoteBrowser;
use Spatie\Browsershot\Browsershot;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\PUG\PUGRenderingHelper;
use DOMDocument;

class RemotePDF{
 
    public static function get(string $tablename,string $template,string $id,bool $getTitle=false):mixed{
        $db = App::get('session')->getDB();
        $localfilename = App::get('tempPath').'/'.$db->singleValue('select uuid() s',[],'s').'.pdf';
        $pollingInMilliseconds = 30;
        $timeoutInMilliseconds = 3000;

        $url = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].''.dirname($_SERVER['SCRIPT_NAME']) .''.$db->singleValue('select @sessionid s',[],'s').'/pugreporthtml/'.$tablename.'/'.$template.'/'.$id.'';
        if (!file_exists(App::get("basePath").'/cache/'.$db->dbname)){
            mkdir(App::get("basePath").'/cache/'.$db->dbname);
        }
        if (!file_exists(App::get("basePath").'/cache/'.$db->dbname.'/ds')){
            mkdir(App::get("basePath").'/cache/'.$db->dbname.'/ds');
        }
        $GLOBALS['pug_cache']=App::get("basePath").'/cache/'.$db->dbname.'/ds';
        $title = $db->singleValue('select uuid() s',[],'s');
            
        if($getTitle){
            PUGRenderingHelper::exportPUG($db);
            $html = PUGRenderingHelper::render([$id], $template, [
                'tablename'=>$tablename,
            ]);
            $dom = new DOMDocument();

            if($dom->loadHTML($html)) {
                $list = $dom->getElementsByTagName("title");
                if ($list->length > 0) {
                    $title = $list->item(0)->textContent;
                }
            }
        }

        $url = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].''.dirname($_SERVER['SCRIPT_NAME']) .''.$db->singleValue('select @sessionid s',[],'s').'/pugreporthtml/'.$tablename.'/'.$template.'/'.$id.'';
        // header('Content-type: application/pdf');
        Browsershot::url( $url )
            ->useCookies([@session_name() => @session_id()])
            ->showBackground()
            ->waitUntilNetworkIdle()
            ->format('A4')
            ->save( $localfilename );

        return [
            'filename'=>$localfilename,
            'title'=>$title,
            'contenttype'=>'application/pdf',
            'filesize'=>filesize($localfilename),
        ];
    }
}