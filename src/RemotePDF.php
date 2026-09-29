<?php

namespace Tualo\Office\RemoteBrowser;

use DOMDocument;
use GuzzleHttp\Client;
use Tualo\Office\Basic\MYSQL\Database;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\PUG\PUGRenderingHelper;

/**
 * Erzeugt ein PDF aus einem PUG-Report, indem die Route
 * `/pugreporthtml/{tablename}/{template}/{id}` in einem Browser aufgerufen wird.
 *
 * Ablauf:
 *  1. Optional: Report lokal rendern, um den `<title>` zu ermitteln (siehe `use`).
 *  2. Bei OAuth-Sitzungen ein Einmal-Token für die Report-URL registrieren.
 *  3. Session-Lock freigeben, damit der Browser die Seite mit derselben Session laden kann.
 *  4. PDF über den Remote-Service erzeugen; schlägt das fehl, lokal per Browsershot.
 *  5. Session wieder öffnen und Einmal-Token entfernen.
 *
 * Einstellungen (Sektion `browsershot`, z. B. `./tm configuration --section browsershot --key <key> --value <value>`):
 *
 * | Key                      | Default | Beschreibung                                                                 |
 * |--------------------------|---------|------------------------------------------------------------------------------|
 * | `use`                    | `''`    | Leer: Report wird vorab lokal gerendert (PUG-Export, Titelermittlung).       |
 * |                          |         | Beliebiger Wert: lokales Vorab-Rendering wird übersprungen.                  |
 * | `remote_service`         | `''`    | Basis-URL eines externen PDF-Dienstes (`POST /pdf` mit `{url, cookies}`).    |
 * |                          |         | Leer: es wird direkt lokal per Browsershot gerendert.                        |
 * | `remote_service_timeout` | `3.0`   | Timeout für den Remote-Service in Sekunden.                                  |
 * | `chrome_path`            | –       | Pfad zur Chrome/Chromium-Binary, z. B. `$(which chromium)`.                  |
 * | `node_binary`            | –       | Pfad zur Node-Binary, z. B. `$(which node)`.                                 |
 * | `npm_binary`             | –       | Pfad zur npm-Binary, z. B. `$(which npm)`.                                   |
 * | `noSandbox`              | `0`     | `1`: Chrome mit `--no-sandbox` starten (nötig in Docker / als root).         |
 * | `useHeadless`            | `0`     | `1`: neuen Headless-Modus von Chrome verwenden.                              |
 */
class RemotePDF
{
    private const CONFIG_SECTION = 'browsershot';

    private static ?Database $db = null;

