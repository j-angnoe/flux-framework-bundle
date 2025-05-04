<?php

use Flux\Framework\Chain\Chain;

if (!function_exists('chain')) { 
    function chain(mixed ...$args): Chain { 
        return new Chain(...$args);
    }   
}
it('works', function () {
    $currentOffset = -1;
    $chain = chain(function() use (&$currentOffset) {
        for($i=0;$i<10;$i++) { 
            $currentOffset = $i;
            yield $i;
        }
    });

    // Calling buffer will immediately read some 
    // entries from the iterator
    $chain->buffer(5);

    // We assert that it has read about 5 items
    expect($currentOffset)->toEqual(4);

    // When terminating the chain (with toArray)
    // the remaining items will be read from iterator
    // and buffered + remaining will be returned.
    $result = $chain->toArray();

    expect($result)->toHaveCount(10);
    expect($result)->toEqual([0,1,2,3,4,5,6,7,8,9]);
});
it('can buffer all', function () {
    $currentOffset = -1;
    $chain = chain(function() use (&$currentOffset) {
        for($i=0;$i<10;$i++) { 
            $currentOffset = $i;
            yield $i;
        }
    });

    $chain->buffer();

    // We assert that it has read about 5 items
    expect($currentOffset)->toEqual(9);
});