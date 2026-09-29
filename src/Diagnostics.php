<?php

namespace Tualo\Office\RemoteBrowser;

use GuzzleHttp\Client;
use Tualo\Office\Basic\TualoApplication as App;

/**
 * Prüft die typischen Fehlerquellen der PDF-Erzeugung per Browsershot.
 * Wird von der CLI (`./tm systemcheck`) und der Route `/remote/check` genutzt.
 */
class Diagnostics
{
    public const OK = 'ok';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const CACHE_NAME = '_systemcheck';

    /** @var array<int, array{level: string, check: string, message: string}> */
    private array $results = [];

    /**
     * @param string|null $sampleUrl zusätzlich diese URL rendern (prüft, ob der Server sich selbst erreicht)
     * @return array<int, array{level: string, check: string, message: string}>
     */
    public static function run(?string $sampleUrl = null): array
    {
        $d = new self();
        $d->checkProcOpen();
        $d->checkTempPath();
        $node = $d->checkBinary('node_binary', 'node');
        $npm = $d->checkBinary('npm_binary', 'npm', $node);
        if ($node !== null && $npm !== null) {
            $d->checkPuppeteer($node, $npm);
        }
        $d->checkChrome();
        $d->checkSandbox();
        $d->checkRemoteService();
        $d->checkRender('Render HTML', null);
        if ($sampleUrl !== null) {
            $d->checkRender('Render URL', $sampleUrl);
        }
        return $d->results;
    }

    public static function hasErrors(array $results): bool
    {
        foreach ($results as $result) {
            if ($result['level'] === self::ERROR) {
                return true;
            }
        }
        return false;
    }