    /**
     * @return array{filename: string, title: string, contenttype: string, filesize: int|false}
     */
    public static function get(string $tablename, string $template, string $id, bool $getTitle = false): mixed
    {
        $db = self::db();
        $reportPath = '/pugreporthtml/' . $tablename . '/' . $template . '/' . $id;
        $localfilename = self::tempPath() . '/' . $db->singleValue('select uuid() s', [], 's') . '.pdf';
        $title = $db->singleValue('select uuid() s', [], 's');

        if (self::setting('use') === '') {
            $renderedTitle = self::renderLocally($tablename, $template, $id, $getTitle);
            if ($renderedTitle !== '') {
                $title = $renderedTitle;
            }
        }

        $session = App::get('session');
        $token = '';
        if (isset($_SESSION['tualoapplication']['oauth'])) {
            // OAuth-Sitzungen haben kein Session-Cookie für den Browser, daher ein Einmal-Token
            // positional only: registerOAuth(...$args) does not support named arguments
            $token = $session->registerOAuth(
                true,               // force
                false,              // anyclient
                $reportPath,
                'RemotePDF Generation',
                'Server',
            );
            $session->oauthSingleUse($token);
        }
        $url = self::reportUrl($reportPath, $token);

        // the browser requests the page with our session cookie; keeping the lock would deadlock
        $sessionWasActive = session_status() === PHP_SESSION_ACTIVE;
        if ($sessionWasActive) {
            session_write_close();
        }

        try {
            self::renderPdf($url, $token, $localfilename);
        } finally {
            if ($sessionWasActive) {
                @session_start();
            }
            if ($token !== '') {
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

    private static function renderPdf(string $url, string $token, string $filename): void
    {
        if (self::setting('remote_service') !== '' && self::renderViaRemoteService($url, $filename)) {
            return;
        }

        $browsershot = self::browsershot($url);
        if ($token === '') {
            $browsershot->useCookies([session_name() => session_id()]);
        }
        $browsershot->save($filename);
    }

    /**
     * @return bool false, wenn der Dienst nicht erreichbar ist oder kein 200 liefert
     */
    private static function renderViaRemoteService(string $url, string $filename): bool
    {
        $client = new Client([
            'base_uri' => self::setting('remote_service'),
            'timeout'  => floatval(self::setting('remote_service_timeout', '3.0')),
        ]);

        $payload = ['url' => $url];
        if (!isset($_SESSION['tualoapplication']['oauth'])) {
            $cookie = session_get_cookie_params();
            $cookie['name'] = session_name();
            $cookie['value'] = session_id();
            $cookie['domain'] = $_SERVER['HTTP_HOST'];
            $payload['cookies'] = [$cookie];
        }

        try {
            $response = $client->post('/pdf', ['json' => $payload]);
        } catch (\Exception $e) {
            return false;
        }
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        file_put_contents($filename, $response->getBody()->getContents());
        return true;
    }

    /**
     * @param string|null $url      null: Seite wird später per setHtml() gesetzt
     * @param string|null $cacheName Unterverzeichnis des Chromium-Caches, Default: Mandanten-DB
     */
    public static function browsershot(?string $url, ?string $cacheName = null): Browsershot
    {
        $chromiumDir = self::tempPath() . '/chromium_cache/' . ($cacheName ?? self::db()->dbname);
        if (!file_exists($chromiumDir)) {
            mkdir($chromiumDir, 0777, true);
        }

        $browsershot = (is_null($url) ? new Browsershot() : Browsershot::url($url))->setEnvironmentOptions([
            'XDG_CONFIG_HOME' => $chromiumDir . '/.chromium',
            'XDG_CACHE_HOME' => $chromiumDir . '/.chromium',
        ]);

        if (self::setting('chrome_path') !== '') {
            $browsershot->setChromePath(self::setting('chrome_path'));
        }
        if (self::setting('node_binary') !== '') {
            $browsershot->setNodeBinary(self::setting('node_binary'));
        }
        if (self::setting('npm_binary') !== '') {
            $browsershot->setNpmBinary(self::setting('npm_binary'));
        }
        if (self::setting('noSandbox', '0') === '1') {
            $browsershot->noSandbox();
        }
        if (self::setting('useHeadless', '0') === '1') {
            $browsershot->newHeadless();
        }

        return $browsershot
            ->preventUnsuccessfulResponse()
            ->showBackground()
            ->waitUntilNetworkIdle()
            ->format('A4')
            ->margins(5, 5, 5, 5)
            ->disableCaptureURLs();
    }

    /**
     * Exportiert die PUG-Templates und rendert den Report lokal.
     *
     * @return string der `<title>` des Reports, sofern `$getTitle` gesetzt ist, sonst ''
     */
    private static function renderLocally(string $tablename, string $template, string $id, bool $getTitle): string
    {
        $cacheDir = self::basePath() . '/cache/' . self::db()->dbname . '/ds';
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        $GLOBALS['pug_cache'] = $cacheDir;

        PUGRenderingHelper::exportPUG(self::db());
        $html = PUGRenderingHelper::render([$id], $template, ['tablename' => $tablename]);

        if (!$getTitle) {
            return '';
        }
        $dom = new DOMDocument();
        if (@$dom->loadHTML($html)) {
            $list = $dom->getElementsByTagName('title');
            if ($list->length > 0) {
                return $list->item(0)->textContent;
            }
        }
        return '';
    }

    private static function reportUrl(string $reportPath, string $token): string
    {
        $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']);
        if ($token !== '') {
            $url .= '/~/' . $token;
        }
        return $url . self::db()->singleValue('select @sessionid s', [], 's') . $reportPath;
    }

    public static function setting(string $key, string $default = ''): string
    {
        return (string) App::configuration(self::CONFIG_SECTION, $key, $default);
    }

    private static function db(): Database
    {
        if (self::$db === null) {
            self::$db = App::get('session')->getDB();
        }
        return self::$db;
    }

    // tempPath is only set for logged-in sessions (bsc session/temp middleware)
    public static function tempPath(): string
    {
        $tempPath = (string) App::get('tempPath');
        if ($tempPath === '') {
            $tempPath = self::basePath() . '/temp';
            if (!file_exists($tempPath)) {
                @mkdir($tempPath, 0777, true);
            }
        }
        return $tempPath;
    }

    public static function basePath(): string
    {
        return (string) App::get('basePath');
    }
}
