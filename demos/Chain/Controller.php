<?php

namespace Flux\Framework\Demos\Chain;

use Flux\Framework\Chain\BackgroundCommand;
use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\Shell;
use Flux\Framework\UI\Vue2SPA;
use Flux\Framework\UI\Vue2SPA\VueBlocksLayout;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class Controller extends AbstractController { 
    public function __construct(
        private Vue2SPA $spa,
    )
    {
        $spa->setup(VueBlocksLayout::with());
        $spa->resolveArgument(BackgroundCommand::class, function(string|array $token) { 
            $token = $token['backgroundCommandId'] ?? $token;

            if (!is_string($token) || !$token) { 
                throw new \Exception('Cannot create background-command from `'.json_encode($token).'`');
            }

            if (!preg_match('~^[a-z0-9]{4}(-[a-z0-9]{4}){3}$~i', $token)) {
                throw new \Exception('Invalid background token`'.json_encode($token).'`');
            }

            return new BackgroundCommand($token);
        });
    }   

    #[Route("/flux/framework/demos/chain")]
    function serve(Request $request) { 
        return $this->spa->serveSPA($this, $request, __FILE__);
    }

    function startLargeBackgroundJob() { 
        $shell = new Shell('php -r', <<<'PHP'
        for($i = 0; $i < 25_000; $i++) { 
            echo $i . "\n";
        }
        PHP);
        return $shell->dispatchBackgroundCommand();
    }

    function startIntermittentBackgroundJob() { 
        $shell = new Shell('php -r', <<<'PHP'
        for($i = 0; $i < 1_000; $i++) { 
            echo $i . "\n";
            if (rand(1,10) === 1) { 
                echo "Take a break\n";
            }
        }
        PHP);
        return $shell->dispatchBackgroundCommand();
    }


    function trackCommand(BackgroundCommand $command) { 

        $numLines = 0;

        
        $command->simpleEventStream(function ($line) use (&$numLines) {
            echo "retry: 1000\n\n";

            $numLines++;
            if ($numLines > 20 && $line === 'Take a break') { 
                exit;
            }
        });
        
        exit;
    }
}
__halt_compiler();
?>
<template url="/large-job" props="">
    <div>
        <h1>A large jobs</h1>
        <p>Outputs 10_000 lines without delay. It should not overload the browser tab.</p>

        <visualize-framerate></visualize-framerate>
        
        <simple-bg-tracker v-if="bg" :token="bg">
        </simple-bg-tracker>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        bg = null;
        async mounted() {
            this.bg = await server.startLargeBackgroundJob();
        }
    }
    </script>
</template>

<template url="/resumable-job" props="">
    <div>
        <h1>Resumable job</h1>
        <p>Here we simulate loss of connection. The browser should resume from last known position</p>
        <simple-bg-tracker v-if="bg" :token="bg">
        </simple-bg-tracker>
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        bg = null;
        async mounted() {            
            this.bg = await server.startIntermittentBackgroundJob();
        }
    }
    </script>
</template>


<template component="simple-bg-tracker" props="token">
    <div>
        Simple tracker
        <hr>
        Running: {{ running ? 'yes' : 'no' }}
        <hr>
        Number of messages {{ messages.length }}
        <pre v-text="messages.slice(-25).join('\n')"></pre>

        <!-- <debug></debug> -->
    </div>
    <style scoped>
    </style>
    <script>
    return class vue {
        messages = []
        running = true;
        async mounted() {
            await wait(2000);

            this.es = server.trackCommand.eventStream(this.token);

            // Very slow, blocks the thread:
            // this.es.originalAddEventListener('message', event => {
            //     this.messages.push(event.data);
            // })

            // This is better. It is a slower, more gradual flood of events
            // without blocking the thread.
            // this.es.addEventListener('message', event => {
            //     this.messages.push(event.data);
            // })

            // This is most efficient:

            this.es.addEventListener('batch', batch => {
                this.messages.push(...batch);
            })

            this.es.addEventListener('finished', event => {
                this.running = false;
            })
        }
        async unmounted() {
            this.es?.close();
        }

    }
    </script>
</template>


<template component="visualize-framerate" props="">
    <div>
        <h5>Approximate frame-rates</h5>
        <p>higher frame-rate is better, 0 frame-rate means the page is unresponsive</p>
        <div class="fps-container">
           <div 
                class="fps-bar" 
                :style="{height: `${Math.round((fps[f] ?? 0) / 1)}%`}"
                v-for="f in lastSeconds"
            > {{ Math.round((fps[f] ?? 0) / 1) }} </div>
        </div>       
    </div>
    <style scoped>
    .fps-container { 
        height: 100px;
        display: flex;
        align-items: flex-end;
    }
    .fps-container .fps-bar { 
        width: 30px;
        margin: 3px;
        background-color: red;
        height: 50px;
    }        
    </style>
    <script>
    return class vue {
        fps = {};
        frameRate = 0;
        lastBleep = null;
        second = null;

        computed = {
            lastSeconds() { 
                var seconds = [];
                var now = Date.now();
                this.second ??= Math.round(now/1000);

                for(var i=-30; i <= 0; i++) { 
                    seconds.push(this.second + i);
                }

                return seconds;
            }
        }

        async mounted() {
            this.startFrameRateLoop();
        }

        startFrameRateLoop() {
            this.determineFrameRate();
            window.requestAnimationFrame(this.startFrameRateLoop);
        }

        determineFrameRate() {
            var now = Date.now();
            this.second = Math.round(now/1000);
            if (this.lastBleep) {
                var elapsed = now - this.lastBleep;
                this.frameRate = (1000/elapsed).toFixed(0)
                this.fps[this.second] ??= 0;
                this.fps[this.second] += 1;
                delete this.fps[this.second-60];
            }
            this.lastBleep = now;
        }        
    }
    </script>
</template>