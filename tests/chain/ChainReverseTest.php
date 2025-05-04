<?php
use Flux\Framework\Chain\Chain;
use PHPUnit\Framework\TestCase;

class ChainReverseTest extends TestCase {
    /**
     * @test
     */

    function it_can_read_files_in_reverse(): void {
        $stream = tmpfile();
        for($i=1;$i<100;$i++) { 
            fputs($stream, "line $i\n");
        }
        $total = 0;
        foreach (Chain::convertStreamReverse($stream, null) as $index => $line) { 
            $this->assertEquals("line " . (99-$index), $line);
            $total += 1;
        }
        $this->assertEquals(99, $total, 'There should be 99 lines read');
        ob_flush();
    }
}