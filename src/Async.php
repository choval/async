<?php

namespace Choval\Async;

use Closure;
use Clue\React\Block;
use Evenement\EventEmitterInterface;

use Generator;
use React\ChildProcess\Process;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\Deferred;

use React\Promise\FulfilledPromise;
use React\Promise\PromiseInterface;
use React\Promise\RejectedPromise;
use React\Promise\Stream;

use React\Stream\ReadableStreamInterface;

final class Async
{
    private static $loop;
    private static $forks = 0;
    private static $forks_limit = 50;



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
        return static::$forks_limit;
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
            foreach ($promise as $v) {
                $promises[] = static::resolve($v);
            }
            $promise = Promise\all($promises);
        }
        else {
            $promise = static::resolve($promise);
        }
        if (is_a($promise, PromiseInterface::class)) {
            return Block\await($promise, $loop, $timeout);
        }
        return $promise;
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
        $loop->addTimer($time, function () use ($defer, $time) {
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
        $buffer = '';
        $proc = new Process($cmd);
        $timer = false;
        $err;
        if ($timeout > 0) {
            $timer = $loop->addTimer($timeout, function () use ($proc, &$err) {
                $proc->stdin->end();
                foreach ($proc->pipes as $pipe) {
                    $pipe->close();
                }
                $proc->terminate(\SIGKILL ?? 9);
                $err = new \RuntimeException('Process timed out');
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
        $proc->on('exit', function ($exitCode, $termSignal) use ($defer, &$buffer, $cmd, $timer, $loop, &$err, $proc) {
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
                $e = new \RuntimeException($msg, $termSignal, $err);
                return $defer->reject($e);
            }
            if (!is_null($termSignal)) {
                return $defer->reject(new \RuntimeException('Process terminated with code: ' . $termSignal, $termSignal));
            }
            if ($exitCode) {
                return $defer->reject(new \RuntimeException('Process exited with code: ' . $exitCode."\n$buffer", $exitCode));
            }
            $defer->resolve($buffer);
        });
        return $defer->promise();
    }



    /**
     * Retry
     */
    public static function retry($func, int $retries = 10, float $frequency = 0.1, string $type = null)
    {
        return static::retryWithLoop(static::getLoop(), $func, $retries, $frequency, $type);
    }
    public static function retryWithLoop(LoopInterface $loop, $func, int $retries = 10, float $frequency = 0.1, string $type = null)
    {
        if (is_null($type)) {
            $type = \Exception::class;
        }
        $defer = new Deferred();
        $promises = [];
        $i = 0;
        $timer = $loop->addPeriodicTimer($frequency, function($timer) use ($loop, &$retries, $func, $type, $defer, &$promises, &$i) {
            $def = new Deferred();
            $promises[$i] = $def->promise();
            if ($i == 0) {
                $promise = new FulfilledPromise(0);
            } else {
                $promise = $promises[ $i-1 ];
            }
            if ($i >= $retries) {
                $defer->reject( new \RuntimeException('Failed retries') );
                $loop->cancelTimer($timer);
                return;
            }
            $i++;
            $promise
                ->then(function($res) use ($func) {
                    return static::resolve($func);
                })
                ->then(function($res) use ($defer, $timer, $loop, $def) {
                    $def->resolve(true);
                    $defer->resolve($res);
                    $loop->cancelTimer($timer);
                })
                ->otherwise(function($e) use ($defer, $timer, $loop, $type, $def) {
                    $def->resolve(true);
                    $msg = $e->getMessage();
                    if ($msg != $type && !(is_a($e,$type))) {
                        $defer->reject($e);
                        $loop->cancelTimer($timer);
                    }
                });
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
        static::resolve(function() use($loop) {
            while (static::$forks >= static::$forks_limit) {
                yield static::sleepWithLoop($loop, 0.1);
            }
            return true;
        })
        ->then(function() use ($loop, $func, $args, $defer) {
            static::$forks++;
            $sockets = array();
            $domain = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? AF_INET : AF_UNIX);
            if (socket_create_pair($domain, SOCK_STREAM, 0, $sockets) === false) {
                return new RejectedPromise(new \RuntimeException('socket_create_pair failed: ' . socket_strerror(socket_last_error())));
            }

            $pid = pcntl_fork();
            if ($pid == -1) {
                static::$forks--;
                return new RejectedPromise(new \RuntimeException('Async fork failed'));
            } elseif ($pid) {
                // Parent
                $buffer = '';
                $loop->addPeriodicTimer(0.001, function ($timer) use ($pid, $defer, &$sockets, $loop, &$buffer) {
                    while (($data = socket_recv($sockets[1], $chunk, 1024, \MSG_DONTWAIT)) > 0) { // !== false) {
                        $buffer .= $chunk;
                    }
                    $waitpid = pcntl_waitpid($pid, $status, \WNOHANG);
                    if ($waitpid > 0) {
                        static::$forks--;
                        $loop->cancelTimer($timer);
                        if (!pcntl_wifexited($status)) {
                            $code = pcntl_wexitstatus($status);
                            $defer->reject(new \RuntimeException('child exited with status: ' . $code));
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
                        static::$forks--;
                        if (!pcntl_wifexited($status)) {
                            $code = pcntl_wexitstatus($status);
                            $defer->reject(new \RuntimeException('child errored with status: ' . $code));
                            @socket_close($sockets[1]);
                            @socket_close($sockets[0]);
                            return;
                        }
                        return $defer->reject(new \RuntimeException('child failed with unknown status'));
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
                } catch(\Exception $e) {
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
    public static function unwrapGenerator(Generator $generator)
    {
        $value = $generator->current();
        $defer = new Deferred();
        static::resolve($value)
            ->then(function ($res) use ($generator, $defer) {
                $generator->send($res);
                if ($generator->valid()) {
                    $final = static::resolve($generator);
                    return $defer->resolve($final);
                }
                $return = $generator->getReturn();
                $defer->resolve($return);
            })
            ->otherwise(function ($e) use ($generator, $defer) {
                $defer->reject($e);
                $generator->throw($e);
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
                $reject(new \RuntimeException('An error occured on the underlying stream while buffering', 0, $error));
            });

            $stream->on('close', function () use ($resolve, &$buffer, &$type) {
                if ($type == 'string') {
                    return $resolve( implode('', $buffer) );
                }
                $resolve($buffer);
            });
        }, function ($_, $reject) {
            $reject(new \RuntimeException('Cancelled buffering'));
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
    public static function resolve($gen)
    {
        if (is_a($gen, Generator::class)) {
            try {
                $gen = static::unwrapGenerator($gen);
            } catch(\Exception $e) {
                return new RejectedPromise($e);
            }
        }
        if (is_a($gen, Closure::class)) {
            try {
                $gen = static::resolve($gen());
            } catch(\Exception $e) {
                return new RejectedPromise($e);
            }
        }
        if (is_a($gen, PromiseInterface::class)) {
            $defer = new Deferred();
            $gen
                ->then(function($res) use ($defer) {
                    $defer->resolve( static::resolve( $res ) );
                })
                ->otherwise(function($e) use ($defer) {
                    $defer->reject($e);
                });
            return $defer->promise();
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
