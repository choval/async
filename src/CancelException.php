<?php

namespace Choval\Async;

use Throwable;
use Choval\Async\Exception;

class CancelException extends Exception
{
    public function __construct(Throwable $prev = null)
    {
        $message = 'Promise cancelled';
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1024);
        $code = 0;
        $this->code = $code;
        $this->message = $message;
        $this->file = $trace[0]['file'];
        $this->line = $trace[0]['line'];
        $this->trace = $trace;
        $this->prev = $prev;
    }
}
