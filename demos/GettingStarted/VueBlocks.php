<?php

namespace Flux\Framework\Demos\GettingStarted;

use Flux\Framework\UI\Vue2SPA;
use Flux\Framework\UI\Vue2SPA\VueBlocksLayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VueBlocks extends AbstractController { 
    function __construct(
        private Vue2SPA $spa
    ) { 
        $spa->setup(new VueBlocksLayout);
    }

    #[Route("/flux/framework/demos/getting-started/vue-blocks")]
    function index(Request $request): Response{ 
        $vueBlocksFilesAndDirectories = [
            __FILE__,                    // To serve THIS file as vue blocks frontend.
            __DIR__ . '/some-directory', // To serve vue blocks files (.vue or .html) files from some directory
        ];

        return $this->spa->serveSPA(
            $this,                      // This object will be the 'Controller' for our UI
            $request,                   // We always need to pass Symfony/HttpFoundation/Request
            $vueBlocksFilesAndDirectories   // We tell Vue2SPA where all our vue-blocks are defined. 
                                            // it is flexible, you can give it an array of files and 
                                            // directories.
        );
    }

    // All PUBLIC methods will be accessible from javascript
    // via server.method_name
    public function perform_some_calculation($number1, $number2) { 
        return [
            'time_on_server' => date('Y-m-d H:i:s'),
            'your_number_1' => $number1,
            'your_number_2' => $number2,
            'the_sum_of_these' => ($number1 + $number2)
        ];
    }
}

// If you use __FILE__ (i.e. use yourself as vue-blocks source)
// You should add __halt_compiler()
__halt_compiler();
?>

<template url="/" props="">
    <div>
        <h1>This is Page 1 of our getting started vue-blocks demo</h1>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        async mounted() {
            
        }
    }
    </script>
</template>

<template url="/communicate-with-server">
    <div>
        <h1>We can communicate with the server</h1>

        Response from server:
        <pre v-text="myData"></pre>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        myData = null;
        async mounted() {
            this.myData = await server.perform_some_calculation(1,2);
        }
    }
    </script>
</template>