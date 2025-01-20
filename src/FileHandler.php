<?php

declare(strict_types=1);

namespace bluemoehre;

/**
 * Handles file operations for WAV files.
 */
class FileHandler
{
    private ?string $filePath = null;

    public function __construct(string $filePath = '')
    {
        if ($filePath !== '') {
            $this->setFile($filePath);
        }
    }

    /** @throws Exception */
    public function setFile(string $filePath): void
    {
        $this->validateFile($filePath);
        $this->filePath = $filePath;
    }

    /**
     * @return resource
     *
     * @throws Exception
     */
    public function openFile(string $mode = 'r'): mixed
    {
        if ($this->filePath === null) {
            throw new Exception('No file was loaded', ErrorCodes::ERR_FILE_ACCESS);
        }

        $fileHandle = fopen($this->filePath, $mode);
        if ($fileHandle === false) {
            throw new Exception('Failed to open file', ErrorCodes::ERR_FILE_ACCESS);
        }

        return $fileHandle;
    }

    /**
     * @param resource $fileHandle
     */
    public function closeFile(mixed $fileHandle): void
    {
        if (! fclose($fileHandle)) {
            throw new Exception('Failed to close file', ErrorCodes::ERR_FILE_CLOSE);
        }
    }

    /** @throws Exception */
    private function validateFile(string $filePath): void
    {
        if ($filePath === '') {
            throw new Exception('No file specified', ErrorCodes::ERR_PARAM_VALUE);
        }
        if (! file_exists($filePath)) {
            throw new Exception('File does not exist', ErrorCodes::ERR_PARAM_VALUE);
        }
    }
}
