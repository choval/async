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

class Async
{
    protected static $loop;



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
     * Gets the loop to use if none passed
     *
     */
    public static function getLoop()
    {
        if (empty(static::$loop)) {
            throw new \Exception('ReactPHP EventLoop not set');
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
        if ($promise instanceof EventEmitterInterface) {
            $promise = Stream\buffer($promise);
        } elseif (is_array($promise)) {
            $promises = [];
            foreach ($promise as $v) {
                $promises[] = static::resolve($v);
            }
            $promise = Promise\all($promises);
        } elseif ($promise instanceof Generator || $promise instanceof Closure) {
            $promise = static::resolve($promise);
        }
        if ($promise instanceof PromiseInterface) {
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
                $err = new \Exception('Process timed out');
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
                $e = new \Exception($msg, $termSignal, $err);
                return $defer->reject($e);
            }
            if (!is_null($termSignal)) {
                return $defer->reject(new \Exception('Process terminated with code: ' . $termSignal, $termSignal));
            }
            if ($exitCode) {
                return $defer->reject(new \Exception('Process exited with code: ' . $exitCode."\n$buffer", $exitCode));
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
        $resolver = function () use ($loop, $frequency, $retries, $func, $type) {
            $last = new \Exception('Failed retries');
            while ($retries--) {
                try {
                    $res = yield static::resolve($func);
                    return $res;
                } catch (\Throwable $e) {
                    yield sleep($loop, $frequency);
                    $last = $e;
                    $msg = $e->getMessage();
                    if ($msg != $type && !($e instanceof $type)) {
                        throw $e;
                    }
                }
            }
            throw $last;
        };
        return static::resolve($resolver);
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

        $sockets = array();
        $domain = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? AF_INET : AF_UNIX);
        if (socket_create_pair($domain, SOCK_STREAM, 0, $sockets) === false) {
            return new RejectedPromise(new \Exception('socket_create_pair failed: ' . socket_strerror(socket_last_error())));
        }

        $pid = pcntl_fork();
        if ($pid == -1) {
            return new RejectedPromise(new \Exception('Async fork failed'));
        } elseif ($pid) {
            // Parent
            $buffer = '';
            $loop->addPeriodicTimer(0.001, function ($timer) use ($pid, $defer, &$sockets, $loop, &$buffer) {
                while (($data = socket_recv($sockets[1], $chunk, 1024, \MSG_DONTWAIT)) > 0) { // !== false) {
                    $buffer .= $chunk;
                }
                $waitpid = pcntl_waitpid($pid, $status, \WNOHANG);
                if ($waitpid > 0) {
                    $loop->cancelTimer($timer);
                    if (!pcntl_wifexited($status)) {
                        $code = pcntl_wexitstatus($status);
                        $defer->reject(new \Exception('child failed with status: ' . $code));
                        @socket_close($sockets[1]);
                        @socket_close($sockets[0]);
                        return;
                    }
                    while (($data = socket_recv($sockets[1], $chunk, 1024, \MSG_DONTWAIT)) > 0) { // !== false) {
                        $buffer .= $chunk;
                    }
                    $data = unserialize($buffer);
                    $defer->resolve($data);
                    @socket_close($sockets[1]);
                    @socket_close($sockets[0]);
                    return;
                } elseif ($waitpid < 0) {
                    if (!pcntl_wifexited($status)) {
                        $code = pcntl_wexitstatus($status);
                        $defer->reject(new \Exception('child failed with status: ' . $code));
                        @socket_close($sockets[1]);
                        @socket_close($sockets[0]);
                        return;
                    }
                    return $defer->reject(new \Exception('child failed with unknown status'));
                }
            });
            return $defer->promise();
        } else {
            // Child
            $res = call_user_func_array($func, $args);
            $res = serialize($res);
            $written = socket_write($sockets[0], $res, strlen($res));
            if ($written === false) {
                exit(1);
            }
            exit;
        }
    }



    /**
     * Resolve Generator, for legacy, but actually alias of resolve
     */
    public static function resolve_generator($gen)
    {
        return static::resolve($gen);
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
     * Resolves multiple things:
     * - Closure
     * - Generator
     * - Promise
     * - Stream
     */
    public static function resolve($gen)
    {
        if ($gen instanceof Generator) {
            try {
                $gen = static::unwrapGenerator($gen);
            } catch(\Exception $e) {
                return new RejectedPromise($e);
            }
        }
        if ($gen instanceof Closure) {
            $gen = static::resolve($gen());
        }
        if ($gen instanceof PromiseInterface) {
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
        if ($gen instanceof React\Stream\ReadableStreamInterface) {
            return Stream\buffer($gen);
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
}
