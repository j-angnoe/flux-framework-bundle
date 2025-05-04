<?php

use Flux\Framework\Chain\Chain;
use Flux\Framework\DuckDB\DuckDB;
beforeAll(function () {
    $duckDbBinaryExists = shell_exec('which duckdb');

    if (!$duckDbBinaryExists) { 
        self::markTestSkipped('DuckDbTests are skipped because `duckdb` binary is not available.');
    }
});
test('the basics', function () {
    $db = new DuckDB();
    $chain = $db->query('SELECT 1 as x, 2 as y FROM range(5)');

    static::assertInstanceOf(Chain::class, $chain);

    $rows = $chain->toArray();

    static::assertCount(5, $rows);
    static::assertEquals(['x' => 1, 'y' => 2], $rows[0]);
});
test('query builder', function () {
    $db = new DuckDB();
    $result = $db->from('range(10)')
        ->select('range.range as x')
        ->skip(2)
        ->limit(3)
        ->toArray()
    ;

    static::assertEquals([['x' => 2], ['x' => 3], ['x' => 4]], $result);
    // print_r($result);
});