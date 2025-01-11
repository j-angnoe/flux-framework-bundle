<?php
/** for testing */
namespace Flux\Framework\EventStream;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class NullUpdateStream implements UpdateStream {
    function __construct(
        private string $channelName = 'message',
        private int $beatsPerMinute = 60,
        private int $updatesPerTick = 1
    ) { 

    }

    function endOfStream(): bool { 
        return true;
    }

    function nextUpdate(mixed $lastPosition): mixed
    {
        return null;
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