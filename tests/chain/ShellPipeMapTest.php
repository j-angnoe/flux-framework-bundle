<?php

use Flux\Framework\Chain\Shell;

test('basics', function () {
    $shell = new Shell('(echo ?; echo ?; echo ?)', 
        'hallo ik ben joshua',
        'hallo ik ben joshua',
        'hallo ik ben joshua'
    );

    $shell->pipeMap(function($line) { 
        return strtoupper($line);
    });

    $data = iterator_to_array($shell);

    expect($data)->toBe([
        'HALLO IK BEN JOSHUA',
        'HALLO IK BEN JOSHUA',
        'HALLO IK BEN JOSHUA'
    ]);
});