<?php

use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\UnsparseTrait;

class UnitTestUnsparseChain extends Chain {
    use UnsparseTrait;
}

test('unsparse', function () {
    $chain = new UnitTestUnsparseChain([
        ['id' => 1],
        ['id' => 2,'name'=>'joshua'],
        ['id' => 3,'last_name'=>'fsadfdf'],
        ['id' => 4,'gender'=>'male'],
    ]);

    expect($chain->unsparse()->toJsonlines()->toString())->toEqual(<<<EXPECTED
        {"id":1,"name":null,"last_name":null,"gender":null}
        {"id":2,"name":"joshua","last_name":null,"gender":null}
        {"id":3,"name":null,"last_name":"fsadfdf","gender":null}
        {"id":4,"name":null,"last_name":null,"gender":"male"}
        EXPECTED);
});
