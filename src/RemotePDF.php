<?php

namespace Tualo\Office\RemoteBrowser;

use Spatie\Browsershot\Browsershot as BaseBrowsershot;
use Spatie\Browsershot\Exceptions\FileUrlNotAllowed;

use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\PUG\PUGRenderingHelper;
use DOMDocument;
use GuzzleHttp\Client;
use Tualo\Office\Basic\MYSQL\Database;



class RemotePDF
{
    private static ?Database $db;

    public static function db(): Database
    {
        if (!self::$db) {
            self::$db = App::get('session')->getDB();
        }
        return self::$db;
    }
    public static function tempPath(): string
    {
        return (string)App::get('tempPath');
    }

    public static function basePath(): string
    {
        return (string)App::get('basePath');
    }

    public static function config(string $url): Browsershot
    {
        if (!file_exists(self::tempPath() . '/chromium_cache/' . self::db()->dbname)) {
            mkdir(self::tempPath() . '/chromium_cache/' . self::db()->dbname, 0777, true);
        }

        $browsershot = Browsershot::url($url);
        if (App::configuration('browsershot', 'noSandbox', '0') == '1') {
            $browsershot->noSandbox();
        }
        $browsershot->setEnvironmentOptions(
            [
                'XDG_CONFIG_HOME' => self::tempPath() . '/chromium_cache/' . self::db()->dbname . '/.chromium',
                'XDG_CACHE_HOME' => self::tempPath() . '/chromium_cache/' . self::db()->dbname . '/.chromium',
            ]
        );

        if (App::configuration('browsershot', 'chrome_path')) $browsershot->setChromePath(App::configuration('browsershot', 'chrome_path'));

        if (App::configuration('browsershot', 'node_binary')) {
            $browsershot->setNodeBinary(App::configuration('browsershot', 'node_binary'));
        }
        if (App::configuration('browsershot', 'npm_binary')) {
            $browsershot->setNpmBinary(App::configuration('browsershot', 'npm_binary'));
        }
        if (App::configuration('browsershot', 'useHeadless', '0') == '1') {
            $browsershot->newHeadless();
        }

        $browsershot
            ->preventUnsuccessfulResponse()
            ->showBackground()
            ->waitUntilNetworkIdle()
            ->format('A4')
            ->margins(5, 5, 5, 5)
            ->disableCaptureURLs();

        return $browsershot;
    }


    private static function fallback(string $tablename, string $template, string $id, bool $getTitle = false): array
    {
        $result = [
            'title' => '',
            'html' => '',
        ];
        if (!file_exists(self::basePath() . '/cache/' . self::db()->dbname)) {
            mkdir(self::basePath() . '/cache/' . self::db()->dbname);
        }
        if (!file_exists(self::basePath() . '/cache/' . self::db()->dbname . '/ds')) {
            mkdir(self::basePath() . '/cache/' . self::db()->dbname . '/ds');
        }
        $GLOBALS['pug_cache'] = self::basePath() . '/cache/' . self::db()->dbname . '/ds';



        PUGRenderingHelper::exportPUG(self::db());
        $html = PUGRenderingHelper::render([$id], $template, [
            'tablename' => $tablename,
        ]);
        $dom = new DOMDocument();

        if ($getTitle) {


            if ($dom->loadHTML($html)) {
                $list = $dom->getElementsByTagName("title");
                if ($list->length > 0) {
                    $result['title'] = $list->item(0)->textContent;
                }
            }
        }
        $result['html'] = $html;
        return $result;
    }

    private static function remoteService(string $url): array
    {
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
        $body = $response->getBody()->getContents();
        return [
            'code' => $code,
            'body' => $body
        ];
    }

    public static function get(string $tablename, string $template, string $id, bool $getTitle = false): mixed
    {
        $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '' . dirname($_SERVER['SCRIPT_NAME']) . '' . self::db()->singleValue('select @sessionid s', [], 's') . '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id . '';

        $db = App::get('session')->getDB();
        $localfilename = self::tempPath() . '/' . $db->singleValue('select uuid() s', [], 's') . '.pdf';
        $pollingInMilliseconds = 30;
        $timeoutInMilliseconds = 3000;
        $title = $db->singleValue('select uuid() s', [], 's');

        if (App::configuration('browsershot', 'use', '') == '') {
            $fallbackResult = self::fallback($tablename, $template, $id, $getTitle);
            $title = $fallbackResult['title'];
            $html = $fallbackResult['html'];
        }

        $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '' . dirname($_SERVER['SCRIPT_NAME']) . '' . $db->singleValue('select @sessionid s', [], 's') . '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id . '';


        $token = '';
        $session = App::get('session');


        if (isset($_SESSION['tualoapplication']['oauth'])) {
            // falls über auth token, muss ein neuer Token registriert und für den einmaligen Gebrauch vorbereitet werden
            $session = App::get('session');
            $token = $session->registerOAuth(
                $force = true,
                $anyclient = false,
                $path = '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id,
                $name = 'RemotePDF Generation',
                $device = 'Server',
            );
            $session->oauthSingleUse($token);
            $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/~/' . $token . '' . $db->singleValue('select @sessionid s', [], 's') . '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id . '';
        }

        // the browser requests the page with our session cookie; keeping the lock would deadlock
        $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($sessionWasActive) {
            session_write_close();
        }

        try {
            if (App::configuration('browsershot', 'remote_service', '') != '') {
                $remoteServiceResult = self::remoteService($url);
                $code = $remoteServiceResult['code'];
                $response = new \GuzzleHttp\Psr7\Response($code, [], $remoteServiceResult['body']);
                if ($code == 200) {
                    $pdf = $response->getBody();
                    file_put_contents($localfilename, $pdf);
                }
            } else {
                $browsershot = self::config($url);
                $browsershot->save($localfilename);
            }
        } finally {
            if ($sessionWasActive) {
                @session_start();
            }

            if ($token != '') {
                $session->removeToken($token);
            }
        }


        return [
            'filename' => $localfilename,
            'title' => $title,
            'contenttype' => 'application/pdf',
            'filesize' => filesize($localfilename),
        ];
    }
}
