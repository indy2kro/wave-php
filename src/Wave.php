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
        if ($chunkId === false || $chunkId !== 'RIFF') {
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
        if ($format === false || $format !== 'WAVE') {
            throw new Exception('Unsupported file format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->format = $format;

        $subChunk1Id = fread($fileHandle, 4);
        if ($subChunk1Id === false || $subChunk1Id !== 'fmt ') {
            throw new Exception('Unsupported file format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->subChunk1Id = $subChunk1Id;

        $subChunk1 = fread($fileHandle, 20);
        if ($subChunk1 === false) {
            throw new RuntimeException('Failed to read sub chunk 1 from file', self::ERR_FILE_READ);
        }

        $subChunk1Unpacked = unpack('VsubChunk1Size/vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $subChunk1);

        if ($subChunk1Unpacked === false) {
            throw new RuntimeException('Failed to unpack sub chunk 1', self::ERR_FILE_READ);
        }
        $this->subChunk1Size = $subChunk1Unpacked['subChunk1Size'];

        if ($subChunk1Unpacked['audioFormat'] !== 1) {
            throw new Exception('Unsupported audio format', self::ERR_FILE_INCOMPATIBLE);
        }

        $this->audioFormat = $subChunk1Unpacked['audioFormat'];
        $this->channels = $subChunk1Unpacked['channels'];
        $this->sampleRate = $subChunk1Unpacked['sampleRate'];
        $this->byteRate = $subChunk1Unpacked['byteRate'];
        $this->blockAlign = $subChunk1Unpacked['blockAlign'];
        $this->bitsPerSample = $subChunk1Unpacked['bitsPerSample'];

        if ($this->byteRate !== $this->sampleRate * $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: byte rate does not match', self::ERR_FILE_HEADER);
        }

        if ($this->blockAlign !== $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: block align does not match', self::ERR_FILE_HEADER);
        }

        // Skip irrelevant chunks until "data" chunk is found
        do {
            $subChunkId = fread($fileHandle, 4);
            if ($subChunkId === false || strlen($subChunkId) < 4) {
                throw new RuntimeException('Failed to read sub chunk id from file', self::ERR_FILE_READ);
            }

            $subChunkSizeData = fread($fileHandle, 4);
            if ($subChunkSizeData === false || strlen($subChunkSizeData) < 4) {
                throw new RuntimeException('Failed to read sub chunk size from file', self::ERR_FILE_READ);
            }

            $subChunkSizeUnpacked = unpack('VsubChunkSize', $subChunkSizeData);
            if ($subChunkSizeUnpacked === false || ! isset($subChunkSizeUnpacked['subChunkSize'])) {
                throw new RuntimeException('Failed to unpack sub chunk size', self::ERR_FILE_READ);
            }
            $subChunkSize = $subChunkSizeUnpacked['subChunkSize'];

            if ($subChunkId !== 'data') {
                // Skip non-data chunk
                if ($subChunkSize < 0) {
                    throw new RuntimeException('Invalid sub chunk size encountered', self::ERR_FILE_HEADER);
                }
                if (fseek($fileHandle, $subChunkSize, SEEK_CUR) === -1) {
                    throw new RuntimeException('Failed to seek in file', self::ERR_FILE_READ);
                }
            }
        } while ($subChunkId !== 'data');

        $dataOffset = ftell($fileHandle);

        if ($dataOffset === false) {
            throw new RuntimeException('Failed to tell position in file', self::ERR_FILE_READ);
        }

        $this->subChunk2Size = $subChunkSize;
        $this->dataOffset = $dataOffset;

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
            if ($outputFileHandle === false) {
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

        if ($this->bitsPerSample <= 0) {
            throw new RuntimeException('Invalid value for bitsPerSample', self::ERR_FILE_READ);
        }

        $samples = [];
        while (($data = fread($fileHandle, $this->bitsPerSample))) {
            $sample = unpack('svol', $data);

            if ($sample === false) {
                throw new RuntimeException('Failed to unpack summary', self::ERR_FILE_READ);
            }
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
