<?php

use Flux\Framework\Chain\Chain;
use Flux\Framework\DuckDB\DuckDB;
use PHPUnit\Framework\TestCase;

class DuckDbTest extends TestCase {
    /**
     * @test
     */
    function the_basics() {
        $db = new DuckDB();
        $chain = $db->query('SELECT 1 as x, 2 as y FROM range(5)');
        
        static::assertInstanceOf(Chain::class, $chain);

        $rows = $chain->toArray();

        static::assertCount(5, $rows);
        static::assertEquals(['x' => 1, 'y' => 2], $rows[0]);
    }

    /**
     * @test
     */
    function query_builder() { 
        $db = new DuckDB();
        $result = $db->from('range(10)')
            ->select('range.range as x')
            ->skip(2)
            ->limit(3)
            ->toArray()
        ;

        static::assertEquals([['x' => 2], ['x' => 3], ['x' => 4]], $result);
        // print_r($result);
    }
}