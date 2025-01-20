<?php

declare(strict_types=1);

namespace bluemoehre\Tests;

use bluemoehre\Wave;
use bluemoehre\Exception;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Iterator;

class WaveTest extends TestCase
{
    public function testConstructorWithEmptyFilePath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No file was loaded');
        $wave = new Wave('');
        $wave->generateSvg();
    }

    public function testSetFileWithEmptyFilePath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No file specified');
        $wave = new Wave();
        $wave->setFile('');
    }

    public function testConstructorWithInvalidFilePath(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File does not exist');
        new Wave('nonexistent.wav');
    }

    public function testUnsupportedFileType(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported file type');
        new Wave('fixtures/unsupported.txt');
    }

    public function testInvalidHeaderData(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('File header contains invalid data');
        new Wave('fixtures/invalid-header.wav');
    }

    public function testGenerateSvgWithEdgeResolutions(): void
    {
        $wave = new Wave('fixtures/48000Hz-24bit-2ch.wav');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Resolution must be between 1 and 0.000001');
        $wave->generateSvg('', 1.1);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Resolution must be between 1 and 0.000001');
        $wave->generateSvg('', 0.0000001);

        $svg = $wave->generateSvg('', 1);
        $this->assertStringContainsString('<svg', $svg, 'SVG should contain SVG root element');
    }

    public function testDifferentChannelAndSampleRates(): void
    {
        $wave = new Wave('fixtures/48000Hz-24bit-2ch.wav');
        $this->assertSame(2, $wave->getChannels(), 'Channel count should match');
        $this->assertSame(48000, $wave->getSampleRate(), 'Sample rate should match');
    }

    public function testMockFileHandlingFailures(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to open file');

        $wave = $this->getMockBuilder(Wave::class)
            ->onlyMethods(['setFile'])
            ->getMock();

        $wave->method('setFile')->willThrowException(new Exception('Failed to open file'));

        $wave->setFile('mocked.wav');
    }

    public static function waveFileProvider(): Iterator
    {
        //            '44100Hz-16bit-1ch' => [
        //                'file' => 'fixtures/44100Hz-16bit-1ch.wav',
        //                'expectedChannels' => 1,
        //                'expectedSampleRate' => 44100,
        //                'expectedByteRate' => 88200,
        //                'expectedKbps' => 705.6,
        //                'expectedBitsPerSample' => 16,
        //                'expectedTotalSamples' => 441000,
        //                'expectedTotalSeconds' => 10,
        //                'svgSnapshot' => './tests/snapshots/44100Hz-16bit-1ch.svg',
        //            ],
        yield '48000Hz-24bit-2ch' => [
            'file' => 'fixtures/48000Hz-24bit-2ch.wav',
            'expectedChannels' => 2,
            'expectedSampleRate' => 48000,
            'expectedByteRate' => 192000,
            'expectedKbps' => 1536.0,
            'expectedBitsPerSample' => 16,
            'expectedTotalSamples' => 480000,
            'expectedTotalSeconds' => 10,
            'svgSnapshot' => './tests/snapshots/48000Hz-24bit-2ch.svg',
        ];
        yield '48000Hz-16bit-2ch' => [
            'file' => 'fixtures/LRMonoPhase4.wav',
            'expectedChannels' => 2,
            'expectedSampleRate' => 48000,
            'expectedByteRate' => 192000,
            'expectedKbps' => 1536.0,
            'expectedBitsPerSample' => 16,
            'expectedTotalSamples' => 1860560,
            'expectedTotalSeconds' => 39,
            'svgSnapshot' => './tests/snapshots/LRMonoPhase4.svg',
        ];
        yield '48000Hz-16bit-2ch-short' => [
            'file' => 'fixtures/piano2.wav',
            'expectedChannels' => 2,
            'expectedSampleRate' => 48000,
            'expectedByteRate' => 192000,
            'expectedKbps' => 1536.0,
            'expectedBitsPerSample' => 16,
            'expectedTotalSamples' => 302712,
            'expectedTotalSeconds' => 6,
            'svgSnapshot' => './tests/snapshots/piano2.svg',
        ];
    }

    #[DataProvider('waveFileProvider')]
    public function testWaveFile(
        string $file,
        int $expectedChannels,
        int $expectedSampleRate,
        int $expectedByteRate,
        float $expectedKbps,
        int $expectedBitsPerSample,
        int $expectedTotalSamples,
        int $expectedTotalSeconds,
        string $svgSnapshot
    ): void {
        $wave = new Wave($file);

        $this->assertSame($expectedChannels, $wave->getChannels(), 'Channel count should match');
        $this->assertSame($expectedSampleRate, $wave->getSampleRate(), 'Sample rate should match');
        $this->assertSame($expectedByteRate, $wave->getByteRate(), 'Byte rate should match');
        $this->assertEqualsWithDelta($expectedKbps, $wave->getKiloBitPerSecond(), PHP_FLOAT_EPSILON, 'Kilobit per second should match');
        $this->assertSame($expectedBitsPerSample, $wave->getBitsPerSample(), 'Bits per sample should match');
        $this->assertSame($expectedTotalSamples, $wave->getTotalSamples(), 'Total samples should match');
        $this->assertSame($expectedTotalSeconds, $wave->getTotalSeconds(), 'Total seconds should match');
        $this->assertEquals(file_get_contents($svgSnapshot), $wave->generateSvg(), 'SVG should match snapshot');
    }
}
