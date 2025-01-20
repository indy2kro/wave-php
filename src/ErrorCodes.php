<?php

declare(strict_types=1);

namespace bluemoehre;

/**
 * Contains error codes used across the application.
 */
class ErrorCodes
{
    public const ERR_PARAM_VALUE = 1;
    public const ERR_FILE_ACCESS = 2;
    public const ERR_FILE_READ = 3;
    public const ERR_FILE_WRITE = 4;
    public const ERR_FILE_CLOSE = 5;
    public const ERR_FILE_INCOMPATIBLE = 6;
    public const ERR_FILE_HEADER = 7;
}
