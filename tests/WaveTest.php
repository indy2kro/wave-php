<?php

declare(strict_types=1);

namespace bluemoehre\Tests;

use bluemoehre\Wave;
use PHPUnit\Framework\TestCase;

class WaveTest extends TestCase
{
    public function test(): void
    {
        $wave = new Wave('fixtures/44100Hz-16bit-1ch.wav');
        $this->assertSame(1, $wave->getChannels(), 'Channel count should match');
        $this->assertSame(44100, $wave->getSampleRate(), 'Sample rate should match');
        $this->assertSame(88200, $wave->getByteRate(), 'Byte rate should match');
        $this->assertEqualsWithDelta(705.6, $wave->getKiloBitPerSecond(), PHP_FLOAT_EPSILON, 'Kilobit per second should match');
        $this->assertSame(16, $wave->getBitsPerSample(), 'Bits per sample should match');
        $this->assertSame(441000, $wave->getTotalSamples(), 'Total samples should match');
        $this->assertSame(10, $wave->getTotalSeconds(), 'Total seconds should match');
        $this->assertEqualsWithDelta(10.0, $wave->getTotalSeconds(true), PHP_FLOAT_EPSILON, 'Total seconds with decimals should match');
        $this->assertEquals(file_get_contents('./tests/snapshots/44100Hz-16bit-1ch.svg'), $wave->generateSvg(), 'SVG should match snapshot');
    }
}
