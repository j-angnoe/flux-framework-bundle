<?php
use Flux\Framework\Chain\Chain;
it('can read files in reverse', function () {
    $stream = tmpfile();
    for($i=1;$i<100;$i++) { 
        fputs($stream, "line $i\n");
    }
    $total = 0;
    foreach (Chain::convertStreamReverse($stream, null) as $index => $line) { 
        expect($line)->toEqual("line " . (99-$index));
        $total += 1;
    }
    expect($total)->toEqual(99, 'There should be 99 lines read');
    ob_flush();
});