    public static function sampleHtml(): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>RemoteBrowser Systemcheck</title>'
            . '<style>body{font-family:sans-serif;background:#eef;}h1{color:#336;}</style></head>'
            . '<body><h1>RemoteBrowser Systemcheck</h1><p>Wenn dieses PDF erzeugt wird, funktioniert Browsershot.</p></body></html>';
    }

    private function add(string $level, string $check, string $message): void
    {
        $this->results[] = ['level' => $level, 'check' => $check, 'message' => $message];
    }

    private function checkProcOpen(): void
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (!function_exists('proc_open') || in_array('proc_open', $disabled, true)) {
            $this->add(self::ERROR, 'proc_open', 'proc_open ist deaktiviert (disable_functions), Browsershot kann keine Prozesse starten.');
            return;
        }
        $this->add(self::OK, 'proc_open', 'verfügbar');
    }

    private function checkTempPath(): void
    {
        $tempPath = RemotePDF::tempPath();
        if (!is_dir($tempPath)) {
            $this->add(self::ERROR, 'tempPath', 'tempPath existiert nicht: "' . $tempPath . '"');
            return;
        }
        if (!is_writable($tempPath)) {
            $this->add(self::ERROR, 'tempPath', $tempPath . ' ist für den Benutzer "' . self::processUser() . '" nicht beschreibbar.');
            return;
        }
        $this->add(self::OK, 'tempPath', $tempPath);
    }

    /**
     * @return string|null Pfad der Binary, null wenn nicht nutzbar
     */
    private function checkBinary(string $key, string $name, ?string $node = null): ?string
    {
        $configured = RemotePDF::setting($key);
        if ($configured !== '') {
            if (!is_file($configured) || !is_executable($configured)) {
                $this->add(self::ERROR, $key, '"' . $configured . '" existiert nicht oder ist für "' . self::processUser() . '" nicht ausführbar.');
                return null;
            }
            $path = $configured;
        } else {
            $path = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($path === '') {
                $this->add(self::ERROR, $key, $name . ' nicht im PATH des PHP-Prozesses gefunden. Setzen mit: ./tm configuration --section browsershot --key ' . $key . ' --value $(which ' . $name . ')');
                return null;
            }
            // PATH unter php-fpm weicht oft von der Shell ab (z. B. nvm)
            $this->add(self::WARNING, $key, 'nicht konfiguriert, gefunden im PATH: ' . $path . ' – Konfiguration empfohlen.');
        }

        $result = self::exec($path, ['--version'], $node ?? $path);
        if ($result['code'] !== 0) {
            $this->add(self::ERROR, $key, $path . ' --version schlug fehl: ' . $result['output']);
            return null;
        }
        $this->add(self::OK, $key, $path . ' (' . $result['output'] . ')');
        return $path;
    }

    private function checkPuppeteer(string $node, string $npm): void
    {
        $result = self::exec($npm, ['root', '-g'], $node);
        $candidates = [(string)App::get('basePath') . '/node_modules/puppeteer'];
        if ($result['code'] === 0) {
            array_unshift($candidates, $result['output'] . '/puppeteer');
        }

        foreach ($candidates as $dir) {
            if (is_file($dir . '/package.json')) {
                $package = json_decode((string) file_get_contents($dir . '/package.json'), true);
                $this->add(self::OK, 'puppeteer', $dir . ' (' . ($package['version'] ?? '?') . ')');
                return;
            }
        }
        $this->add(self::ERROR, 'puppeteer', 'puppeteer nicht gefunden in ' . implode(', ', $candidates) . '. Installieren mit: npm install -g puppeteer');
    }

    private function checkChrome(): void
    {
        $chrome = RemotePDF::setting('chrome_path');
        if ($chrome === '') {
            $this->add(self::WARNING, 'chrome_path', 'nicht konfiguriert, Puppeteer nutzt seinen eigenen Chrome aus dem Cache von "' . self::processUser() . '" (~/.cache/puppeteer).');
            return;
        }
        if (!is_file($chrome) || !is_executable($chrome)) {
            $this->add(self::ERROR, 'chrome_path', '"' . $chrome . '" existiert nicht oder ist nicht ausführbar.');
            return;
        }
        $result = self::exec($chrome, ['--version']);
        $level = $result['code'] === 0 ? self::OK : self::WARNING;
        $this->add($level, 'chrome_path', $chrome . ' (' . $result['output'] . ')');
    }

    private function checkSandbox(): void
    {
        $noSandbox = RemotePDF::setting('noSandbox', '0') === '1';
        $isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
        if ($isRoot && !$noSandbox) {
            $this->add(self::ERROR, 'noSandbox', 'PHP läuft als root, Chrome startet dann nur mit noSandbox=1.');
            return;
        }
        $this->add(self::OK, 'noSandbox', $noSandbox ? 'aktiv' : 'inaktiv (Benutzer: ' . self::processUser() . ')');
    }

    private function checkRemoteService(): void
    {
        $service = RemotePDF::setting('remote_service');
        if ($service === '') {
            $this->add(self::OK, 'remote_service', 'nicht konfiguriert, es wird lokal gerendert.');
            return;
        }
        try {
            $client = new Client([
                'base_uri' => $service,
                'timeout' => floatval(RemotePDF::setting('remote_service_timeout', '3.0')),
                'http_errors' => false,
            ]);
            $client->get('/');
            $this->add(self::OK, 'remote_service', $service . ' erreichbar');
        } catch (\Throwable $e) {
            $this->add(self::WARNING, 'remote_service', $service . ' nicht erreichbar, es wird lokal gerendert: ' . $e->getMessage());
        }
    }

    private function checkRender(string $check, ?string $url): void
    {
        $filename = RemotePDF::tempPath() . '/remotebrowser_check_' . bin2hex(random_bytes(8)) . '.pdf';
        try {
            $browsershot = RemotePDF::browsershot($url, self::CACHE_NAME)->timeout(60);
            if ($url === null) {
                $browsershot->setHtml(self::sampleHtml());
            }
            $browsershot->save($filename);

            $header = is_file($filename) ? (string) file_get_contents($filename, false, null, 0, 4) : '';
            if ($header !== '%PDF') {
                $this->add(self::ERROR, $check, 'Browsershot lieferte kein gültiges PDF.');
                return;
            }
            $this->add(self::OK, $check, ($url ?? 'Musterseite') . ' gerendert (' . filesize($filename) . ' Bytes)');
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if ($url !== null) {
                $message .= ' – Hinweis: der Server muss sich selbst unter ' . parse_url($url, PHP_URL_HOST) . ' erreichen können.';
            }
            $this->add(self::ERROR, $check, $message);
        } finally {
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    /**
     * @param string|null $node Node-Binary, deren Verzeichnis dem PATH vorangestellt wird (npm benötigt node)
     * @return array{code: int, output: string}
     */
    private static function exec(string $binary, array $args, ?string $node = null): array
    {
        $command = escapeshellarg($binary) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        if ($node !== null) {
            $command = 'PATH=' . escapeshellarg(dirname($node) . ':' . getenv('PATH')) . ' ' . $command;
        }
        $output = [];
        $code = 0;
        exec($command, $output, $code);
        return ['code' => $code, 'output' => trim(implode("\n", $output))];
    }

    private static function processUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            return posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid();
        }
        return get_current_user();
    }
}
