<?php

declare(strict_types=1);

namespace bluemoehre;

/**
 * Generates SVG representations of WAV file data.
 */
class SvgGenerator
{
    public const SVG_DEFAULT_RESOLUTION_FACTOR = 0.01;

    public function __construct(private readonly WaveData $waveData, private readonly WaveHeader $header, private readonly FileHandler $fileHandler)
    {
    }

    public function generateSvg(string $outputFile = '', float $resolution = self::SVG_DEFAULT_RESOLUTION_FACTOR): string
    {
        $outputFileHandle = $this->openOutputFile($outputFile);
        $this->validateResolution($resolution);

        $fileHandle = $this->fileHandler->openFile();
        $sampleSummaries = $this->collectSampleSummaries($fileHandle, $resolution);
        $this->fileHandler->closeFile($fileHandle);

        $svg = $this->createSvg($sampleSummaries);

        if ($outputFileHandle) {
            $this->writeToFile($outputFileHandle, $svg);
        }

        return $svg;
    }

    /**
     * @return resource|null
     *
     * @throws Exception
     */
    private function openOutputFile(string $outputFile): mixed
    {
        if ($outputFile === '') {
            return null;
        }

        $outputFileHandle = fopen($outputFile, 'w');
        if ($outputFileHandle === false) {
            throw new Exception('Failed to open output file for writing', ErrorCodes::ERR_FILE_ACCESS);
        }

        return $outputFileHandle;
    }

    private function validateResolution(float $resolution): void
    {
        if ($resolution > 1.0 || $resolution < 0.000001) {
            throw new Exception('Resolution must be between 1 and 0.000001', ErrorCodes::ERR_PARAM_VALUE);
        }
    }

    /**
     * @param resource $fileHandle
     *
     * @return array<int, array<int, int>>
     *
     * @throws Exception
     */
    private function collectSampleSummaries($fileHandle, float $resolution): array
    {
        $sampleSummaryLength = $this->header->getSampleRate() / ($resolution * $this->header->getSampleRate());
        $sampleSummaries = [];
        $samples = [];
        $i = 0;

        if (fseek($fileHandle, $this->waveData->getDataOffset()) === -1) {
            throw new Exception('Failed to seek in file', ErrorCodes::ERR_FILE_READ);
        }

        if ($this->header->getBitsPerSample() <= 0) {
            throw new Exception('Invalid value for bitsPerSample', ErrorCodes::ERR_FILE_READ);
        }

        while (($data = fread($fileHandle, $this->header->getBitsPerSample()))) {
            /** @var array<string, int>|false $sample */
            $sample = unpack('svol', $data);
            if ($sample === false) {
                throw new Exception('Failed to unpack summary', ErrorCodes::ERR_FILE_READ);
            }
            $samples[] = (int) $sample['vol'];

            if ($i > 0 && $i % $sampleSummaryLength === 0) {
                $sampleSummaries[] = [min($samples), max($samples)];
                $samples = [];
            }
            $i++;

            if (fseek($fileHandle, $this->header->getBitsPerSample() * $this->header->getChannels() * 3, SEEK_CUR) === -1) {
                throw new Exception('Failed to seek in file', ErrorCodes::ERR_FILE_READ);
            }
        }

        return $sampleSummaries;
    }

    /**
     * @param array<int, array<int, int>> $sampleSummaries
     *
     * @throws Exception
     */
    private function createSvg(array $sampleSummaries): string
    {
        $minPossibleValue = (float) (2 ** $this->header->getBitsPerSample() / 2 * -1);
        $maxPossibleValue = (float) ($minPossibleValue * -1 - 1);
        $range = (float) (2 ** $this->header->getBitsPerSample());

        if ($range === 0.0) {
            throw new Exception('Invalid range value', ErrorCodes::ERR_FILE_READ);
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
     * @throws Exception
     */
    private function writeToFile(mixed $outputFileHandle, string $svg): void
    {
        if (fwrite($outputFileHandle, $svg) === false) {
            throw new Exception('Failed to write to output file', ErrorCodes::ERR_FILE_WRITE);
        }
        if (! fclose($outputFileHandle)) {
            throw new Exception('Failed to close output file', ErrorCodes::ERR_FILE_CLOSE);
        }
    }
}
