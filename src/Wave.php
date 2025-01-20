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

    /** @throws Exception */
    public function setFile(string $file): void
    {
        $this->validateFile($file);
        $fileHandle = $this->openFile($file);

        $this->readChunkId($fileHandle);
        $this->readChunkSize($fileHandle);
        $this->readFormat($fileHandle);
        $this->readSubChunk1($fileHandle);
        $this->findDataChunk($fileHandle);

        if (! fclose($fileHandle)) {
            throw new RuntimeException('Failed to close file', self::ERR_FILE_CLOSE);
        }
    }
    public function generateSvg(string $outputFile = '', float $resolution = self::SVG_DEFAULT_RESOLUTION_FACTOR): string
    {
        $outputFileHandle = $this->openOutputFile($outputFile);
        $this->validateResolution($resolution);
        $fileHandle = $this->openInputFile();

        $sampleSummaries = $this->collectSampleSummaries($fileHandle, $resolution);
        fclose($fileHandle);

        $svg = $this->createSvg($sampleSummaries);

        if ($outputFileHandle) {
            $this->writeToFile($outputFileHandle, $svg);
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

    /**
     * @return resource|null
     *
     * @throws RuntimeException
     */
    private function openOutputFile(string $outputFile): mixed
    {
        if ($outputFile === '') {
            return null;
        }

        $outputFileHandle = fopen($outputFile, 'w');
        if ($outputFileHandle === false) {
            throw new RuntimeException('Failed to open output file for writing', self::ERR_FILE_ACCESS);
        }

        return $outputFileHandle;
    }

    private function validateResolution(float $resolution): void
    {
        if ($resolution > 1.0 || $resolution < 0.000001) {
            throw new OutOfRangeException('Resolution must be between 1 and 0.000001', self::ERR_PARAM_VALUE);
        }
    }

    /**
     * @return resource
     *
     * @throws Exception
     * @throws RuntimeException
     */
    private function openInputFile(): mixed
    {
        if ($this->file === null) {
            throw new Exception('No file was loaded', self::ERR_FILE_ACCESS);
        }

        $fileHandle = fopen($this->file, 'r');
        if (! $fileHandle) {
            throw new RuntimeException('Failed to open file', self::ERR_FILE_ACCESS);
        }

        return $fileHandle;
    }

    /**
     * @param resource $fileHandle
     *
     * @return array<int, array<int, int>>
     *
     * @throws RuntimeException
     */
    private function collectSampleSummaries(mixed $fileHandle, float $resolution): array
    {
        $sampleSummaryLength = $this->sampleRate / ($resolution * $this->sampleRate);
        $sampleSummaries = [];
        $samples = [];
        $i = 0;

        if (fseek($fileHandle, $this->dataOffset) === -1) {
            throw new RuntimeException('Failed to seek in file', self::ERR_FILE_READ);
        }

        if ($this->bitsPerSample <= 0) {
            throw new RuntimeException('Invalid value for bitsPerSample', self::ERR_FILE_READ);
        }

        while (($data = fread($fileHandle, $this->bitsPerSample))) {
            /** @var array<string, int>|false $sample */
            $sample = unpack('svol', $data);
            if ($sample === false) {
                throw new RuntimeException('Failed to unpack summary', self::ERR_FILE_READ);
            }
            $samples[] = (int) $sample['vol'];

            if ($i > 0 && $i % $sampleSummaryLength === 0) {
                $sampleSummaries[] = [min($samples), max($samples)];
                $samples = [];
            }
            $i++;

            if (fseek($fileHandle, $this->bitsPerSample * $this->channels * 3, SEEK_CUR) === -1) {
                throw new RuntimeException('Failed to seek in file', self::ERR_FILE_READ);
            }
        }

        return $sampleSummaries;
    }

    /**
     * @param array<int, array<int, int>> $sampleSummaries
     *
     * @throws RuntimeException
     */
    private function createSvg(array $sampleSummaries): string
    {
        $minPossibleValue = (float) (2 ** $this->bitsPerSample / 2 * -1);
        $maxPossibleValue = (float) ($minPossibleValue * -1 - 1);
        $range = (float) (2 ** $this->bitsPerSample);

        if ($range === 0.0) {
            throw new RuntimeException('Invalid range value', self::ERR_FILE_READ);
        }

        $svgPathTop = '';
        $svgPathBottom = '';

        foreach ($sampleSummaries as $x => $sampleMinMax) {
            if ($sampleMinMax[0] === 0 || $sampleMinMax[1] === 0) {
                continue;
            }

            $yTop = round(100 / $range * ($maxPossibleValue - $sampleMinMax[1]));
            $svgPathTop .= "L{$x} {$yTop}";

            $yBottom = round(100 / $range * ($maxPossibleValue + $sampleMinMax[0] * -1));
            $svgPathBottom = "L{$x} {$yBottom}" . $svgPathBottom;
        }

        return '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="' . count($sampleSummaries) . 'px" height="100px" preserveAspectRatio="none">
    <defs>
        <linearGradient id="gradient" x1="0%" y1="0%" x2="0%" y2="100%">
            <stop offset="0%" style="stop-color:rgb(0,0,0);stop-opacity:1"/>
            <stop offset="50%" style="stop-color:rgb(50,50,50);stop-opacity:1"/>
            <stop offset="100%" style="stop-color:rgb(0,0,0);stop-opacity:1"/>
        </linearGradient>
    </defs>
    <path d="M0 50' . $svgPathTop . $svgPathBottom . 'L0 50 Z" fill="url(#gradient)"/>
</svg>';
    }

    /**
     * @param resource $outputFileHandle
     *
     * @throws RuntimeException
     */
    private function writeToFile(mixed $outputFileHandle, string $svg): void
    {
        if (fwrite($outputFileHandle, $svg) === false) {
            throw new RuntimeException('Failed to write to output file', self::ERR_FILE_WRITE);
        }
        if (! fclose($outputFileHandle)) {
            throw new RuntimeException('Failed to close output file', self::ERR_FILE_CLOSE);
        }
    }

    /** @throws UnexpectedValueException */
    private function validateFile(string $file): void
    {
        if ($file === '') {
            throw new UnexpectedValueException('No file specified', self::ERR_PARAM_VALUE);
        }
        if (! file_exists($file)) {
            throw new RuntimeException('File does not exist', self::ERR_PARAM_VALUE);
        }
    }

    /**
     * @return resource
     *
     * @throws RuntimeException
     */
    private function openFile(string $file): mixed
    {
        $fileHandle = fopen($file, 'r');
        if ($fileHandle === false) {
            throw new RuntimeException('Failed to open file for reading', self::ERR_FILE_ACCESS);
        }
        $this->file = $file;
        return $fileHandle;
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readChunkId(mixed $fileHandle): void
    {
        $chunkId = fread($fileHandle, 4);
        if ($chunkId === false || $chunkId !== 'RIFF') {
            throw new Exception('Unsupported file type', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->chunkId = $chunkId;
    }

    /**
     * @param resource $fileHandle
     *
     * @throws RuntimeException
     */
    private function readChunkSize(mixed $fileHandle): void
    {
        $chunkSize = fread($fileHandle, 4);
        if ($chunkSize === false) {
            throw new RuntimeException('Failed to read chunk size from file', self::ERR_FILE_READ);
        }

        /** @var array<string, int>|false $chunkSizeUnpacked */
        $chunkSizeUnpacked = unpack('VchunkSize', $chunkSize);
        if ($chunkSizeUnpacked === false) {
            throw new RuntimeException('Failed to unpack chunk size', self::ERR_FILE_READ);
        }
        $this->chunkSize = (int) $chunkSizeUnpacked['chunkSize'];
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readFormat(mixed $fileHandle): void
    {
        $format = fread($fileHandle, 4);
        if ($format === false || $format !== 'WAVE') {
            throw new Exception('Unsupported file format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->format = $format;
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readSubChunk1(mixed $fileHandle): void
    {
        $subChunk1Id = fread($fileHandle, 4);
        if ($subChunk1Id === false || $subChunk1Id !== 'fmt ') {
            throw new Exception('Unsupported file format', self::ERR_FILE_INCOMPATIBLE);
        }
        $this->subChunk1Id = $subChunk1Id;

        $subChunk1 = fread($fileHandle, 20);
        if ($subChunk1 === false) {
            throw new RuntimeException('Failed to read sub chunk 1 from file', self::ERR_FILE_READ);
        }

        /** @var array<string, int>|false $subChunk1Unpacked */
        $subChunk1Unpacked = unpack('VsubChunk1Size/vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $subChunk1);
        if ($subChunk1Unpacked === false) {
            throw new RuntimeException('Failed to unpack sub chunk 1', self::ERR_FILE_READ);
        }

        $this->subChunk1Size = (int) $subChunk1Unpacked['subChunk1Size'];
        if ($subChunk1Unpacked['audioFormat'] !== 1) {
            throw new Exception('Unsupported audio format', self::ERR_FILE_INCOMPATIBLE);
        }

        $this->audioFormat = $subChunk1Unpacked['audioFormat'];
        $this->channels = (int) $subChunk1Unpacked['channels'];
        $this->sampleRate = (int) $subChunk1Unpacked['sampleRate'];
        $this->byteRate = (int) $subChunk1Unpacked['byteRate'];
        $this->blockAlign = (int) $subChunk1Unpacked['blockAlign'];
        $this->bitsPerSample = (int) $subChunk1Unpacked['bitsPerSample'];

        $this->validateHeader();
    }

    /** @throws Exception */
    private function validateHeader(): void
    {
        if ($this->byteRate !== $this->sampleRate * $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: byte rate does not match', self::ERR_FILE_HEADER);
        }

        if ($this->blockAlign !== $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: block align does not match', self::ERR_FILE_HEADER);
        }
    }

    /**
     * @param resource $fileHandle
     *
     * @throws RuntimeException
     */
    private function findDataChunk(mixed $fileHandle): void
    {
        do {
            $subChunkId = fread($fileHandle, 4);
            if ($subChunkId === false || strlen($subChunkId) < 4) {
                throw new RuntimeException('Failed to read sub chunk id from file', self::ERR_FILE_READ);
            }

            $subChunkSizeData = fread($fileHandle, 4);
            if ($subChunkSizeData === false || strlen($subChunkSizeData) < 4) {
                throw new RuntimeException('Failed to read sub chunk size from file', self::ERR_FILE_READ);
            }

            /** @var array<string, int>|false $subChunkSizeUnpacked */
            $subChunkSizeUnpacked = unpack('VsubChunkSize', $subChunkSizeData);
            if ($subChunkSizeUnpacked === false || ! isset($subChunkSizeUnpacked['subChunkSize'])) {
                throw new RuntimeException('Failed to unpack sub chunk size', self::ERR_FILE_READ);
            }
            $subChunkSize = (int) $subChunkSizeUnpacked['subChunkSize'];

            if ($subChunkId !== 'data') {
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
        $this->totalSamples = (int) ($this->subChunk2Size * 8 / $this->bitsPerSample / $this->channels);
        $this->totalSeconds = (int) round($this->subChunk2Size / $this->byteRate);
    }
}
