<?php

declare(strict_types=1);

namespace bluemoehre;

/**
 * Manages the reading and validation of WAV file headers.
 */
class WaveHeader
{
    private int $chunkSize;
    private int $audioFormat;
    private int $channels;
    private int $sampleRate;
    private int $byteRate;
    private int $blockAlign;
    private int $bitsPerSample;

    /**
     * @param resource $fileHandle
     */
    public function readHeader($fileHandle): void
    {
        $this->readChunkId($fileHandle);
        $this->readChunkSize($fileHandle);
        $this->readFormat($fileHandle);
        $this->readSubChunk1($fileHandle);
    }

    public function getBitsPerSample(): int
    {
        return $this->bitsPerSample;
    }

    public function getByteRate(): int
    {
        return $this->byteRate;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function getAudioFormat(): int
    {
        return $this->audioFormat;
    }

    public function getChannels(): int
    {
        return $this->channels;
    }

    public function getSampleRate(): int
    {
        return $this->sampleRate;
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readChunkId($fileHandle): void
    {
        $chunkId = fread($fileHandle, 4);
        if ($chunkId === false || $chunkId !== 'RIFF') {
            throw new Exception('Unsupported file type', ErrorCodes::ERR_FILE_INCOMPATIBLE);
        }
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readChunkSize($fileHandle): void
    {
        $chunkSize = fread($fileHandle, 4);
        if ($chunkSize === false) {
            throw new Exception('Failed to read chunk size from file', ErrorCodes::ERR_FILE_READ);
        }

        /** @var array<string, int>|false $chunkSizeUnpacked */
        $chunkSizeUnpacked = unpack('VchunkSize', $chunkSize);
        if ($chunkSizeUnpacked === false) {
            throw new Exception('Failed to unpack chunk size', ErrorCodes::ERR_FILE_READ);
        }
        $this->chunkSize = (int) $chunkSizeUnpacked['chunkSize'];
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readFormat($fileHandle): void
    {
        $format = fread($fileHandle, 4);
        if ($format === false || $format !== 'WAVE') {
            throw new Exception('Unsupported file format', ErrorCodes::ERR_FILE_INCOMPATIBLE);
        }
    }

    /**
     * @param resource $fileHandle
     *
     * @throws Exception
     */
    private function readSubChunk1($fileHandle): void
    {
        $subChunk1Id = fread($fileHandle, 4);
        if ($subChunk1Id === false || $subChunk1Id !== 'fmt ') {
            throw new Exception('Unsupported file format', ErrorCodes::ERR_FILE_INCOMPATIBLE);
        }

        $subChunk1 = fread($fileHandle, 20);
        if ($subChunk1 === false) {
            throw new Exception('Failed to read sub chunk 1 from file', ErrorCodes::ERR_FILE_READ);
        }

        /** @var array<string, int>|false $subChunk1Unpacked */
        $subChunk1Unpacked = unpack('VsubChunk1Size/vaudioFormat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', $subChunk1);
        if ($subChunk1Unpacked === false) {
            throw new Exception('Failed to unpack sub chunk 1', ErrorCodes::ERR_FILE_READ);
        }

        if ($subChunk1Unpacked['audioFormat'] !== 1) {
            throw new Exception('Unsupported audio format', ErrorCodes::ERR_FILE_INCOMPATIBLE);
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
            throw new Exception('File header contains invalid data: byte rate does not match', ErrorCodes::ERR_FILE_HEADER);
        }

        if ($this->blockAlign !== $this->channels * $this->bitsPerSample / 8) {
            throw new Exception('File header contains invalid data: block align does not match', ErrorCodes::ERR_FILE_HEADER);
        }
    }
}
