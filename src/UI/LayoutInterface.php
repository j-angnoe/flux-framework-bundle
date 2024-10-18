<?php

namespace Flux\Framework\UI;

use Stringable;
use Closure;

interface LayoutInterface {
    public function __invoke(string $content): string|Stringable;

    static function with(AddonInterface|Closure|string ...$addons): static;
}