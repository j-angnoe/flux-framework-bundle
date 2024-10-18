<?php

namespace Flux\Framework\UI;

interface AddonInterface {
    public function __invoke(string $content): string;
    public function isCompatibleWith(object|string $parentLayoutObject): bool;
}