<?php

namespace Flux\Framework\Demos\VueHarnessDemo;

use Flux\Framework\UI\Vue2SPA;
use Flux\Framework\UI\Vue2SPA\FontAwesome4Addon;
use Flux\Framework\UI\Vue2SPA\VueBlocksLayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Routing\Annotation\Route;

class Controller extends AbstractController { 
    public function __construct(
        private Vue2SPA $spa,
        private ParameterBagInterface $parameterBag
    )
    {
        $spa->setup(VueBlocksLayout::with(
            // Bootstrap4Addon::class,
            FontAwesome4Addon::class,
            function (string $content): string {
                // $content = HtmlUtils::removeLine('dist/vue-blocks\.js', $content);
                // $content = HtmlUtils::append('head','<link href="vue-harness/dist/vue-harness.css" rel="stylesheet">', $content);
                // $content = HtmlUtils::append('head','<script src="vue-harness/dist/vue-harness.js">', $content);
                return $content;
            }
        ));
    }   

    #[Route("/flux/framework/demos/vue-harness")]
    function view_homepage(Request $request): Response {
        return $this->spa->serveSPA($this, $request, __FILE__);
    }

    #[Route("/flux/framework/demos/vue-harness/with-vue-files")]
    function experience_vue_ui(Request $request): Response {
        return $this->spa->serveSPA($this, $request, __DIR__ . '/ui');
    }


    #[Route("/flux/framework/demos/vue-harness/vue-harness/dist/{filename}")]
    #[Route("/flux/framework/vue-harness/with-vue-files/vue-harness/dist/{filename}")]
    function serve_dist(string $filename) { 
        $path = $this->getParameter('kernel.project_dir') . '/legacy/_layers/vue-harness/dist/' . $filename;

        // Create a BinaryFileResponse
        $response = new BinaryFileResponse($path);

        // Automatically guess the MIME type based on the file extension

        // @Fixme - Mimetypes is still an issue
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $mimeType = match($ext) {   
            'txt' => 'text/plain',
            'xsd' => 'text/plain',
            'avi' => 'video/avi',
            'bmp' => 'image/bmp',
            'css' => 'text/css',
            'gif' => 'image/gif',
            'htm' => 'text/html',
            'html' => 'text/html',
            'htmls' => 'text/html',
            'ico' => 'image/x-ico',
            'jpe' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'js' => 'application/x-javascript',
            'midi' => 'audio/midi',
            'mid' => 'audio/midi',
            'mod' => 'audio/mod',
            'mov' => 'movie/quicktime',
            'mp3' => 'audio/mp3',
            'mpg' => 'video/mpeg',
            'mpeg' => 'video/mpeg',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'swf' => 'application/shockwave-flash',
            'tif' => 'image/tiff',
            'tiff' => 'image/tiff',
            'wav' => 'audio/wav',
            'xbm' => 'image/xbm',
            'xml' => 'text/xml',
            default => throw new \Exception('Could not determine mime-type for extension `'.$ext.'`')
        };

        $response->headers->set('Content-Type', $mimeType);

        return $response;
    }

    public function getSomeData() { 
        return [
            'time' => 'time on server: ' . date('Y-m-d H:i:s')
        ];
    }

    public function myMethod($a, $b, $c) { 
        return [
            'received args' => [$a, $b, $c],
            'sum of args' => $a+$b+$c
        ];
    }

    public function raisesException() { 
        throw new \Exception('This is an intended exception to test the exception handling');
    }

    public function viewParameters() { 
        return [
            'all_parameters' => $this->parameterBag->get('kernel.environment')
        ];
    }
}
__halt_compiler();
?>

<template url="/">
    <div>Welcome to vue-harness demo, click on a sub page to view more.</div>
</template>

<template url="/symfony-parameters">
    <div>
        <pre v-text="$data"></pre>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        parameters = null;
        async mounted() {
            this.parameters = await server.viewParameters();
        }
    }
    </script>
</template>
<template url="/io-with-controller">
    <div>
        <i class="fa fa-home"></i>

        Calling myMethod on controller with arguments 1,2,3

        Result:
        <pre v-text="result"></pre>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        result = null;
        async mounted() {
            this.result = await server.myMethod(1,2,3);
        }
    }
    </script>
</template>

<template url="/exceptions">
    <div>
        
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        async mounted() {
            await server.raisesException();
        }
    }
    </script>
</template>
<template url="/throughput-benchmark">
    <div>
        <h1>Throughput benchmark</h1>

        <div>
            Data from server: 
            <pre v-text="data"></pre>
        </div>

        <div v-if="elapsed > 0">
            Elapsed: {{ elapsed }} ms.<br>
            Requests per second: {{ rps }}<br>
        </div>

        <pre v-text="$data"></pre>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        data = null;
        elapsed = -1;
        rps = -1;

        async mounted() {

            for (var j = 0; j < 10; j++) { 
                var start = Date.now();
                var num_requests = 50;

                for(var i = 0; i < num_requests; i++) { 
                    this.data = await server.getSomeData();
                    if (!document.contains(this.$el)) { 
                        return;
                    }
                }
                var end = Date.now();

                var requestsPerSecond = num_requests / ((end - start) / 1000);

                this.rps = requestsPerSecond;
                this.elapsed = end - start;
            }            
        }
    }
    </script>
</template>