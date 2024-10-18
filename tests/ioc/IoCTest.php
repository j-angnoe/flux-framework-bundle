<?php

use Flux\Framework\Utils\IoC;
use PHPUnit\Framework\TestCase;

class MyClassA { } 
class MyClassB { } 
class DecoratorForA {
    function __construct(private MyClassA $my_a) { } 
}
class ItHasBeenCalled extends Exception { 
}

class IoCTest extends TestCase { 
    /**
     * @test
     */

    function it_can_resolve_requested_arguments_from_given_container() {
        $ioc = new IoC;
        $ioc->set(MyClassA::class, new MyClassA);
        $ioc->set(MyClassB::class, new MyClassB);

        $this->expectException(ItHasBeenCalled::class);
        $ioc->call(function(MyClassA $a, MyClassB $b, string $c, $d) {
            $this->assertTrue($a instanceof MyClassA);
            $this->assertTrue($b instanceof MyClassB);
            $this->assertEquals('waarde voor c', $c);
            $this->assertEquals('waarde voor d', $d);
            throw new ItHasBeenCalled;
        }, [], ['$c' => 'waarde voor c', '$d' => 'waarde voor d']);
    }

    /**
     * @test
     */

    function can_resolve_and_leave_optional_args_be() {
        $ioc = new IoC;
        $ioc->set(MyClassA::class, new MyClassA);
        $ioc->set(MyClassB::class, new MyClassB);

        $this->expectException(ItHasBeenCalled::class);
        $ioc->call(function(MyClassA $a, MyClassB $b, string $c = 'waarde voor c', $d = 'waarde voor d') {
            $this->assertTrue($a instanceof MyClassA);
            $this->assertTrue($b instanceof MyClassB);
            $this->assertEquals('waarde voor c', $c);
            $this->assertEquals('waarde voor d', $d);
            throw new ItHasBeenCalled;
        });
    }
    /**
     * @test
     */
    function it_can_resolve_variation2() { 
        $ioc = new IoC;
        $ioc->set(MyClassA::class, new MyClassA);
        $ioc->set(MyClassB::class, new MyClassB);

        $this->expectException(ItHasBeenCalled::class);
        $ioc->call(function(MyClassA $a, MyClassB $b, string $c, $d) {
            $this->assertTrue($a instanceof MyClassA);
            $this->assertTrue($b instanceof MyClassB);
            $this->assertEquals('waarde voor c', $c);
            $this->assertEquals('waarde voor d', $d);
            throw new ItHasBeenCalled;
        }, ['waarde voor c'], ['$d' => 'waarde voor d']);
        
    }

    /**
     * @test
     */
    function it_can_resolve_varation3() { 
        $ioc = new IoC;
        $ioc->set(MyClassA::class, new MyClassA);
        $ioc->set(MyClassB::class, new MyClassB);

        $this->expectException(ItHasBeenCalled::class);
        $ioc->call(function(MyClassA $a, MyClassB $b, string $c, $d) {
            $this->assertTrue($a instanceof MyClassA);
            $this->assertTrue($b instanceof MyClassB);
            $this->assertEquals('waarde voor c', $c);
            $this->assertEquals('waarde voor d', $d);
            throw new ItHasBeenCalled;
        }, ['waarde voor c', 'waarde voor d'], []);
    }

    /**
     * @test
     */
    function it_cannot_resolve_recursively() { 
        $ioc = new IoC;
        $ioc->set(MyClassA::class, new MyClassA);
        $this->expectException(ArgumentCountError::class);
        $ioc->call(function(DecoratorForA $a) {
            $this->assertInstanceOf(DecoratorForA::class, $a);
            throw new ItHasBeenCalled;
        });
    }

    /**
     * @test
     */
    function it_can_resolve_recursively() { 
        $ioc = new IoC;
        $ioc->set(MyClassA::class, new MyClassA);
        $ioc->set(DecoratorForA::class, fn(MyClassA $a) => new DecoratorForA($a));

        $this->expectException(ItHasBeenCalled::class);
        $ioc->call(function(DecoratorForA $a) {
            $this->assertInstanceOf(DecoratorForA::class, $a);
            throw new ItHasBeenCalled;
        });
    }
}