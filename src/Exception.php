<?php

namespace Choval\Async;

use Exception as RootException;
use Throwable;

class Exception extends RootException
{
    protected $trace;
    protected $prev;


    public function __construct(string $message = "", int $code = 0, $trace = null)
    {
        if (is_null($trace)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
        } else {
            $called = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            array_unshift($trace, $called[0]);
        }
        $this->code = $code;
        $this->message = $message;
        $this->file = $trace[0]['file'];
        $this->line = $trace[0]['line'];
        $this->trace = $trace;
    }


    public function __serialize(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'file' => $this->file,
            'line' => $this->line,
            'trace' => $this->trace,
        ];
    }


    public function __unserialize(array $data): void
    {
        $this->code = $data['code'];
        $this->message = $data['message'];
        $this->file = $data['file'];
        $this->line = $data['line'];
        $this->trace = $data['trace'];
    }



    public function getAsyncTrace(): array
    {
        return $this->trace;
    }


    public function getAsyncTraceAsString(): string
    {
        $out = '';
        foreach ($this->trace as $pos => $row) {
            if (empty($row['line'])) {
                $row['line'] = 'Unknown line';
            }
            if (empty($row['file'])) {
                $row['file'] = 'Unknown file';
            }
            $out .= "#{$pos} {$row['file']}({$row['line']})";
            if (isset($row['class']) && isset($row['function'])) {
                $out .= ": {$row['class']}\\{$row['function']}()";
            } elseif (isset($row['function'])) {
                $out .= ": {$row['function']}()";
            }
            $out .= "\n";
        }
        return $out;
    }
}
