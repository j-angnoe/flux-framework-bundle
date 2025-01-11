<?php

use Flux\Framework\Chain\Chain;
use PHPUnit\Framework\TestCase;

if (!function_exists('chain')) { 
    function chain(...$args): Chain { 
        return new Chain(...$args);
    }   
}
class ChainCoreTest extends TestCase {


    function testConstructorNull() { 
        # __construct(null)

        $result = chain(null)->toArray();

        $this->assertEquals([], $result);
    }

    function testConstructorSimpleArray() {
        $result = chain([1,2,3])->toArray();

        $this->assertEquals([1,2,3], $result);
    }

    function testConstructorIterator() { 
        # __construct(Iterator)
        $result = chain(new ArrayIterator([4,5,6]))->toArray();
        $this->assertEquals([4,5,6], $result);
    }

    function testConstructorGenerator() { 
        $result = chain(function () {
            yield 7;
            yield 8;
            yield 9;
        })->toArray();

        $this->assertEquals([7,8,9], $result);
    }
        
    function testConstructorResource() { 
        $handle = fopen('php://temp','rw');
        fputs($handle, "1\n");
        fputs($handle, "2\n");
        fputs($handle, "3\n");
        rewind($handle);
        $result = chain($handle)->toString();
        $this->assertEquals("1\n2\n3", $result);
    }

    function testConstructorChain() { 
        $result = chain(chain([4,5,6]))->toArray();
        $this->assertEquals([4,5,6], $result);
    }

    function testConstructorJsonSerializable() { 
        $result = json_encode(chain([7,8,9]));
        $this->assertEquals('[7,8,9]', $result);
    }

