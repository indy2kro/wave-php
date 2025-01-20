<?php

declare(strict_types=1);

namespace bluemoehre;

use Exception;
use OutOfRangeException;
use RuntimeException;
use UnexpectedValueException;

/**
 * @license GNU General Public License v2 http://www.gnu.org/licenses/gpl-2.0
 *
 * @author BlueMöhre <bluemoehre@gmx.de>
 *
 * @copyright 2012-2016 BlueMöhre
 *
 * @link http://www.github.com/bluemoehre
 */
class Wave
{
    public const ERR_PARAM_VALUE = 1;
    public const ERR_FILE_ACCESS = 2;
    public const ERR_FILE_READ = 3;
    public const ERR_FILE_WRITE = 4;
    public const ERR_FILE_CLOSE = 5;
    public const ERR_FILE_INCOMPATIBLE = 6;
    public const ERR_FILE_HEADER = 7;

    public const SVG_DEFAULT_RESOLUTION_FACTOR = 0.01;

    protected ?string $file = null;

    protected string $chunkId;

    protected int $chunkSize;

    protected string $format;

    protected string $subChunk1Id;

    protected int $subChunk1Size;

    protected int $audioFormat;

    protected int $channels;

    protected int $sampleRate;

    protected int $byteRate;

    protected int $blockAlign;

    protected int $bitsPerSample;

    protected int $subChunk2Size;

    protected int $dataOffset;

    protected float $kiloBitPerSecond;

    protected int $totalSamples;

    protected int $totalSeconds;

    public function __construct(string $file = '')
    {
        if ($file !== '') {
            $this->setFile($file);
        }
    }

