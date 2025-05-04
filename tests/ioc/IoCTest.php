<?php

use Flux\Framework\Utils\IoC;

class MyClassA { } 
class MyClassB { } 
class DecoratorForA {
    function __construct(private MyClassA $my_a)
    {
    } 
}
class ItHasBeenCalled extends Exception { 
}

it('can resolve requested arguments from given container', function () {
    $ioc = new IoC;
    $ioc->set(MyClassA::class, new MyClassA);
    $ioc->set(MyClassB::class, new MyClassB);

    $ioc->call(function(MyClassA $a, MyClassB $b, string $c, $d) {
        expect($a instanceof MyClassA)->toBeTrue();
        expect($b instanceof MyClassB)->toBeTrue();
        expect($c)->toEqual('waarde voor c');
        expect($d)->toEqual('waarde voor d');
        throw new ItHasBeenCalled;
    }, [], ['$c' => 'waarde voor c', '$d' => 'waarde voor d']);

})->throws(ItHasBeenCalled::class); 

test('can resolve and leave optional args be', function () {
    $ioc = new IoC;
    $ioc->set(MyClassA::class, new MyClassA);
    $ioc->set(MyClassB::class, new MyClassB);

    $ioc->call(function(MyClassA $a, MyClassB $b, string $c = 'waarde voor c', $d = 'waarde voor d') {
        expect($a instanceof MyClassA)->toBeTrue();
        expect($b instanceof MyClassB)->toBeTrue();
        expect($c)->toEqual('waarde voor c');
        expect($d)->toEqual('waarde voor d');
        throw new ItHasBeenCalled;
    });
})->throws(ItHasBeenCalled::class);

it('can resolve variation2', function () {
    $ioc = new IoC;
    $ioc->set(MyClassA::class, new MyClassA);
    $ioc->set(MyClassB::class, new MyClassB);

    $ioc->call(function(MyClassA $a, MyClassB $b, string $c, $d) {
        expect($a instanceof MyClassA)->toBeTrue();
        expect($b instanceof MyClassB)->toBeTrue();
        expect($c)->toEqual('waarde voor c');
        expect($d)->toEqual('waarde voor d');
        throw new ItHasBeenCalled;
    }, ['waarde voor c'], ['$d' => 'waarde voor d']);
})->throws(ItHasBeenCalled::class);

it('can resolve varation3', function () {
    $ioc = new IoC;
    $ioc->set(MyClassA::class, new MyClassA);
    $ioc->set(MyClassB::class, new MyClassB);

    $ioc->call(function(MyClassA $a, MyClassB $b, string $c, $d) {
        expect($a instanceof MyClassA)->toBeTrue();
        expect($b instanceof MyClassB)->toBeTrue();
        expect($c)->toEqual('waarde voor c');
        expect($d)->toEqual('waarde voor d');
        throw new ItHasBeenCalled;
    }, ['waarde voor c', 'waarde voor d'], []);
})->throws(ItHasBeenCalled::class);

it('cannot resolve recursively', function () {
    $ioc = new IoC;
    $ioc->set(MyClassA::class, new MyClassA);
    $ioc->call(function(DecoratorForA $a) {
        expect($a)->toBeInstanceOf(DecoratorForA::class);
        throw new ItHasBeenCalled;
    });
})->throws(ArgumentCountError::class);  

it('can resolve recursively', function () {
    $ioc = new IoC;
    $ioc->set(MyClassA::class, new MyClassA);
    $ioc->set(DecoratorForA::class, fn(MyClassA $a) => new DecoratorForA($a));

    $ioc->call(function(DecoratorForA $a) {
        expect($a)->toBeInstanceOf(DecoratorForA::class);
        throw new ItHasBeenCalled;
    });
})->throws(ItHasBeenCalled::class); 
