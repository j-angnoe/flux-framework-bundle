<?php

use Flux\Framework\Chain\Chain;
use PHPUnit\Framework\TestCase;

if (!function_exists('chain')) { 
    function chain(mixed ...$args): Chain { 
        return new Chain(...$args);
    }   
}

class ChainBufferTest extends TestCase {
    /**
     * @test
     */
    function it_works(): void { 
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
        $this->assertEquals(4, $currentOffset);

        // When terminating the chain (with toArray)
        // the remaining items will be read from iterator
        // and buffered + remaining will be returned.
        $result = $chain->toArray();

        $this->assertCount(10, $result);
        $this->assertEquals([0,1,2,3,4,5,6,7,8,9], $result);
    }

    /**
     * @test
     */
    function it_can_buffer_all(): void { 
        $currentOffset = -1;
        $chain = chain(function() use (&$currentOffset) {
            for($i=0;$i<10;$i++) { 
                $currentOffset = $i;
                yield $i;
            }
        });

        $chain->buffer();

        // We assert that it has read about 5 items
        $this->assertEquals(9, $currentOffset);
        
    }
}