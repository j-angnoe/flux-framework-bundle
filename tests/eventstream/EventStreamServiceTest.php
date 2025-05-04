<?php

use Flux\Framework\EventStream\EventStreamService;
use Flux\Framework\EventStream\NullUpdateStream;
use Symfony\Component\HttpFoundation\Request;
it('recognized non eventstream requests', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);

    $eventStreamService = new EventStreamService($request);

    expect($eventStreamService->isEventStreamRequest())->toBeFalse();
});
it('recognized real eventstream requests', function () {
    $headers = [
        'HTTP_ACCEPT' => 'text/event-stream',
    ];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);
    $eventStreamService = new EventStreamService($request);

    expect($eventStreamService->isEventStreamRequest())->toBeTrue();
});
it('will throw when trying to start on a non eventstream request', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);
    $eventStreamService = new EventStreamService($request);
    $eventStreamService->start(exit: false);
})->throws(Exception::class);

it('can send a sse data message', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);

    $eventStreamService = new EventStreamService($request);
    ob_start();
    $eventStreamService->send('blabla');
    $content = ob_get_clean() ?: '';

    // It contains the data message
    expect($content)->toContain("data: blabla\n");

    // It also contains the proper sse message boundary.
    expect($content)->toContain("\n\n");
});
it('can send a sse data multi line message', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);

    $eventStreamService = new EventStreamService($request);
    ob_start();
    $eventStreamService->send("line one\nline two\nline three\n");
    $content = ob_get_clean() ?: '';

    // It contains the data message
    expect($content)->toContain("data: line one\n");
    expect($content)->toContain("data: line two\n");
    expect($content)->toContain("data: line three\n");

    // It also contains the proper sse message boundary.
    expect($content)->toContain("\n\n");
});
it('can send a sse data message with position', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);

    $eventStreamService = new EventStreamService($request);
    ob_start();
    $eventStreamService->send('blabla', 101);
    $content = ob_get_clean() ?: '';

    // It contains the data message
    expect($content)->toContain("id: 0:101\n");
});
it('can send a sse custom event', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);

    $eventStreamService = new EventStreamService($request);
    ob_start();
    $eventStreamService->sendEvent('blabla_event', 'blabla_data', 102);
    $content = ob_get_clean() ?: '';

    // It contains the data message
    expect($content)->toContain("event: blabla_event\n");
    expect($content)->toContain("data: \"blabla_data\"\n");
    expect($content)->toContain("id: 0:102\n");

    // It also contains the proper sse message boundary.
    expect($content)->toContain("\n\n");
});
test('sse custom event data is always json encoded', function () {
    $headers = [];
    $request = Request::create('/whatever', 'GET', [], [], [], $headers);

    $eventStreamService = new EventStreamService($request);
    ob_start();
    $eventStreamService->sendEvent('blabla_event', ['blab' => 'blabvalue'], 102);
    $content = ob_get_clean() ?: '';

    // It contains the data message
    expect($content)->toContain("event: blabla_event\n");
    expect($content)->toContain("data: {\"blab\":\"blabvalue\"}\n");
    expect($content)->toContain("id: 0:102\n");

    // It also contains the proper sse message boundary.
    expect($content)->toContain("\n\n");
});
it('sends multiple stream offsets', function () {
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
    expect($content)->toContain("id: 0:S1_P101;1:S2_P33;2:S3_P44\n");
});
it('knows about the offsets to resume from', function () {
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

    expect($eventStreamService->getStreamOffset(0))->toEqual('S1_P101');
    expect($eventStreamService->getStreamOffset(1))->toEqual('S2_P33');
    expect($eventStreamService->getStreamOffset(2))->toEqual('S3_P44');
});
