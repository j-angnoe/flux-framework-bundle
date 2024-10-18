<?php

namespace Flux\Framework\UI\Vue2SPA;

use Flux\Framework\UI\AddonInterface;
use Flux\Framework\UI\HtmlUtils;

class FontAwesome4Addon extends AlwaysCompatibleAddon implements AddonInterface { 
    function __invoke(string $content): string { 
        return HtmlUtils::append('head', '<link rel="stylesheet" href="https://unpkg.com/font-awesome@4.7.0/css/font-awesome.css" crossorigin="anonymous">', $content);
    }
}