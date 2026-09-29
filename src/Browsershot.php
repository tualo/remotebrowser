<?php

namespace Tualo\Office\RemoteBrowser;

use Spatie\Browsershot\Browsershot as BaseBrowsershot;
use Spatie\Browsershot\Exceptions\FileUrlNotAllowed;

class Browsershot extends BaseBrowsershot
{
    public function setUrl(string $url): static
    {
        $url = trim($url);

        $parsedScheme = parse_url($url, PHP_URL_SCHEME);
        if ($parsedScheme === false) {
            throw FileUrlNotAllowed::urlCannotBeParsed($url);
        }

        foreach ($this->unsafeProtocols as $unsupportedProtocol) {
            if (str_starts_with(strtolower($url), $unsupportedProtocol)) {
                throw FileUrlNotAllowed::make();
            }
        }

        $this->url = $url;
        $this->html = '';

        return $this;
    }
}
