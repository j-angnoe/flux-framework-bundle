<?php

use Flux\Framework\EventStream\EventStreamService;
use Flux\Framework\EventStream\NullUpdateStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class EventStreamServiceTest extends TestCase
{
    /**
     * @test
     */
    public function it_recognized_non_eventstream_requests()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);

        $this->assertFalse($eventStreamService->isEventStreamRequest());
    }

    /**
     * @test
     */
    public function it_recognized_real_eventstream_requests()
    {
        $headers = [
            'HTTP_ACCEPT' => 'text/event-stream',
        ];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);
        $eventStreamService = new EventStreamService($request);

        $this->assertTrue($eventStreamService->isEventStreamRequest());
    }

    /**
     * @test
     */
    public function it_will_throw_when_trying_to_start_on_a_non_eventstream_request()
    {
        $this->expectException(Exception::class);

        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);
        $eventStreamService = new EventStreamService($request);
        $eventStreamService->start(exit: false);
    }

    /**
     * @test
     */
    public function it_can_send_a_sse_data_message()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);
        ob_start();
        $eventStreamService->send('blabla');
        $content = ob_get_clean() ?: '';

        // It contains the data message
        $this->assertStringContainsString("data: blabla\n", $content);

        // It also contains the proper sse message boundary.
        $this->assertStringContainsString("\n\n", $content);
    }

    /**
     * @test
     */
    public function it_can_send_a_sse_data_multi_line_message()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);
        ob_start();
        $eventStreamService->send("line one\nline two\nline three\n");
        $content = ob_get_clean() ?: '';

        // It contains the data message
        $this->assertStringContainsString("data: line one\n", $content);
        $this->assertStringContainsString("data: line two\n", $content);
        $this->assertStringContainsString("data: line three\n", $content);

        // It also contains the proper sse message boundary.
        $this->assertStringContainsString("\n\n", $content);
    }
    /**
     * @test
     */
    public function it_can_send_a_sse_data_message_with_position()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);
        ob_start();
        $eventStreamService->send('blabla', 101);
        $content = ob_get_clean() ?: '';

        // It contains the data message
        $this->assertStringContainsString("id: 0:101\n", $content);
    }

    /**
     * @test
     */
    public function it_can_send_a_sse_custom_event()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);
        ob_start();
        $eventStreamService->sendEvent('blabla_event', 'blabla_data', 102);
        $content = ob_get_clean() ?: '';

        // It contains the data message
        $this->assertStringContainsString("event: blabla_event\n", $content);
        $this->assertStringContainsString("data: \"blabla_data\"\n", $content);
        $this->assertStringContainsString("id: 0:102\n", $content);

        // It also contains the proper sse message boundary.
        $this->assertStringContainsString("\n\n", $content);
    }


    /**
     * @test
     */
    public function sse_custom_event_data_is_always_json_encoded()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);
        ob_start();
        $eventStreamService->sendEvent('blabla_event', ['blab' => 'blabvalue'], 102);
        $content = ob_get_clean() ?: '';

        // It contains the data message
        $this->assertStringContainsString("event: blabla_event\n", $content);
        $this->assertStringContainsString("data: {\"blab\":\"blabvalue\"}\n", $content);
        $this->assertStringContainsString("id: 0:102\n", $content);

        // It also contains the proper sse message boundary.
        $this->assertStringContainsString("\n\n", $content);
    }


    /**
     * @test
     */
    public function it_sends_multiple_stream_offsets()
    {
        $headers = [];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);

        $eventStreamService = new EventStreamService($request);
        ob_start();
        $eventStreamService->setStreamOffset('S1_P101');
        $eventStreamService->setStreamOffset('S2_P33', 1);
        $eventStreamService->setStreamOffset('S3_P44', 2);

        $eventStreamService->sendOffset();
        $content = ob_get_clean() ?: '';

        // It contains the data message
        $this->assertStringContainsString("id: 0:S1_P101;1:S2_P33;2:S3_P44\n", $content);
    }

    /**
     * @test
     */
    public function it_knows_about_the_offsets_to_resume_from()
    {
        $headers = [
            'HTTP_ACCEPT' => 'text/event-stream',
            'HTTP_LAST_EVENT_ID' => '0:S1_P101;1:S2_P33;2:S3_P44',
        ];
        $request = Request::create('/whatever', 'GET', [], [], [], $headers);
        
        ob_start();
        $eventStreamService = new EventStreamService($request);
        $eventStreamService->register(new NullUpdateStream());
        $eventStreamService->start(exit: false);

        ob_end_clean();

        $this->assertEquals('S1_P101', $eventStreamService->getStreamOffset(0));
        $this->assertEquals('S2_P33', $eventStreamService->getStreamOffset(1));
        $this->assertEquals('S3_P44', $eventStreamService->getStreamOffset(2));
    }
}
