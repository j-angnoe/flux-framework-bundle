<?php

use Flux\Framework\Chain\Chain;

if (!function_exists('chain')) { 
    function chain(mixed ...$args): Chain { 
        return new Chain(...$args);
        
    }   
}
test('constructor null', function () {
    # __construct(null)
    $result = chain(null)->toArray();

    expect($result)->toEqual([]);
});
test('constructor simple array', function () {
    $result = chain([1,2,3])->toArray();

    expect($result)->toEqual([1,2,3]);
});
test('constructor iterator', function () {
    # __construct(Iterator)
    $result = chain(new ArrayIterator([4,5,6]))->toArray();
    expect($result)->toEqual([4,5,6]);
});
test('constructor generator', function () {
    $result = chain(function () {
        yield 7;
        yield 8;
        yield 9;
    })->toArray();

    expect($result)->toEqual([7,8,9]);
});
test('constructor resource', function () {
    $handle = fopen('php://temp','rw');
    fputs($handle, "1\n");
    fputs($handle, "2\n");
    fputs($handle, "3\n");
    rewind($handle);
    $result = chain($handle)->toString();
    expect($result)->toEqual("1\n2\n3");
});
test('constructor chain', function () {
    $result = chain(chain([4,5,6]))->toArray();
    expect($result)->toEqual([4,5,6]);
});
test('constructor json serializable', function () {
    $result = json_encode(chain([7,8,9]));
    expect($result)->toEqual('[7,8,9]');
});
test('print rfunction', function () {
    ob_start();
    $result = chain([1,2,3])->print_r()->toArray();
    expect($result)->toEqual([1,2,3]);
    $content = ob_get_clean();

    expect(preg_replace('/\s+/',' ',trim($content)))->toEqual(preg_replace('/\s+/',' ',trim(<<<OUT
            0) 1
            1) 2
            2) 3
        OUT)));
});
test('pipes', function () {
    $result = chain([1,2,3])->pipe(function($x) { return $x * 2; })->toArray();
    expect($result)->toEqual([2,4,6]);
});
test('pipe generator', function () {
    ## pipe(Generator) (aka apply)
    $result = chain([3,2,1])
        ->pipe(function($iterator) { 
        foreach ($iterator as $i) {
            yield $i * 2;
        }
        })->toArray();
    expect($result)->toEqual([6,4,2]);
});
test('pipe passes only truthy stuff', function () {
    ## pipe(closure) doesnt yield NULL values and empty strings and empty arrays.
    ## pipe(closure) does yield false, 0
    ## pipe(closure) yields everything else.  
    $result = chain(null)
        ->pipe(function () {
            yield 1;
            yield 2;
            yield 'whatever';
            yield null;
            yield '';
            yield 0;
            yield false;
            yield [];
            yield new stdClass;
            yield [false];
            yield [null];
        })->toArray();
    expect($result)->toEqual([1,2,'whatever',0,false,(object)[],[false],[null]]);
});
test('apply works', function () {
    # apply(generator) should work
    $result = chain([1,2,3])
        ->apply(function ($iterator) {
        foreach ($iterator as $i) {
            yield $i * 2;
        }
    })->toArray();
    expect($result)->toEqual([2,4,6]);
});
test('apply anon generator yields an error', function () {
    # apply(closure) that doesn't yield, what will happen?
    $this->expectException(TypeError::class);

    $result = chain([1,2,3])
        ->apply(function ($iterator): array {
            $res = [];
            foreach ($iterator as $i) {
                $res[] = $i * 2;
            }
            return $res;
        })->toArray();
    expect($result)->toEqual(null);
});
test('chain window function', function () {
    # window() rows are received in right order
    # window(Closure) is supported, we will check out how many parameters the closure wants to determine the window size.
    $result = chain([1,2,3,4,5,6])
        ->window(function($a,$b) { 
        return [$a,$b];
        })->toArray();

    expect($result)->toEqual([[1,2], [2,3], [3,4], [4,5], [5,6]]);

    # window(int, Closure) is supported
    $result = chain([1,2,3,4,5,6])
        ->window(3, function($a,$b,$c) { 
            return [$a,$b,$c];
        })->toArray();

    expect($result)->toEqual([[1,2,3],[2,3,4],[3,4,5], [4,5,6]]);

    # window(Closure, int) is supported
    $result = chain([1,2,3,4,5,6],3)
        ->window(function($a,$b,$c) { 
        return [$a,$b,$c];
        },3)->toArray();
    expect($result)->toEqual([[1,2,3],[2,3,4],[3,4,5], [4,5,6]]);

    # window() result is 1 element shorter than the input.
    $result = chain([1,2,3,4,5])
        ->window(function($a,$b) {
        return $a+$b;
        })
        ->count();
    expect($result)->toEqual(4);
});
test('chain mapping', function () {
    # Mapping 
    ## mapWithKeys(closure) - closure should receive (value, key)
    $result = chain([1])
        ->mapWithKeys(function($value, $key) {
        return compact('key','value');
        })
        ->first();
    expect($result)->toEqual(['key' => 0, 'value' => 1]);

    // print_R($result);
    ## map(callable string) - this may be a built-in php function, that receives only value as first argument
    $result = chain(['een','twee'])
        ->map('strtoupper')
        ->toArray();
    expect($result)->toEqual(['EEN','TWEE']);
});
test('map may not yield', function () {
    $this->markTestIncomplete('This feature is broken');

    ## map(closure) - may not yield
    $this->expectException(Exception::class);
    $result = chain([1])
        ->map(function($value) {
            yield $value;
        })->toArray();
});
test('each generator', function () {
    # each(generator) - if you return a generator/iterable each will yield from that generator
    $result = chain([1,2,3])
        ->each(function($value) {
            yield $value*2;
        })->toArray();

    expect($result)->toEqual([2,4,6]);

    # each(non-generator) - does not work.
    $this->expectException(\Exception::class);
    $result = chain([1,2,3])
        ->each(function($value) {
            return $value*2;
        })->toArray();

    expect($result)->toEqual([]);
});
test('unique feature', function () {
    # unique() - results are deduplicated accurately.
    # unique(100) - buffer size remains static at 100
    $result = chain([1,1,1,2,1,1,2,3,2,1,1,2,3,2,2,3,1,3])
        ->unique()
        ->toArray();

    expect($result)->toEqual([1,2,3]);

    # unique(1) - only sequential duplicates are dedupped.
    $result = chain([1,1,1,2,2,2,1,3,3,3,3])
        ->unique(1)
        ->toArray();

    expect($result)->toEqual([1,2,1,3]);

    # unique(2) - only sequential duplicates are dedupped with memory of 2
    $result = chain([1,1,1,2,2,2,1,3,3,3,3])
        ->unique(2)
        ->toArray();

    expect($result)->toEqual([1,2,3]);

    # unique(2) - only sequential duplicates are dedupped with memory of 2
    $result = chain([1,1,1,2,2,3,1,3,3,3,3])
        ->unique(2)
        ->toArray();

    expect($result)->toEqual([1,2,3,1]);
});
test('head', function () {
    $result = chain([1,2,3])
        ->head(1)
        ->toArray();

    expect($result)->toEqual([1]);

    $result = chain([1,2,3])
        ->head(2)
        ->toArray();

    expect($result)->toEqual([1,2]);

    $result = chain([1,2,3])
        ->head(100000)
        ->toArray();

    expect($result)->toEqual([1,2,3]);
});
test('tail', function () {
    $result = chain([1,2,3])
        ->tail(1)
        ->toArray();

    expect($result)->toEqual([3]);

    $result = chain([1,2,3])
        ->tail(2)
        ->toArray();

    expect($result)->toEqual([2,3]);

    $result = chain([1,2,3])
        ->tail(100000)
        ->toArray();

    expect($result)->toEqual([1,2,3]);
});
test('skip', function () {
    $result = chain([1,2,3])
        ->skip(1)
        ->toArray();

    expect($result)->toEqual([2,3]);
});
test('filter', function () {
    $result = chain([1,2,3,4])
        ->filter(fn($x) => $x % 2)
        ->toArray();

    expect($result)->toEqual([1,3]);
});
test('sort', function () {
    $result = chain([1,2,3,4])
        ->sortDesc()
        ->toArray();

    expect($result)->toEqual([4,3,2,1]);

    $result = chain(array_map(fn($x) => ['id' => $x], [1,2,3,4]))
        ->sortDesc(fn($x)=> $x['id'])
        ->toArray();

    expect($result)->toEqual([['id' => 4],['id' => 3],['id' => 2],['id' => 1]]);
});
test('to string', function () {
    $string = chain([1,2,3])->toString(',');

    expect($string)->toEqual('1,2,3');
});
test('first last', function () {
    $result = chain([1,2,3])->first();

    expect($result)->toEqual(1);

    $result = chain([1,2,3])->last();

    expect($result)->toEqual(3);
});
test('reduce', function () {
    $result = chain([1,2,3])
        ->reduce(fn($carry, $item) => $carry + $item, 0);

    expect($result)->toEqual(1 + 2 + 3);
});
test('run', function () {
    $iterations = 0;

    $result = chain([1,2,3])
        ->map(function($x) use (&$iterations) {
            $iterations++;
        })
        ->run();

    expect($iterations)->toEqual(3);
});

