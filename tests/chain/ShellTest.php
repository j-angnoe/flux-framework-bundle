<?php

use Flux\Framework\Chain\BackgroundCommand;
use Flux\Framework\Chain\Chain;
use Flux\Framework\Chain\RuntimeExceededException;
use Flux\Framework\Chain\Shell;
use Flux\Framework\Chain\ShellTrait;
use PHPUnit\Framework\TestCase;
use SebastianBergmann\CodeCoverage\Report\Xml\Unit;

class UnitTest_Shell_Chain extends Chain {
    use ShellTrait;
}

class ShellTest extends TestCase {
    function setUp(): void {
        error_reporting(E_ALL);
    }
    function test_it_escapes_all_command_arguments_by_default(): void { 
        $result = UnitTest_Shell_Chain::formatCommand('a','b','c');
        $this->assertEquals("a 'b' 'c'", $result);
    }

    function test_you_can_define_placeholders_questionmark_and_percent_s(): void { 
        $result = UnitTest_Shell_Chain::formatCommand('a ? %s','b','c');
        $this->assertEquals("a 'b' c", $result);
    }

    function test_more_arguments_then_placeholders_will_be_escaped(): void { 
        $result = UnitTest_Shell_Chain::formatCommand('a ? %s','b','c','d','e');
        $this->assertEquals("a 'b' c 'd' 'e'", $result);
    }

    function test_i_can_run_a_command(): void { 
        $result = UnitTest_Shell_Chain::shell('echo ?; echo ?; echo ?; echo ?;', 'hallo','hoe','is','het');

        $this->assertInstanceOf(UnitTest_Shell_Chain::class, $result);

        $lines = $result->toArray();

        $this->assertEquals(['hallo','hoe','is','het'], $lines);
    }

    function test_what_happens_to_stderr(): void { 
        // Stderr will be outputted in stream
        $result = UnitTest_Shell_Chain::shell('>&2 echo "STDERR"; echo "hoi"');
        $items = $result->toArray();
        
        $this->assertContains('hoi', $items);
        $this->assertContains('stderr > STDERR', $items);
    }

    function test_what_happens_on_pipe_failure(): void { 

        // you can define it
        $command = UnitTest_Shell_Chain::shell('cat /does/not/exist | wc -l');
        
        // but it will throw upon runnign:
        $this->expectException(Exception::class);
        // $command->run();    

        foreach ($command as $line) { 
            // echo "Received $line\n";
            // ob_flush();
        }
    }

    /**
     * This is a prove that it is capable of running big data
     * without getting into memory issues.
     * 
     * This test is a bit heavy to run all-the-time.
     */
    function test_shell_can_handle_big_data(): void {
        static::markTestSkipped('Skipping big-data test');

        $command = UnitTest_Shell_Chain::shell('php -r ?', <<<'PHP'
            ob_start(null, 8*581);
            $blob = sha1(uniqid());
            $blob = $blob.$blob.$blob;
            for($i=0;$i < 1_000_000; $i++) { 
                echo substr($i.' '. $blob,0,120) . PHP_EOL;
            }
        PHP);


        $counts = $command->reduce(function ($carry, $item) {
            [$lineno] = explode(' ',$item,2);
            if (intval($lineno) !== $carry[0]) { 
                throw new \Exception('it failed at line ' . $carry[0] . '`'.$item.'`');
            }
            $carry[0] += 1;

            // echo $carry[0] . ' ' . substr($item,0,8) . "\n";
            // ob_flush();
            $carry[1] += strlen($item);
            return $carry;
        }, [0,0]);

        // print_r($counts);

        $this->assertEquals(1_000_000, $counts[0], 'There should have been 1 milion lines read.');
        $this->assertEquals(1_000_000 * (3*strlen(sha1('x'))), $counts[1], 'There should be this amount of bytes received.');

        $this->assertEquals(1_000_000, $command->getStats('read_lines'));
        $this->assertEquals(120_000_000, $command->getStats('read_bytes'));
    }

    function test_shell_can_launch_a_command_with_limited_runtime(): void {
        $command = new Shell('php -r ?', <<<'PHP'
            $start = microtime(true);
            for($i=0;$i<1000;$i++) {
                $elapsed = round(1000* (microtime(true) - $start));
                echo "Elapsed $elapsed\n";
                usleep(1 * 1000);
            }
        PHP);

        $command->setRuntime(250);
        $elapsed = -1;

        // We should receive a RuntimeExceededException
        $this->expectException(RuntimeExceededException::class);

        foreach ($command as $signal => $line) {
            [,$elapsed] = explode('Elapsed ', $line);
            // echo $elapsed . PHP_EOL; ob_flush();
            $this->assertLessThanOrEqual(250, $elapsed);
        }
        error_log("\ninfo: Command with runtime 250 had $elapsed ms of effective runtime");


    }

    function test_shell_can_launch_a_limited_command_but_always_receive_failure_signals(): void {
        $command = new Shell('php -r ?', <<<'PHP'
            // Generate a parse error
            intented parse error!;
        PHP);

        // Even with extremely short runtime:
        $command->setRuntime(1);

        // We will get an exception
        $this->expectException(Exception::class);

        // And the instant failure error is visible here:
        $output = (new UnitTest_Shell_Chain($command))->toString();

        $this->assertStringContainsString('Parse error', $output);

    }

