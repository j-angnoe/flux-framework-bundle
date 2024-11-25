<?php

use PHPUnit\Framework\TestCase;
use PSB\Core\Connection\ConnectionInterface;
use PSB\Core\Http\PsbHttpClient;
use PSB\Core\Http\PsbHttpClientInterface;
use PSB\Core\Log\MetricsManager;
use PSB\Core\Log\PsbLogger;
use PSB\Core\Log\PsbLoggerInterface;
use Psr\Log\NullLogger;

class MetricsManagerTest extends TestCase {
    private function getMetricsManager(?PsbLoggerInterface $logger = null, ?PsbHttpClientInterface $http = null): MetricsManager {
        $logger ??= $this->createMock(PsbLoggerInterface::class);
        $http   ??= new PsbHttpClient();

        return new MetricsManager($logger, $http, '/tmp/metrics-manager-unit-test');
    }
    /**
     * @test
     */
    function it_can_start_a_process() { 

        $mm = $this->getMetricsManager();

        $finish = $mm->startProcess('some-process');

        $metric = $finish->createMetric(new Exception('sample exception'));

        $this->assertEquals('some-process', $metric['e.name']);
        $this->assertArrayHasKey('e.date', $metric);
        $this->assertArrayHasKey('e.user', $metric);
        $this->assertArrayHasKey('e.cxid', $metric);
        $this->assertEquals('e_err', $metric['e.status']);
        $this->assertStringContainsString('sample exception', $metric['e.rmk']);
        $this->assertStringContainsString('error:', $metric['e.rmk']);
    }

    /**
     * @test
     */
    function the_metrics_recorder_will_capture_logger_stats() { 

        $logger = new PsbLogger(new NullLogger);
        
        $mm = $this->getMetricsManager($logger);

        // This will not be included in the process metrics, because it 
        // happens before.
        $logger->incrementStat('my_unit', 50);

        $finish = $mm->startProcess('some-process');

        $logger->incrementStat('my_unit', 101);
        $logger->incrementStat('my_unit', 102);
        $logger->incrementStat('my_unit', 103);

        $metric = $finish->createMetric('some comment');

        // This wont be included as well, because it is outside of the loop.
        $logger->incrementStat('my_unit', 63);

        $this->assertEquals(101 + 102 + 103, $metric['my_unit']);
        $this->assertEquals('some comment', $metric['e.rmk']);
        $this->assertEquals('e_ok', $metric['e.status']);
    }

    /**
     * @test
     */
    function you_can_start_recording_a_process_for_a_connection() {

        $mm = $this->getMetricsManager();

        $myConnection = $this->createMock(ConnectionInterface::class);
        $myConnectionId = 'unit-test-client:unittest:a3819da';
        $myConnection->method('getId')->willReturn($myConnectionId);

        $metric = $mm->startProcessForConnection('some-process', $myConnection);

        $data = $metric->createMetric();
        $this->assertEquals($myConnectionId, $data['e.cxid']);

    }
}