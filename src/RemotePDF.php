<?php

namespace Tualo\Office\RemoteBrowser;

use Spatie\Browsershot\Browsershot;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\PUG\PUGRenderingHelper;
use DOMDocument;
use GuzzleHttp\Client;

class RemotePDF
{

    public static function get(string $tablename, string $template, string $id, bool $getTitle = false): mixed
    {
        $db = App::get('session')->getDB();
        $localfilename = App::get('tempPath') . '/' . $db->singleValue('select uuid() s', [], 's') . '.pdf';
        $pollingInMilliseconds = 30;
        $timeoutInMilliseconds = 3000;
        $title = $db->singleValue('select uuid() s', [], 's');

        if (App::configuration('browsershot', 'remote_service', '') == '') {
            $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '' . dirname($_SERVER['SCRIPT_NAME']) . '' . $db->singleValue('select @sessionid s', [], 's') . '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id . '';
            if (!file_exists(App::get("basePath") . '/cache/' . $db->dbname)) {
                mkdir(App::get("basePath") . '/cache/' . $db->dbname);
            }
            if (!file_exists(App::get("basePath") . '/cache/' . $db->dbname . '/ds')) {
                mkdir(App::get("basePath") . '/cache/' . $db->dbname . '/ds');
            }
            $GLOBALS['pug_cache'] = App::get("basePath") . '/cache/' . $db->dbname . '/ds';


            PUGRenderingHelper::exportPUG($db);
            $html = PUGRenderingHelper::render([$id], $template, [
                'tablename' => $tablename,
            ]);
            $dom = new DOMDocument();

            if ($getTitle) {


                if ($dom->loadHTML($html)) {
                    $list = $dom->getElementsByTagName("title");
                    if ($list->length > 0) {
                        $title = $list->item(0)->textContent;
                    }
                }
            }
        }

        $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '' . dirname($_SERVER['SCRIPT_NAME']) . '' . $db->singleValue('select @sessionid s', [], 's') . '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id . '';


        $token = '';
        $session = App::get('session');


        if (isset($_SESSION['tualoapplication']['oauth'])) {

            $session = App::get('session');
            $token = $session->registerOAuth($params = ['cmp' => 'cmp_ds'], $force = true, $anyclient = false, $path = '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id);
            $session->oauthSingleUse($token);
            $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/~/' . $token . '' . $db->singleValue('select @sessionid s', [], 's') . '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id . '';
        }

        try {
            if (App::configuration('browsershot', 'remote_service', '') != '') {
                $client = new Client(
                    [
                        'base_uri' => App::configuration('browsershot', 'remote_service', ''),
                        'timeout'  => floatval(App::configuration('browsershot', 'remote_service_timeout', 3.0)),
                    ]
                );

                $cookie = @session_get_cookie_params();
                $cookie['name'] = @session_name();
                $cookie['value'] = @session_id();
                $cookie['domain'] = $_SERVER['HTTP_HOST'];

                $o = [
                    'url' => $url,
                    'cookies' => [$cookie],
                ];
                if (isset($_SESSION['tualoapplication']['oauth'])) {
                    $o = [
                        'url' => $url
                    ];
                }
                $response = $client->post('/pdf', [
                    'json' => $o
                ]);

                $code = $response->getStatusCode(); // 200
            } else {
                $code = 500;
            }
        } catch (\Exception $e) {
            $code = 500;
        }
        if ($code == 200) {
            $pdf = $response->getBody();
            file_put_contents($localfilename, $pdf);
        } else {

            //Browsershot::html($html)->newHeadless()->showBackground()->format('A4')->save( $localfilename );
            /*
            ->setNodeBinary('/usr/local/bin/node')
            ->setNpmBinary('/usr/local/bin/npm');
            */
            if (App::configuration('browsershot', 'useHeadless', '0') == '1') {

                if (!file_exists(App::get("tempPath") . '/chromium_cache/' . $db->dbname)) {
                    mkdir(App::get("tempPath") . '/chromium_cache/' . $db->dbname, 0777, true);
                }

                Browsershot::url($url)
                    ->setEnvironmentOptions(
                        [
                            'XDG_CONFIG_HOME' => App::get("tempPath") . '/chromium_cache/' . $db->dbname . '/.chromium',
                            'XDG_CACHE_HOME' => App::get("tempPath") . '/chromium_cache/' . $db->dbname . '/.chromium'
                        ]
                    )
                    ->newHeadless()
                    ->useCookies([@session_name() => @session_id()])
                    ->showBackground()
                    ->waitUntilNetworkIdle()
                    ->format('A4')
                    ->disableCaptureURLs()
                    ->save($localfilename);
            } else {
                Browsershot::url($url)
                    ->useCookies([@session_name() => @session_id()])
                    ->showBackground()
                    ->waitUntilNetworkIdle()
                    ->format('A4')
                    ->save($localfilename);
            }
        }

        if ($token != '') {
            $session->removeToken($token);
        }


        return [
            'filename' => $localfilename,
            'title' => $title,
            'contenttype' => 'application/pdf',
            'filesize' => filesize($localfilename),
        ];
    }
}
