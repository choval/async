<?php

namespace Choval\Async;

use Choval\Async\Exception;
use Closure;
use Clue\React\Block;
use Evenement\EventEmitterInterface;

use Generator;
use React\ChildProcess\Process;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\Deferred;

use React\Promise\Timer\TimeoutException;
use React\Promise\FulfilledPromise;
use React\Promise\PromiseInterface;
use React\Promise\RejectedPromise;
use React\Promise\Stream;

use React\Stream\ReadableStreamInterface;

final class Async
{
    private static $loop;
    private static $forks = [];
    private static $forks_limit;



    /**
     *
     * Sets the loop to use if none passed
     *
     */
    public static function setLoop(LoopInterface $loop)
    {
        static::$loop = $loop;
    }



    /**
     *
     * Sets the limit of simultaneous async forks
     *
     */
    public static function setForksLimit(int $limit)
    {
        static::$forks_limit = $limit;
    }



    /**
     *
     * Gets the limit of async forks
     *
     */
    public static function getForksLimit()
    {
        if (empty(static::$forks_limit)) {
            static::$forks_limit = 20;
            // TODO: Calculate the max number of forks
        }
        return static::$forks_limit;
    }



    /**
     *
     * Add a fork
     *
     */
    public static function addFork(string $id, PromiseInterface $promise)
    {
        static::$forks[$id] = $promise;
        $promise->always(function() use ($id) {
            unset( static::$forks[$id] );
        });
    }



    /**
     *
     * Remove a fork
     *
     */
    public static function removeFork($id)
    {
        $promise = static::$forks[$id] ?? false;
        if ($promise) {
            $promise->cancel();
            unset(static::$forks[$id]);
        }
    }



    /**
     *
     * Wait free fork
     *
     */
    public static function waitFreeFork(LoopInterface $loop)
    {
        $limit = static::getForksLimit();
        return static::resolve(function() use ($limit, $loop) {
            $count = count( static::$forks );
            while( count(static::$forks) >= $limit ) {
                yield static::sleepWithLoop($loop, 0.001);
            }
            return true;
        });
    }



    /**
     *
     * Gets the loop to use if none passed
     *
     */
    public static function getLoop()
    {
        if (empty(static::$loop)) {
            throw new \RuntimeException('ReactPHP EventLoop not set');
        }
        return static::$loop;
    }



    /**
     * Wait
     */
    public static function wait($promise, float $timeout = null)
    {
        return static::waitWithLoop(static::getLoop(), $promise, $timeout);
    }
    public static function waitWithLoop(LoopInterface $loop, $promise, float $timeout = null)
    {
        return static::syncWithLoop($loop, $promise, $timeout);
    }



    /**
     * Sync
     * We run Block\await with a catch multiple times, to avoid having
     * it block timers and promises added to the loop after calling sync.
     */
    public static function sync($promise, float $timeout = null)
    {
        return static::syncWithLoop(static::getLoop(), $promise, $timeout);
    }
    public static function syncWithLoop(LoopInterface $loop, $promise, float $timeout = null)
    {
        if (!is_null($timeout)) {
            $timeout = (float)$timeout;
        }
        if ($timeout <= 0) {
            $timeout = null;
        }
        if (is_array($promise)) {
            $promises = [];
            foreach ($promise as $k=>$v) {
                $promises[$k] = static::resolve($v);
            }
            $promise = Promise\all($promises);
        }
        else if (!is_a($promise, PromiseInterface::class)) {
            $promise = static::resolve($promise);
        }
        $freq = 1;
        if (is_null($timeout)) {
            while(true) {
                try {
                    return Block\await($promise, $loop, $freq);
                } catch(TimeoutException $e) {
                }
            }
        }

        $freq = $timeout / 100;
        if ($freq < 0.01) {
            $freq = 0.01;
        }
        $limit = $timeout / $freq;

        $exit = false;
        $loop->addTimer($timeout, function() use (&$exit) {
            $exit = true;
        });
        while(!$exit) {
            try {
                return Block\await($promise, $loop, $freq);
            } catch(TimeoutException $e) {
            }
        }
        $ef = new TimeoutException($timeout, 'Wait timed out in '.$timeout. ' secs', 408, $e->getPrevious());
        throw $ef;
    }



