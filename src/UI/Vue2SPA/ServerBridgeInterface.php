<?php

namespace Flux\Framework\UI\Vue2SPA;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface ServerBridgeInterface { 

    public function setController(object $controller): void;
    public function isDispatchRequest(Request $request): bool;
    public function dispatch(Request $request): Response;
    public function generateJavascriptClient(Request $request): string;
}