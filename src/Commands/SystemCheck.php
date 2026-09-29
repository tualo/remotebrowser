<?php

namespace Tualo\Office\RemoteBrowser\Commands;

use Tualo\Office\Basic\SystemCheck as BasicSystemCheck;
use Tualo\Office\RemoteBrowser\Diagnostics;

class SystemCheck extends BasicSystemCheck
{
    private const COLORS = [
        Diagnostics::OK => 'green',
        Diagnostics::WARNING => 'yellow',
        Diagnostics::ERROR => 'red',
    ];

    public static function getModuleName(): string
    {
        return 'remotebrowser';
    }

    // unabhängig vom Mandanten, daher nur einmal als Session-Test
    public static function hasClientTest(): bool
    {
        return false;
    }

    public static function hasSessionTest(): bool
    {
        return true;
    }

    public static function testSessionDB(array $config): int
    {
        return self::test($config);
    }

    public static function test(array $config): int
    {
        $results = Diagnostics::run();
        foreach ($results as $result) {
            self::formatPrintLn([self::COLORS[$result['level']]], $result['check'] . ': ' . $result['message']);
        }
        return Diagnostics::hasErrors($results) ? 1 : 0;
    }
}
