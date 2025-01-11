<?php

use Flux\Framework\Chain\Shell;
use PHPUnit\Framework\TestCase;

class ShellPipeMapTest extends TestCase {
    /**
     * @test
     */
    function basics() { 
        $shell = new Shell('(echo ?; echo ?; echo ?)', 
            'hallo ik ben joshua',
            'hallo ik ben joshua',
            'hallo ik ben joshua'
        );

        $shell->pipeMap(function($line) { 
            return strtoupper($line);
        });

        $data = iterator_to_array($shell);

        static::assertEquals('HALLO IK BEN JOSHUA', $data[0]);
        static::assertEquals('HALLO IK BEN JOSHUA', $data[1]);
        static::assertEquals('HALLO IK BEN JOSHUA', $data[2]);
    }
}