    /**
     * Sleep
     */
    public static function sleep(float $time)
    {
        return static::sleepWithLoop(static::getLoop(), $time);
    }
    public static function sleepWithLoop(LoopInterface $loop, float $time)
    {
        $defer = new Deferred();
        $loop->addTimer($time, function ($timer) use ($defer, $time) {
            $defer->resolve($time);
        });
        return $defer->promise();
    }



    /**
     * Execute
     */
    public static function execute(string $cmd, float $timeout = 0)
    {
        return static::executeWithLoop(static::getLoop(), $cmd, $timeout);
    }
    public static function executeWithLoop(LoopInterface $loop, string $cmd, float $timeout = 0)
    {
        if (is_null($timeout)) {
            $timeout = ini_get('max_execution_time');
        }
        $defer = new Deferred();
        $id = random_bytes(16);
        $trace = debug_backtrace();
        static::waitFreeFork($loop)
            ->then(function() use ($loop, $cmd, $timeout, $defer, $id, $trace) {
                static::addFork($id, $defer->promise());
                $buffer = '';
                $proc = new Process($cmd);
                $timer = false;
                $err;
                if ($timeout > 0) {
                    $timer = $loop->addTimer($timeout, function () use ($proc, &$err, $timeout) {
                        $proc->stdin->end();
                        foreach ($proc->pipes as $pipe) {
                            $pipe->close();
                        }
                        $proc->terminate(\SIGKILL ?? 9);
                        $err = new \RuntimeException('Process timed out in '.$timeout .' secs');
                    });
                }
                $proc->start($loop);
                $echo = false;
                if(!empty(getenv('ASYNC_EXECUTE_ECHO'))) {
                    $echo = true;
                }
                $proc->stdout->on('data', function ($chunk) use (&$buffer, $echo) {
                    $buffer .= $chunk;
                    if ($echo) {
                        $first = "  [ASYNC EXECUTE]  ";
                        $chunk = trim($chunk);
                        $lines = explode("\n", $chunk);
                        foreach($lines as $line) {
                            echo $first.$line."\n";
                        }
                    }
                });
                $proc->stdout->on('error', function (\Exception $e) use (&$err) {
                    $err = $e;
                });
                $proc->on('exit', function ($exitCode, $termSignal) use ($defer, &$buffer, $cmd, $timer, $loop, &$err, $proc, $id, $trace) {
                    static::removeFork($id);
                    $proc->stdout->close();
                    if ($timer) {
                        $loop->cancelTimer($timer);
                    }
                    // Clears any hanging processes
                    /*
                    $loop->addTimer(1, function () {
                        pcntl_waitpid(-1, $status, \WNOHANG);
                    });
                     */
                    if ($err) {
                        $msg = $err->getMessage();
                        $e = new Exception($msg, $termSignal, $trace, $err);
                        return $defer->reject($e);
                    }
                    if (!is_null($termSignal)) {
                        return $defer->reject(new Exception('Process terminated with code: ' . $termSignal, $termSignal, $trace));
                    }
                    if ($exitCode) {
                        return $defer->reject(new Exception('Process exited with code: ' . $exitCode."\n$buffer", $exitCode, $trace));
                    }
                    $defer->resolve($buffer);
                });
            })
            ->otherwise(function($e) use ($defer) {
                $defer->reject($e);
            });
        return $defer->promise();
    }



    /**
     * Timeout
     */
    public static function timeout($func, float $timeout)
    {
        return static::timeoutWithLoop(static::getLoop(), $func, $timeout);
    }
    public static function timeoutWithLoop(LoopInterface $loop, $func, float $timeout)
    {
        $defer = new Deferred();
        if (is_a($func, PromiseInterface::class)) {
            $promise = $func;
        } else {
            $promise = static::resolve($func);
        }
        $timer = $loop->addTimer($timeout, function() use ($defer, $timeout) {
            $defer->reject( new Exception('Timed out after '. $timeout .' secs') );
//            $defer->reject( new TimeoutException($timeout, 'Timed out after ' . $timeout . ' secs') );
        });
        $promise
            ->then(function($res) use ($defer) {
                $defer->resolve($res);
            })
            ->otherwise(function($e) use ($defer) {
                $defer->reject($e);
            })
            ->always(function() use ($timer, $loop) {
                $loop->cancelTimer($timer);
            });
        return $defer->promise();
    }



