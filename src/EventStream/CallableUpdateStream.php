<?php

namespace Flux\Framework\EventStream;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class CallableUpdateStream implements UpdateStream {
    function __construct(
        private \Closure $callable,
        private string $channelName = 'message',
        private int $beatsPerMinute = 60,
        private int $updatesPerTick = 1
    ) { 

    }

    function nextUpdate(mixed $lastPosition): mixed
    {
        return ($this->callable)($lastPosition);
    }

    function channelName(): string
    {
        return $this->channelName;
    }

    function beatsPerMinute(): int
    {
        return $this->beatsPerMinute;
    }

    function updatesPerTick(): int
    {
        return $this->updatesPerTick;
    }

}