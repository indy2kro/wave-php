<?php

declare(strict_types=1);

namespace bluemoehre;

/**
 * Handles the data chunk of WAV files.
 */
class WaveData
{
    private float $kiloBitPerSecond;
    private int $totalSamples;
    private int $totalSeconds;
    private int $dataOffset;

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    public function findDataChunk(mixed $fileHandle, WaveHeader $header): void
    {
        do {
            $subChunkId = fread($fileHandle, 4);
            if ($subChunkId === false || strlen($subChunkId) < 4) {
                throw new Exception('Failed to read sub chunk id from file', ErrorCodes::ERR_FILE_READ);
            }

            $subChunkSizeData = fread($fileHandle, 4);
            if ($subChunkSizeData === false || strlen($subChunkSizeData) < 4) {
                throw new Exception('Failed to read sub chunk size from file', ErrorCodes::ERR_FILE_READ);
            }

            /** @var array<string, int>|false $subChunkSizeUnpacked */
            $subChunkSizeUnpacked = unpack('VsubChunkSize', $subChunkSizeData);
            if ($subChunkSizeUnpacked === false || ! isset($subChunkSizeUnpacked['subChunkSize'])) {
                throw new Exception('Failed to unpack sub chunk size', ErrorCodes::ERR_FILE_READ);
            }
            $subChunkSize = (int) $subChunkSizeUnpacked['subChunkSize'];

            if ($subChunkId !== 'data') {
                if ($subChunkSize < 0) {
                    throw new Exception('Invalid sub chunk size encountered', ErrorCodes::ERR_FILE_HEADER);
                }
                if (fseek($fileHandle, $subChunkSize, SEEK_CUR) === -1) {
                    throw new Exception('Failed to seek in file', ErrorCodes::ERR_FILE_READ);
                }
            }
        } while ($subChunkId !== 'data');

        $dataOffset = ftell($fileHandle);
        if ($dataOffset === false) {
            throw new Exception('Failed to tell position in file', ErrorCodes::ERR_FILE_READ);
        }
        $this->dataOffset = $dataOffset;

        $subChunk2Size = $subChunkSize;

        $this->kiloBitPerSecond = $header->getByteRate() * 8 / 1000;
        $this->totalSamples = (int) ($subChunk2Size * 8 / $header->getBitsPerSample() / $header->getChannels());
        $this->totalSeconds = (int) round($subChunk2Size / $header->getByteRate());
    }

    public function getKiloBitPerSecond(): float
    {
        return $this->kiloBitPerSecond;
    }

    public function getTotalSamples(): int
    {
        return $this->totalSamples;
    }

    public function getTotalSeconds(): int
    {
        return $this->totalSeconds;
    }

    public function getDataOffset(): int
    {
        return $this->dataOffset;
    }
}