    /**
     * Retry
     */
    public static function retry($func, int $retries = 10, float $frequency = 0.1, $type = null)
    {
        return static::retryWithLoop(static::getLoop(), $func, $retries, $frequency, $type);
    }
    public static function retryWithLoop(LoopInterface $loop, $func, int $retries = 10, float $frequency = 0.1, $type = null)
    {
        if (is_null($type)) {
            $type = \Throwable::class;
        }
        if (!is_array($type)) {
            $type = [$type];
        }
        $defer = new Deferred();
        $i = $retries;
        $trace = debug_backtrace();
        $last_e = new Exception('Failed retries', 0, $trace);
        $running = true;
        $timer = $loop->addPeriodicTimer($frequency, function($timer) use ($defer, &$i, $loop, &$last_e, &$running, $func, $type) {
            if ($i >= 0) {
                $i--;
            } else {
                $loop->cancelTimer($timer);
                return $defer->reject($last_e);
            }
            if (!$running) {
                $running = true;
                static::resolve($func)
                    ->then(function($res) use ($defer, $timer, $loop) {
                        $loop->cancelTimer($timer);
                        $defer->resolve($res);
                    })
                    ->otherwise(function($e) use ($defer, $loop, $timer, $type, &$last_e) {
                        $last_e = $e;
                        $msg = $e->getMessage();
                        $ignore = false;
                        foreach($type as $tmp) {
                            if ($tmp == $msg || is_a($e, $tmp)) {
                                $ignore = true;
                                break;
                            }
                        }
                        if (!$ignore) {
                            $loop->cancelTimer($timer);
                            $defer->reject($e);
                        }
                    })
                    ->always(function() use (&$running) {
                        $running = false;
                    });
            }
        });
        static::resolve($func)
            ->then(function($res) use ($defer, $timer, $loop) {
                $loop->cancelTimer($timer);
                $defer->resolve($res);
            })
            ->otherwise(function($e) use ($defer, $loop, $timer, $type, &$last_e) {
                $last_e = $e;
                $msg = $e->getMessage();
                $ignore = false;
                foreach($type as $tmp) {
                    if ($tmp == $msg || is_a($e, $tmp)) {
                        $ignore = true;
                        break;
                    }
                }
                if (!$ignore) {
                    $loop->cancelTimer($timer);
                    $defer->reject($e);
                }
            })  
            ->always(function() use (&$running) {
                $running = false;
            });
        return $defer->promise();
    }