    /**
     * @throws Exception
     */
    public function setFile(string $file): void
    {
        if ($file === '') {
            throw new UnexpectedValueException('No file specified', self::ERR_PARAM_VALUE);
        }
        if (! file_exists($file)) {
            throw new RuntimeException('File does not exist', self::ERR_PARAM_VALUE);
        }
        $fileHandle = fopen($file, 'r');
        if ($fileHandle === false) {
            throw new RuntimeException('Failed to open file for reading', self::ERR_FILE_ACCESS);
        }
        $this->file = $file;

        $chunkId = fread($fileHandle, 4);
        if ($chunkId === false) {
            throw new RuntimeException('Failed to read chunk id from file', self::ERR_FILE_READ);
        }
        if ($chunkId !== 'RIFF') {
            throw new Exception('Unsupported file type', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->chunkId = $chunkId;

        $chunkSize = fread($fileHandle, 4);
        if ($chunkSize === false) {
            throw new RuntimeException('Failed to read chunk size from file', self::ERR_FILE_READ);
        }

        $chunkSizeUnpacked = unpack('VchunkSize', $chunkSize);

        if ($chunkSizeUnpacked === false) {
            throw new RuntimeException('Failed to unpack chunk size', self::ERR_FILE_READ);
        }

        $this->chunkSize = $chunkSizeUnpacked['chunkSize'];

        $format = fread($fileHandle, 4);
        if ($format === false) {
            throw new RuntimeException('Failed to read format from file', self::ERR_FILE_READ);
        }
        if ($format !== 'WAVE') {
            throw new Exception('Unsupported file format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->format = $format;

        $subChunk1Id = fread($fileHandle, 4);
        if ($subChunk1Id === false) {
            throw new RuntimeException('Failed to read sub chunk 1 id from file', self::ERR_FILE_READ);
        }
        if ($subChunk1Id !== 'fmt ') {
            throw new Exception('Unsupported file format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->subChunk1Id = $subChunk1Id;

        $offset = ftell($fileHandle);
        $subChunk1 = fread($fileHandle, 20);
        if ($subChunk1 === false) {
            throw new RuntimeException('Failed to read sub chunk 1 from file', self::ERR_FILE_READ);
        }
        $subChunk1 = unpack('VsubChunk1Size/vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $subChunk1);
        $this->subChunk1Size = $subChunk1['subChunk1Size'];
        $offset += 4;
        if ($subChunk1['audioFormat'] !== 1) {
            throw new Exception('Unsupported audio format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->audioFormat = $subChunk1['audioFormat'];
        $this->channels = $subChunk1['channels'];
        $this->sampleRate = $subChunk1['sampleRate'];
        $this->byteRate = $subChunk1['byteRate'];
        $this->blockAlign = $subChunk1['blockAlign'];
        $this->bitsPerSample = $subChunk1['bitsPerSample'];
        if ($this->byteRate !== $this->sampleRate * $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: byte rate does not match', self::ERR_FILE_HEADER);
        }
        if ($this->blockAlign !== $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: block align does not match', self::ERR_FILE_HEADER);
        }

        if (fseek($fileHandle, $offset + $this->subChunk1Size) === -1) {
            throw new RuntimeException('Failed to seek in file', self::ERR_FILE_READ);
        }
        $subChunk2Id = fread($fileHandle, 4);
        if ($subChunk2Id === false) {
            throw new RuntimeException('Failed to read sub chunk 2 id from file', self::ERR_FILE_READ);
        }
        if ($subChunk2Id !== 'data') {
            throw new Exception('File header contains invalid data', self::ERR_FILE_HEADER);
        }

        $subChunk2 = fread($fileHandle, 4);
        if ($subChunk2 === false) {
            throw new RuntimeException('Failed to read sub chunk 2 from file', self::ERR_FILE_READ);
        }
        $subChunk2 = unpack('VdataSize', $subChunk2);
        $this->subChunk2Size = $subChunk2['dataSize'];
        $this->dataOffset = ftell($fileHandle);

        $this->kiloBitPerSecond = $this->byteRate * 8 / 1000;
        $this->totalSamples = $this->subChunk2Size * 8 / $this->bitsPerSample / $this->channels;
        $this->totalSeconds = (int) round($this->subChunk2Size / $this->byteRate);

        if (! fclose($fileHandle)) {
            throw new RuntimeException('Failed to close file', self::ERR_FILE_CLOSE);
        }
    }

    /**
     * TODO verify calculations
     *
     * @param float $resolution - Must be <=1. If 1 SVG will be full waveform resolution (amazing large filesize)
     *
     * @throws Exception
     */
    public function generateSvg(string $outputFile = '', float $resolution = self::SVG_DEFAULT_RESOLUTION_FACTOR): string
    {
        $outputFileHandle = null;

        if ($outputFile !== '') {
            $outputFileHandle = fopen($outputFile, 'w');
            if (! $outputFileHandle) {
                throw new RuntimeException('Failed to open output file for writing', self::ERR_FILE_ACCESS);
            }
        }

        if ($resolution > 1.0 || $resolution < 0.000001) {
            throw new OutOfRangeException('Resolution must be between 1 and 0.000001', self::ERR_PARAM_VALUE);
        }

        if ($this->file === null) {
            throw new Exception('No file was loaded', self::ERR_FILE_ACCESS);
        }
        $fileHandle = fopen($this->file, 'r');
        if (! $fileHandle) {
            throw new RuntimeException('Failed to open file', self::ERR_FILE_ACCESS);
        }

        $sampleSummaryLength = $this->sampleRate / ($resolution * $this->sampleRate);
        $sampleSummaries = [];
        $i = 0;
        if (fseek($fileHandle, $this->dataOffset) === -1) {
            throw new RuntimeException('Failed to seek in file', self::ERR_FILE_READ);
        }

        $samples = [];
        while (($data = fread($fileHandle, $this->bitsPerSample))) {
            $sample = unpack('svol', $data);
            $samples[] = $sample['vol'];

            // when all samples for a summary are collected, get lows & peaks
            if ($i > 0 && $i % $sampleSummaryLength === 0) {
                $minValue = min($samples);
                $maxValue = max($samples);
                $sampleSummaries[] = [$minValue, $maxValue];
                $samples = []; // reset
            }
            $i++;

            // TODO analyze side effects and remove
            // skip to increase speed
            if (fseek($fileHandle, $this->bitsPerSample * $this->channels * 3, SEEK_CUR) === -1) {
                throw new RuntimeException('Failed to seek in file', self::ERR_FILE_READ);
            }
        }

        if (! fclose($fileHandle)) {
            throw new RuntimeException('Failed to close file', self::ERR_FILE_CLOSE);
        }

        $minPossibleValue = 2 ** $this->bitsPerSample / 2 * -1;
        $maxPossibleValue = $minPossibleValue * -1 - 1;
        $range = 2 ** $this->bitsPerSample;
        $svgPathTop = '';
        $svgPathBottom = '';

        foreach ($sampleSummaries as $x => $sampleMinMax) {
            # TODO configurable vertical detail
            $y = round(100 / $range * ($maxPossibleValue - $sampleMinMax[1]));
            $svgPathTop .= "L{$x} {$y}";
            # TODO configurable vertical detail
            $y = round(100 / $range * ($maxPossibleValue + $sampleMinMax[0] * -1));
            $svgPathBottom = "L{$x} {$y}" . $svgPathBottom;
        }

        // TODO move gradient to stylesheet
        // TODO this should be improved to use kinda template
        $svg =
        '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="' . count($sampleSummaries) . 'px" height="100px" preserveAspectRatio="none">
    <defs>
        <linearGradient id="gradient" x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" style="stop-color:rgb(0,0,0);stop-opacity:1"/>
            <stop offset="50%" style="stop-color:rgb(50,50,50);stop-opacity:1"/>
            <stop offset="100%" style="stop-color:rgb(0,0,0);stop-opacity:1"/>
        </linearGradient>
    </defs>
    <path d="M0 50' . $svgPathTop . $svgPathBottom . 'L0 50 Z" fill="url(#gradient)"/>
</svg>';

        if ($outputFileHandle) {
            if (fwrite($outputFileHandle, $svg) === false) {
                throw new RuntimeException('Failed to write to output file', self::ERR_FILE_WRITE);
            }
            if (! fclose($outputFileHandle)) {
                throw new RuntimeException('Failed to close output file', self::ERR_FILE_CLOSE);
            }
        }

        return $svg;
    }

    public function getChannels(): int
    {
        return $this->channels;
    }

    public function getSampleRate(): int
    {
        return $this->sampleRate;
    }

    public function getByteRate(): int
    {
        return $this->byteRate;
    }

    public function getKiloBitPerSecond(): float
    {
        return $this->kiloBitPerSecond;
    }

    public function getBitsPerSample(): int
    {
        return $this->bitsPerSample;
    }

    public function getTotalSamples(): int
    {
        return $this->totalSamples;
    }

    public function getTotalSeconds(): int
    {
        return $this->totalSeconds;
    }
}
