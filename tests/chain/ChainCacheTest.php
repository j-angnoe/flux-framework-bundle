<?php

use Flux\Framework\Chain\Chain;
use PHPUnit\Framework\TestCase;

if (!function_exists('chain')) { 
    function chain(...$args): Chain { 
        return new Chain(...$args);
    }   
}
class ChainCacheTest extends TestCase { 
    private float $__startTime;

    private function startTimer(): void { 
        $this->__startTime = microtime(true);
    }
    private function getTheTime(): float { 
        return microtime(true) - $this->__startTime;
    }

    private function assertItWasSlow() { 
        return $this->assertTrue($this->getTheTime() > 0.9);
    }
    private function assertItWasFast() { 
        return $this->assertTrue($this->getTheTime() < 0.1);
    }

    private function getCacheSource(array $cacheOptions = [], bool $refreshCache = false) { 
        if ($refreshCache) { 
            // only refresh cache to force re-running.
            $cacheOptions['refreshCache'] = true;
        }


        return chain(array_map(fn($x)=>['id'=>$x], [1,2,3]))
            ->map(function($x) {
                usleep(333 * 1000);
                return $x;
            })
            ->cache('5m', $cacheOptions);
    }


    function testCachingCapabilities() { 
        $this->startTimer();

        $myCacheId = __METHOD__ . uniqid();

        // First it is slow:

        $result = $this->getCacheSource(['id' => $myCacheId], true)
            ->toArray();

        $this->assertEquals([['id' => 1],['id' => 2],['id' => 3]], $result);
        $this->assertItWasSlow();


        // You can get the same cache by supplying the same ID/cache parameters.
        $this->startTimer();
        $result = chain(null)->cache('5m', ['id' => $myCacheId])->toArray();
        $this->assertEquals([['id' => 1],['id' => 2],['id' => 3]], $result);

        $this->assertItWasFast();
    }

    function testAnonymousCacheIsRecognizedByStacktrace() {
        $this->startTimer();
        $this->getCacheSource([], true)->run();
        $this->assertItWasSlow();
        
        $this->startTimer();
        $this->getCacheSource([], false)->run();
        $this->assertItWasFast();
    }
}