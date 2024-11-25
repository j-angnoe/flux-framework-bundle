<?php

namespace Flux\Framework\EventStream;

interface UpdateStream
{
    public function channelName(): string;
    public function nextUpdate(mixed $lastPosition): mixed;
    public function beatsPerMinute(): int;
    public function updatesPerTick(): int;
}
