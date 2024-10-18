<?php

namespace Flux\Framework\UI\Vue2SPA;

use Flux\Framework\UI\AddonInterface;

abstract class AlwaysCompatibleAddon implements AddonInterface { 
    function isCompatibleWith(object|string $parentLayoutObject): bool
    {
        return true;
    }
}