<?php

use Flux\Framework\Chain\Chain;

use Flux\Framework\Chain\ShellTrait;
use Flux\Framework\Chain\RuntimeExceededException;
use Flux\Framework\Chain\Shell;

class UnitTest_Shell_Chain extends Chain { 
    use ShellTrait;
}

test('it escapes all command arguments by default', function () {
    $result = UnitTest_Shell_Chain::formatCommand('a','b','c');
    expect($result)->toEqual("a 'b' 'c'");
});

test('you can define placeholders questionmark and percent s', function () {
    $result = UnitTest_Shell_Chain::formatCommand('a ? %s','b','c');
    expect($result)->toEqual("a 'b' c");
});

test('more arguments then placeholders will be escaped', function () {
    $result = UnitTest_Shell_Chain::formatCommand('a ? %s','b','c','d','e');
    expect($result)->toEqual("a 'b' c 'd' 'e'");
});

test('i can run a command', function () {
    $result = UnitTest_Shell_Chain::shell('echo ?; echo ?; echo ?; echo ?;', 'hallo','hoe','is','het');

    expect($result)->toBeInstanceOf(UnitTest_Shell_Chain::class);

    $lines = $result->toArray();

    expect($lines)->toEqual(['hallo','hoe','is','het']);
});

test('what happens to stderr', function () {
    // Stderr will be outputted in stream
    $result = UnitTest_Shell_Chain::shell('>&2 echo "STDERR"; echo "hoi"');
    $items = $result->toArray();

    expect($items)->toContain('hoi');
    expect($items)->toContain('stderr > STDERR');
});

test('what happens on pipe failure', function () {
    // you can define it
    $command = UnitTest_Shell_Chain::shell('cat /does/not/exist | wc -l');

    // but it will throw upon runnign:
    $this->expectException(Exception::class);

    // $command->run();    
    foreach ($command as $line) { 
        // echo "Received $line\n";
        // ob_flush();
    }
});

test('shell can handle big data', function () {
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
    expect($counts[0])->toEqual(1_000_000, 'There should have been 1 milion lines read.');
    expect($counts[1])->toEqual(1_000_000 * (3*strlen(sha1('x'))), 'There should be this amount of bytes received.');

    expect($command->getStats('read_lines'))->toEqual(1_000_000);
    expect($command->getStats('read_bytes'))->toEqual(120_000_000);
});

test('shell can launch a command with limited runtime', function () {
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
        expect($elapsed)->toBeLessThanOrEqual(250);
    }
    error_log("\ninfo: Command with runtime 250 had $elapsed ms of effective runtime");
});

test('shell can launch a limited command but always receive failure signals', function () {
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
});

test('i can dispatch a background command and handle falsy lines', function () {
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

    expect($lines)->toHaveCount(1000 +1000, 'There should be 2000 lines (1000 stdout, 1000 stderr).');
});

test('i can dispatch a background command and receive all output streams', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        for($i=0;$i<10;$i++){
            echo "Iteration $i\n";
            fputs(STDERR, "stderr message $i\n");
        }
    PHP);

    $lines = [];
    foreach ($command->dispatchBackgroundCommand() as $line) { 
        $lines[] = $line;
    }

    expect($lines)->toHaveCount(20, 'There should be 10 stdout lines and 10 stderr lines.');
});

test('i can dispatch a background command and stop it midway', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        for($i=0;$i<=10;$i++){
            echo "iteration $i\n";            
        }
        usleep(150 * 1000);
        for($i=0;$i<100;$i++){
            echo "iteration $i\n";            
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

    expect(count($lines))->toBeLessThan(30, 'The command should have stopped in less then 30 iterations or so..');
});

test('i can dispatch a background command and only iterate stdout', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        for($i=0;$i<10;$i++){
            echo "Iteration $i\n";
            fputs(STDERR, "stderr message double $i\n");
            fputs(STDERR, "stderr message double $i\n");
        }
    PHP);

    $bg = $command->dispatchBackgroundCommand();

    // Run 1: All output (30 lines)
    $lines = [];
    foreach ($bg as $line) {
        $lines[] = $line;
    }
    expect($lines)->toHaveCount(30);

    // Run 2: Stdout output (10 lines)
    $lines = [];
    foreach ($bg->getIterator(stderr: false) as $line) {
        $lines[] = $line;
    }
    expect($lines)->toHaveCount(10);

    // Run 2: Stdout output (10 lines)
    $lines = [];
    foreach ($bg->getIterator(true, false) as $line) {
        $lines[] = $line;
    }
    expect($lines)->toHaveCount(20);
});

test('yield last line', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        echo "Just echo something without a NEWLINE";
    PHP);

    $bg = $command->dispatchBackgroundCommand();

    $lines = [];
    foreach ($bg->getIterator(stderr: false) as $line) {
        $lines[] = $line;
    }

    expect($lines)->toHaveCount(1);
});

test('the exitcode will be available afterwards', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        echo "Just echo something without a NEWLINE";
    PHP);

    $bg = $command->dispatchBackgroundCommand();

    $lines = [];
    foreach ($bg->getIterator(stderr: false) as $line) {
        $lines[] = $line;
    }

    expect($bg->getExitCode())->toBe(0);    
});


test('get iterator yields after timeout in seconds during long running process', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        echo "Starting\n";
        usleep(200 * 1000);
        echo "Finished\n";
        // echo "Just echo something without a NEWLINE";
    PHP);

    $signals = [];
    foreach ($command->getIterator(0.01) as $line) { 
        if (!$line) { 
            $signals[] = $line;
        }
    }

    expect(count($signals))->toBeGreaterThanOrEqual(1);
});

test('shell while running', function () {
    $command = new Shell('php -r ?', <<<'PHP'
        echo "Starting\n";
        for($i=0;$i<100;$i++) { 
            echo "Iteration $i\n";
            usleep(1e6/1000);
        }
        echo "Finished\n";
        // echo "Just echo something without a NEWLINE";
    PHP);

    $signals = [];
    $receivedLines = '';

    // The primary use case is receiving ticks every once in a while,
    // optionally, you can also receive the collected content (since last tick)
    foreach ($command->whileRunning(100,true) as $line) { 
        $signals[] = 1;
        $receivedLines .= join("\n", toa($line));
    }

    expect($receivedLines)->toContain("Iteration 99");
    
    expect(count($signals))->toBeGreaterThanOrEqual(6);

});