    /**
     * Async
     */
    public static function async($func, $args = [])
    {
        return static::asyncWithLoop(static::getLoop(), $func, $args);
    }
    public static function asyncWithLoop(LoopInterface $loop, $func, array $args = [])
    {
        $defer = new Deferred();
        $id = random_bytes(16);
        $trace = debug_backtrace();
        static::waitFreeFork($loop)
            ->then(function() use ($loop, $func, $args, $defer, $id, $trace) {
                static::addFork($id, $defer->promise());
                $sockets = array();
                $domain = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? AF_INET : AF_UNIX);
                if (socket_create_pair($domain, SOCK_STREAM, 0, $sockets) === false) {
                    return new RejectedPromise(new Exception('socket_create_pair failed: ' . socket_strerror(socket_last_error()), 0, $trace));
                }

                $pid = pcntl_fork();
                if ($pid == -1) {
                    static::removeFork($id);
                    return new RejectedPromise(new Exception('Async fork failed', 0, $trace));
                } elseif ($pid) {
                    // Parent
                    $buffer = '';
                    $loop->addPeriodicTimer(0.001, function ($timer) use ($pid, $defer, &$sockets, $loop, &$buffer, $id, $trace) {
                        while (($data = socket_recv($sockets[1], $chunk, 1024, \MSG_DONTWAIT)) > 0) { // !== false) {
                            $buffer .= $chunk;
                        }
                        $waitpid = pcntl_waitpid($pid, $status, \WNOHANG);
                        if ($waitpid > 0) {
                            static::removeFork($id);
                            $loop->cancelTimer($timer);
                            if (!pcntl_wifexited($status)) {
                                $code = pcntl_wexitstatus($status);
                                $defer->reject(new Exception('child exited with status: ' . $code, $code, $trace));
                                @socket_close($sockets[1]);
                                @socket_close($sockets[0]);
                                return;
                            }
                            while (($data = socket_recv($sockets[1], $chunk, 1024, \MSG_DONTWAIT)) > 0) { // !== false) {
                                $buffer .= $chunk;
                            }
                            $data = unserialize($buffer);
                            if (is_a($data, \Exception::class)) {
                                $defer->reject($data);
                            } else {
                                $defer->resolve($data);
                            }
                            @socket_close($sockets[1]);
                            @socket_close($sockets[0]);
                            return;
                        } elseif ($waitpid < 0) {
                            static::removeFork($id);
                            if (!pcntl_wifexited($status)) {
                                $code = pcntl_wexitstatus($status);
                                $defer->reject(new Exception('child errored with status: ' . $code, $code, $trace));
                                @socket_close($sockets[1]);
                                @socket_close($sockets[0]);
                                return;
                            }
                            return $defer->reject(new Exception('child failed with unknown status', 0, $trace));
                        }
                    });
                } else {
                    // Child
                    try {
                        $res = call_user_func_array($func, $args);
                        $res = serialize($res);
                        $written = socket_write($sockets[0], $res, strlen($res));
                        if ($written === false) {
                            exit(1);
                        }
                    } catch(\Throwable $e) {
                        static::flattenExceptionBacktrace($e);
                        $res = serialize($e);
                        $written = socket_write($sockets[0], $res, strlen($res));
                        if ($written === false) {
                            exit(1);
                        }
                    }
                    exit(0);
                }
            })
            ->otherwise(function($e) use ($defer) {
                $defer->reject($e);
            });
        return $defer->promise();
    }



    /**
     * Unwraps a generator and solves yielded promises
     */
    public static function unwrapGenerator(Generator $generator, int $depth=0)
    {
        $value = $generator->current();
        $defer = new Deferred();
        static::resolve($value, $depth++)
            ->then(function ($res) use ($generator, $defer, $depth) {
                try {
                    $generator->send($res);
                } catch(\Throwable $e) {
                    $defer->reject($e);
                    if ($generator->valid()) {
                        $generator->throw($e);
                    }
                    return;
                }
                if (!$generator->valid()) {
                    try {
                        $return = $generator->getReturn();
                    } catch(\Throwable $e) {
                        return $defer->resolve(null);
                    }
                    return $defer->resolve(static::resolve($return, $depth++));
                }
                return $defer->resolve( static::resolve( $generator, $depth++) );
            })
            ->otherwise(function ($e) use ($generator, $defer, $depth) {
                if ($generator->valid()) {
                    try {
                        $generator->throw($e);
                    } catch(\Throwable $e) {
                        return $defer->reject($e);
                    }
                    return $defer->resolve( static::resolve( $generator, $depth++) );
                }
                return $defer->reject($e);
                // return $defer->resolve( static::resolve( $generator, $depth++) );
            });
        return $defer->promise();
    }



    /**
     *
     * Unwraps a stream
     * This is based on promise-stream, but allows handling non-strings
     * https://github.com/reactphp/promise-stream/blob/master/src/functions.php
     *
     */
    public static function buffer(ReadableStreamInterface $stream, $maxLength = null)
    {
        // Return null if stream is closed
        if (!$stream->isReadable()) {
            return Promise\resolve();
        }
        $buffer = [];
        $bufferer;
        $size = 0;
        $type = 'array';
        $trace = debug_backtrace();
        $promise = new Promise\Promise(function ($resolve, $reject) use ($stream, $maxLength, &$buffer, &$bufferer, &$size, &$type) {
            $bufferer = function ($data) use (&$buffer, $reject, $maxLength, &$size, &$type) {
                $buffer[] = $data;
                if (is_string($data)) {
                    $type = 'string';
                    $size += strlen($data);
                } else {
                    $size++;
                }
                if ($maxLength !== null && $size > $maxLength) {
                    $reject(new \OverflowException('Buffer exceeded maximum length'));
                }
            };
            $stream->on('data', $bufferer);
            $stream->on('error', function ($error) use ($reject) {
                $reject($error);
            });

            $stream->on('close', function () use ($resolve, &$buffer, &$type) {
                if ($type == 'string') {
                    return $resolve( implode('', $buffer) );
                }
                $resolve($buffer);
            });
        }, function ($_, $reject) use ($trace) {
            $reject(new Exception('Cancelled buffering', 0, $trace));
        });

        return $promise->then(null, function ($error) use (&$buffer, &$bufferer, $stream, $type) {
            // promise rejected => clear buffer and buffering
            $buffer = [];
            $stream->removeListener('data', $bufferer);
            throw $error;
        });
    }




    /**
     * Resolves multiple things:
     * - Closure
     * - Generator
     * - Promise
     * - Stream
     */
    public static function resolve($gen, int $depth=0)
    {
        if (is_a($gen, Closure::class)) {
            try {
                $gen = static::resolve($gen(), $depth++);
            } catch(\Exception $e) {
                return new RejectedPromise($e);
            }
        }
        if (is_a($gen, Generator::class)) {
            try {
                $gen = static::unwrapGenerator($gen, $depth++);
            } catch(\Exception $e) {
                return new RejectedPromise($e);
            }
        }
        if (is_a($gen, FulfilledPromise::class)) {
            return $gen;
        }
        if (is_a($gen, RejectedPromise::class)) {
            return $gen;
        }
        if (is_a($gen, PromiseInterface::class)) {
            return $gen;
        }
        if (is_a($gen, ReadableStreamInterface::class)) {
            return static::buffer($gen);
        }
        return new FulfilledPromise($gen);
    }



    /**
     * Chain Resolve
     */
    public static function chainResolve()
    {
        $functions = func_get_args();
        if (count($functions) == 1 && is_array(current($functions))) {
            $functions = current($functions);
        }
        $func = function () use ($functions) {
            $rows = [];
            foreach ($functions as $pos => $function) {
                $rows[$pos] = yield $function;
            }
            return $rows;
        };
        return static::resolve($func());
    }



    /**
     * From: https://gist.github.com/nh-mike/fde9f69a57bc45c5b491d90fb2ee08df
     */
    static function flattenExceptionBacktrace(\Throwable $exception) {
        if (is_a($exception, \Exception::class)) {
            $traceProperty = (new \ReflectionClass('Exception'))->getProperty('trace');
        } else {
            $traceProperty = (new \ReflectionClass('Error'))->getProperty('trace');
        }
        $traceProperty->setAccessible(true);
        $flatten = function(&$value, $key) {
            if (is_a($value, Closure::class)) {
                $closureReflection = new \ReflectionFunction($value);
                $value = sprintf(
                    '(Closure at %s:%s)',
                    $closureReflection->getFileName(),
                    $closureReflection->getStartLine()
                );
            } elseif (is_object($value)) {
                $value = sprintf('object(%s)', get_class($value));
            } elseif (is_resource($value)) {
                $value = sprintf('resource(%s)', get_resource_type($value));
            }
        };
        $previousexception = $exception;
        do {
            if ($previousexception === NULL) {
                break;
            }
            $exception = $previousexception;
            $trace = $traceProperty->getValue($exception);
            foreach($trace as &$call) {
                array_walk_recursive($call['args'], $flatten);
            }
            $traceProperty->setValue($exception, $trace);
        } while($previousexception = $exception->getPrevious());
        $traceProperty->setAccessible(false);
    }
}
