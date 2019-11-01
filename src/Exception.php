<?php
namespace Choval\Async;

use Throwable;
use Exception as RootException;

class Exception extends RootException
{

    protected $trace;
    protected $prev;


    public function __construct(string $message = "", int $code=0, $trace=null, Throwable $prev=null) {
        if (is_null($trace)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1024);
        } else {
            $called = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            array_unshift($trace, $called[0]);
        }
        $this->code = $code;
        $this->message = $message;
        $this->file = $trace[0]['file'];
        $this->line = $trace[0]['line'];
        $this->trace = $trace;
        $this->prev = $prev;
    }
        

    public function getAsyncTrace(): array
    {
        return $this->trace;
    }


    public function getAsyncTraceAsString(): string
    {
        $out = '';
        foreach($this->trace as $pos=>$row) {
            $out .= "#{$pos} {$row['file']}({$row['line']})";
            if (isset($row['class']) && isset($row['function'])) {
                $out .= ": {$row['class']}\\{$row['function']}()";
            }
            else if (isset($row['function'])) {
                $out .= ": {$row['function']}()";
            }
            $out .= "\n";
        }
        return $out;
    }
}
