<?php

use Flux\Framework\Chain\Chain;
use PHPUnit\Framework\TestCase;

if (!function_exists('chain')) { 
    function chain(mixed ...$args): Chain { 
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

    private function assertItWasSlow(): void { 
        $this->assertTrue($this->getTheTime() > 0.9);
    }
    private function assertItWasFast(): void { 
        $this->assertTrue($this->getTheTime() < 0.1);
    }

    private function getCacheSource(array $cacheOptions = [], bool $refreshCache = false): Chain { 
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

    /**
     * @test
     */
    function test_caching_capabilities(): void { 
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

    /**
     * @test
     */
    function anonymous_cache_is_recognized_by_stacktrace(): void {
        $this->startTimer();
        $this->getCacheSource([], true)->run();
        $this->assertItWasSlow();
        
        $this->startTimer();
        $this->getCacheSource([], false)->run();
        $this->assertItWasFast();
    }

    /**
     * @test
     */

    function it_can_serve_reversed(): void { 
        $source1 = chain([1,2,3,4,5])->cache('1m', ['id' => 'mycache', 'reverse' => true,'refreshCache' => true]);

        $result = $source1->toArray();
        $this->assertArrayHasKey('To cache', $source1->getStats());
        $this->assertEquals([5,4,3,2,1], $result);

        $source2 = chain([1,2,3,4,5])->cache('1m', ['id' => 'mycache', 'reverse' => true]);
        $result = $source2->toArray();
        $this->assertArrayHasKey('From cache', $source2->getStats());

        $this->assertEquals([5,4,3,2,1], $result);
    }
}