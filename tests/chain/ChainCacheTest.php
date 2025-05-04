<?php

use Flux\Framework\Chain\Chain;

if (!function_exists('chain')) { 
    function chain(mixed ...$args): Chain { 
        return new Chain(...$args);
    }   
}

class MiniTimer { 
    private float $time;
    function __construct() {
        $this->time = microtime(true);
    }
    function elapsed(): float { 
        return microtime(true) - $this->time;
    }
    function assertItWasSlow(): void
    {
        expect($this->elapsed() > 0.9)->toBeTrue();
    }
    function assertItWasFast(): void 
    {
        expect($this->elapsed() < 0.1)->toBeTrue();
    }
}


function getCacheSource(array $cacheOptions = [], bool $refreshCache = false): Chain
{
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

it('caching capabilities', function () {
    $timer = new MiniTimer();
    $myCacheId = __METHOD__ . uniqid();

    
    // First it is slow:
    $result = getCacheSource(['id' => $myCacheId], true)
        ->toArray();

    expect($result)->toEqual([['id' => 1],['id' => 2],['id' => 3]]);
    $timer->assertItWasSlow();

    // You can get the same cache by supplying the same ID/cache parameters.
    $timer = new MiniTimer();
    $result = chain(null)->cache('5m', ['id' => $myCacheId])->toArray();
    expect($result)->toEqual([['id' => 1],['id' => 2],['id' => 3]]);

    $timer->assertItWasFast();
});

test('anonymous cache is recognized by stacktrace', function () {
    $timer = new MiniTimer();
    getCacheSource([], true)->run();
    $timer->assertItWasSlow();

    $timer = new MiniTimer();
    getCacheSource([], false)->run();
    $timer->assertItWasFast();
});

it('can serve reversed', function () {
    $source1 = chain([1,2,3,4,5])->cache('1m', ['id' => 'mycache', 'reverse' => true,'refreshCache' => true]);

    $result = $source1->toArray();
    expect($source1->getStats())->toHaveKey('To cache');
    expect($result)->toEqual([5,4,3,2,1]);

    $source2 = chain([1,2,3,4,5])->cache('1m', ['id' => 'mycache', 'reverse' => true]);
    $result = $source2->toArray();
    expect($source2->getStats())->toHaveKey('From cache');

    expect($result)->toEqual([5,4,3,2,1]);
});