    function test_i_can_dispatch_a_background_command_and_handle_falsy_lines(): void { 
        $command = new Shell('php -r ?', <<<'PHP'
            for($i=0;$i<1_000;$i++){
                echo "0\n";
                fputs(STDERR, "0\n");
            }
        PHP);

        $lines = [];
        foreach ($command->dispatchBackgroundCommand() as $line) { 
            $lines[] = $line;
        }

        $this->assertCount(1000 +1000, $lines, 'There should be 2000 lines (1000 stdout, 1000 stderr).');
    }

    function test_i_can_dispatch_a_background_command_and_receive_all_output_streams(): void { 
        $command = new Shell('php -r ?', <<<'PHP'
            for($i=0;$i<10;$i++){
                echo "Iteration $i\n";
                fputs(STDERR, "stderr message $i\n");
                usleep(100*1000);
            }
        PHP);

        $lines = [];
        foreach ($command->dispatchBackgroundCommand() as $line) { 
            $lines[] = $line;
        }

        $this->assertCount(20, $lines, 'There should be 10 stdout lines and 10 stderr lines.');
    }

    function test_i_can_dispatch_a_background_command_and_stop_it_midway(): void { 
        $command = new Shell('php -r ?', <<<'PHP'
            for($i=0;$i<100;$i++){
                echo "iteration $i\n";
                usleep(10 * 1000);
            }
        PHP);

        $command = $command->dispatchBackgroundCommand();
        $lines = [];
        foreach ($command as $line) { 
            if ($line === 'iteration 10') { 
                $command->stop();
                // echo "Stop after iteration 10!\n";
            }
            $lines[] = $line;
            // echo "received line; $line\n";
            // ob_flush();
        }

        $this->assertLessThan(30, count($lines), 'The command should have stopped in less then 30 iterations or so..');
    }

    function test_i_can_dispatch_a_background_command_and_only_iterate_stdout(): void { 
        $command = new Shell('php -r ?', <<<'PHP'
            for($i=0;$i<10;$i++){
                echo "Iteration $i\n";
                fputs(STDERR, "stderr message double $i\n");
                fputs(STDERR, "stderr message double $i\n");
                usleep(100*1000);
            }
        PHP);

        $bg = $command->dispatchBackgroundCommand();

        // Run 1: All output (30 lines)
        $lines = [];
        foreach ($bg as $line) {
            $lines[] = $line;
        }
        $this->assertCount(30, $lines);

        // Run 2: Stdout output (10 lines)
        $lines = [];
        foreach ($bg->getIterator(stderr: false) as $line) {
            $lines[] = $line;
        }
        $this->assertCount(10, $lines);

        // Run 2: Stdout output (10 lines)
        $lines = [];
        foreach ($bg->getIterator(true, false) as $line) {
            $lines[] = $line;
        }
        $this->assertCount(20, $lines);
    }

    function test_yield_last_line(): void {
        $command = new Shell('php -r ?', <<<'PHP'
            echo "Just echo something without a NEWLINE";
        PHP);

        $bg = $command->dispatchBackgroundCommand();

        $lines = [];
        foreach ($bg->getIterator(stderr: false) as $line) {
            $lines[] = $line;
        }

        $this->assertCount(1, $lines);

    }

    function test_the_exitcode_will_be_available_afterwards(): void { 
        $command = new Shell('php -r ?', <<<'PHP'
            echo "Just echo something without a NEWLINE";
        PHP);

        $bg = $command->dispatchBackgroundCommand();

        $lines = [];
        foreach ($bg->getIterator(stderr: false) as $line) {
            $lines[] = $line;
        }

        static::assertEquals(0, $bg->getExitCode());
    }


    function test_getIterator_yields_after_timeoutInSeconds_during_long_running_process(): void { 
        $command = new Shell('php -r ?', <<<'PHP'
            echo "Starting\n";
            sleep(1);
            echo "Finished\n";
            // echo "Just echo something without a NEWLINE";
        PHP);

        $signals = [];
        foreach ($command->getIterator(0.1) as $line) { 
            if (!$line) { 
                $signals[] = $line;
            }
        }

        static::assertGreaterThan(6, count($signals), 'There should have been about 10 timeouts while running the command');
    }

    function test_shell_while_running(): void {
        $command = new Shell('php -r ?', <<<'PHP'
            echo "Starting\n";
            for($i=0;$i<100;$i++) { 
                echo "Iteration $i\n";
                usleep(1e6/100);
            }
            echo "Finished\n";
            // echo "Just echo something without a NEWLINE";
        PHP);


        $signals = [];
        $receivedLines = '';

        // The primary use case is receiving ticks every once in a while,
        // optionally, you can also receive the collected content (since last tick)
        foreach ($command->whileRunning(10,true) as $line) { 
            $signals[] = 1;
            $receivedLines .= join("\n", $line);
        }

        static::assertStringContainsString("Iteration 99", $receivedLines);
        
        static::assertGreaterThan(6, count($signals), 'There should have been about 10 timeouts while running the command');
    }
}
