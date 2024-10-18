<?php

namespace Flux\Framework\Demos\GettingStarted;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class Controller extends AbstractController { 
    #[Route("/flux/framework/demos/getting-started")]
    function index() { 
        return new Response('<h1>It works!</h1>');
    }
}