/*
function todo(): void
{
    # trim() - what happens to empty lines...?
    # head(n) - gives us the first n results
    # take(n) - gives us the first n results
    # skip(n)
    # tail(n) - gives us the last n results
    # filter(string callable)
    # filter() - will strip out null, false, '' and [] values
    # filter(closure) - keep elements that are truthy.
    # reject() is the inverse of filter
    # sort()
    # sort(closure)
    # sort(function($a, $b) { }) - this will not work, should throw an error
    # sort('strtolower')
    # sortAsc() - results are ordered neatly.
    # sortDesc() - results are ordered in reverse
    # toCsv() converts you tabular data to array.
    # toArray() - executes the iterator and returns the result as an array.
    # last() - executes the iterator and returns only the last line.
    # first() - executes the iterator and returns only the first line.
    # toString()
    # toString(custom separator)
    # toString(trimming) - trims the final result.
    # output() - echo's the output
    # output('/my/file') - outputs to a file
    # output(resource) - outputs to resource
    # output('php://stderr') - outputs to stderr
    # output(null) - does execute but outputs nothing
    # static::magicStrings() will control the behaviour of __toString()
    # __toString() when magicStrings is enabled (executes the iterator and outputs result)
    # __toString() can be used inside a string.
    # __toString() when magicStrings is disabled
    # done() - finalizes the result, after this no more operations can be performed.
    # ob(closure) - captures your output so you can chain operations afterwards
    # ob(closure) - is lazy, executes only when the chain is executed.
    # getStats() - i can receive useful info about the last operation.
}
*/