<?php

declare(strict_types=1);

namespace bluemoehre;

/**
 * Main class for handling WAV file operations and SVG generation.
 */
class Wave
{
    private readonly FileHandler $fileHandler;
    private readonly WaveHeader $waveHeader;
    private readonly WaveData $waveData;
    private readonly SvgGenerator $svgGenerator;

    public function __construct(string $file = '')
    {
        $this->fileHandler = new FileHandler($file);
        $this->waveHeader = new WaveHeader();
        $this->waveData = new WaveData();
        $this->svgGenerator = new SvgGenerator($this->waveData, $this->waveHeader, $this->fileHandler);

        if ($file !== '') {
            $this->setFile($file);
        }
    }

    /** @throws Exception */
    public function setFile(string $file): void
    {
        $this->fileHandler->setFile($file);
        $fileHandle = $this->fileHandler->openFile();

        $this->waveHeader->readHeader($fileHandle);
        $this->waveData->findDataChunk($fileHandle, $this->waveHeader);

        $this->fileHandler->closeFile($fileHandle);
    }

    public function generateSvg(string $outputFile = '', float $resolution = SvgGenerator::SVG_DEFAULT_RESOLUTION_FACTOR): string
    {
        return $this->svgGenerator->generateSvg($outputFile, $resolution);
    }

    public function getChannels(): int
    {
        return $this->waveHeader->getChannels();
    }

    public function getSampleRate(): int
    {
        return $this->waveHeader->getSampleRate();
    }

    public function getByteRate(): int
    {
        return $this->waveHeader->getByteRate();
    }

    public function getChunkSize(): int
    {
        return $this->waveHeader->getChunkSize();
    }

    public function getAudioFormat(): int
    {
        return $this->waveHeader->getAudioFormat();
    }

    public function getKiloBitPerSecond(): float
    {
        return $this->waveData->getKiloBitPerSecond();
    }

    public function getBitsPerSample(): int
    {
        return $this->waveHeader->getBitsPerSample();
    }

    public function getTotalSamples(): int
    {
        return $this->waveData->getTotalSamples();
    }

    public function getTotalSeconds(): int
    {
        return $this->waveData->getTotalSeconds();
    }
}