    function testPrintRFunction() { 
        ob_start();
        $result = chain([1,2,3])->print_r()->toArray();
        $this->assertEquals([1,2,3], $result);
        $content = ob_get_clean();

        $this->assertEquals(preg_replace('/\s+/',' ',trim(<<<OUT
            0) 1
            1) 2
            2) 3
        OUT)), preg_replace('/\s+/',' ',trim($content)));
    }

    function testPipes() { 
        $result = chain([1,2,3])->pipe(function($x) { return $x * 2; })->toArray();
        $this->assertEquals([2,4,6], $result);
    }

    function testPipeGenerator() { 
        ## pipe(Generator) (aka apply)
        $result = chain([3,2,1])
            ->pipe(function($iterator) { 
            foreach ($iterator as $i) {
                yield $i * 2;
            }
            })->toArray();
        $this->assertEquals([6,4,2], $result);
    }

    function testPipePassesOnlyTruthyStuff() { 
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
        $this->assertEquals([1,2,'whatever',0,false,(object)[],[false],[null]], $result);

    }

    function testApplyWorks() { 
        # apply(generator) should work
        $result = chain([1,2,3])
            ->apply(function ($iterator) {
            foreach ($iterator as $i) {
                yield $i * 2;
            }
        })->toArray();
        $this->assertEquals([2,4,6], $result);
    }

    function testApplyANonGeneratorYieldsAnError() { 
        # apply(closure) that doesn't yield, what will happen?

        $this->expectException(TypeError::class);

        $result = chain([1,2,3])
            ->apply(function ($iterator) {
              foreach ($iterator as $i) {
                $res[] = $i * 2;
              }
              return $res;
            })->toArray();
        $this->assertEquals(null, $result);

    }

    function testChainWindowFunction() { 
        # window() rows are received in right order
        # window(Closure) is supported, we will check out how many parameters the closure wants to determine the window size.

        $result = chain([1,2,3,4,5,6])
            ->window(function($a,$b) { 
            return [$a,$b];
            })->toArray();

        $this->assertEquals([[1,2], [2,3], [3,4], [4,5], [5,6]], $result);

        # window(int, Closure) is supported

        $result = chain([1,2,3,4,5,6])
            ->window(3, function($a,$b,$c) { 
                return [$a,$b,$c];
            })->toArray();

        $this->assertEquals([[1,2,3],[2,3,4],[3,4,5], [4,5,6]], $result);

        # window(Closure, int) is supported
        $result = chain([1,2,3,4,5,6],3)
            ->window(function($a,$b,$c) { 
            return [$a,$b,$c];
            },3)->toArray();
        $this->assertEquals([[1,2,3],[2,3,4],[3,4,5], [4,5,6]], $result);

        # window() result is 1 element shorter than the input.
        $result = chain([1,2,3,4,5])
            ->window(function($a,$b) {
            return $a+$b;
            })
            ->count();
        $this->assertEquals(4, $result);
    }

    function testChainMapping() { 
        # Mapping 
        ## mapWithKeys(closure) - closure should receive (value, key)
        $result = chain([1])
            ->mapWithKeys(function($value, $key) {
            return compact('key','value');
            })
            ->first();
        $this->assertEquals(['key' => 0, 'value' => 1], $result);
        // print_R($result);

        ## map(callable string) - this may be a built-in php function, that receives only value as first argument
        $result = chain(['een','twee'])
            ->map('strtoupper')
            ->toArray();
        $this->assertEquals(['EEN','TWEE'], $result);


    }

    function testMapMayNotYield() { 
        $this->markTestIncomplete('This feature is broken');
        ## map(closure) - may not yield
        $this->expectException(Exception::class);
        $result = chain([1])
            ->map(function($value) {
                yield $value;
            })->toArray();
    }

    function testEachGenerator() { 
        # each(generator) - if you return a generator/iterable each will yield from that generator

        $result = chain([1,2,3])
            ->each(function($value) {
                yield $value*2;
            })->toArray();

        $this->assertEquals([2,4,6], $result);

        # each(non-generator) - does not work.

        $this->expectException(\Exception::class);
        $result = chain([1,2,3])
            ->each(function($value) {
                return $value*2;
            })->toArray();

        $this->assertEquals([], $result);
    }

    function testUniqueFeature() { 


        # unique() - results are deduplicated accurately.
        # unique(100) - buffer size remains static at 100
        $result = chain([1,1,1,2,1,1,2,3,2,1,1,2,3,2,2,3,1,3])
            ->unique()
            ->toArray();

        $this->assertEquals([1,2,3], $result);


        # unique(1) - only sequential duplicates are dedupped.
        $result = chain([1,1,1,2,2,2,1,3,3,3,3])
            ->unique(1)
            ->toArray();

        $this->assertEquals([1,2,1,3], $result);


        # unique(2) - only sequential duplicates are dedupped with memory of 2
        $result = chain([1,1,1,2,2,2,1,3,3,3,3])
            ->unique(2)
            ->toArray();

        $this->assertEquals([1,2,3], $result);

        # unique(2) - only sequential duplicates are dedupped with memory of 2
        $result = chain([1,1,1,2,2,3,1,3,3,3,3])
            ->unique(2)
            ->toArray();

        $this->assertEquals([1,2,3,1], $result);
    }

    function testHead() { 

        $result = chain([1,2,3])
            ->head(1)
            ->toArray();

        $this->assertEquals([1], $result);

        $result = chain([1,2,3])
            ->head(2)
            ->toArray();

        $this->assertEquals([1,2], $result);

        $result = chain([1,2,3])
            ->head(100000)
            ->toArray();

        $this->assertEquals([1,2,3], $result);
    }


    function testTail() { 

        $result = chain([1,2,3])
            ->tail(1)
            ->toArray();

        $this->assertEquals([3], $result);

        $result = chain([1,2,3])
            ->tail(2)
            ->toArray();

        $this->assertEquals([2,3], $result);

        $result = chain([1,2,3])
            ->tail(100000)
            ->toArray();

        $this->assertEquals([1,2,3], $result);
    }

    function testSkip() { 
        $result = chain([1,2,3])
            ->skip(1)
            ->toArray();

        $this->assertEquals([2,3], $result);
    }

    function testFilter() { 
        $result = chain([1,2,3,4])
            ->filter(fn($x) => $x % 2)
            ->toArray();

        $this->assertEquals([1,3], $result);
    }

    function testSort() { 
        $result = chain([1,2,3,4])
            ->sortDesc()
            ->toArray();

        $this->assertEquals([4,3,2,1], $result);

        $result = chain(array_map(fn($x) => ['id' => $x], [1,2,3,4]))
            ->sortDesc(fn($x)=> $x['id'])
            ->toArray();

        $this->assertEquals([['id' => 4],['id' => 3],['id' => 2],['id' => 1]], $result);
    }

    function testToString() { 
        $string = chain([1,2,3])->toString(',');

        $this->assertEquals('1,2,3', $string);
    }

    function testFirstLast() { 
        $result = chain([1,2,3])->first();

        $this->assertEquals(1, $result);

        $result = chain([1,2,3])->last();

        $this->assertEquals(3, $result);
    }

    function testReduce() { 
        $result = chain([1,2,3])
            ->reduce(fn($carry, $item) => $carry + $item, 0);
        
        $this->assertEquals(1 + 2 + 3, $result);
    }

    function testRun() { 
        $iterations = 0;

        $result = chain([1,2,3])
            ->map(function($x) use (&$iterations) {
                $iterations++;
            })
            ->run();

        $this->assertEquals(3, $iterations);
    }
    function todo() {       

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
}