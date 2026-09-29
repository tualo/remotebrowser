<?php

namespace Tualo\Office\RemoteBrowser\Routes;

use Tualo\Office\Basic\Route as BasicRoute;
use Tualo\Office\Basic\TualoApplication as App;
use Tualo\Office\RemoteBrowser\Diagnostics;

class Check extends \Tualo\Office\Basic\RouteWrapper
{
    public static function register()
    {
        BasicRoute::add('/remote/check', function () {
            $path = strtok($_SERVER['REQUEST_URI'], '?');
            $sampleUrl = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $path . '/sample';

            $results = Diagnostics::run($sampleUrl);
            $errors = array_values(array_filter($results, fn($r) => $r['level'] === Diagnostics::ERROR));

            App::contenttype('application/json');
            App::result('success', count($errors) === 0);
            App::result('errors', $errors);
            App::result('checks', $results);
        }, ['get'], false);

        BasicRoute::add('/remote/check/sample', function () {
            App::contenttype('text/html');
            App::body(Diagnostics::sampleHtml());
            BasicRoute::$finished = true;
        }, ['get'], false);
    }
}
