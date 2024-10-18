<?php

namespace Flux\Framework\UI\Vue2SPA;

use Flux\Framework\UI\AddonInterface;
use Flux\Framework\UI\HtmlUtils;

class Bootstrap4Addon extends AlwaysCompatibleAddon implements AddonInterface { 
    function __invoke(string $content): string { 
        return HtmlUtils::append('head', '<link rel="stylesheet" href="https://unpkg.com/bootstrap@4.5.3/dist/css/bootstrap.min.css" crossorigin="anonymous">', $content);
    }
}