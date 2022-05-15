<?php

namespace Choval\Async;

use Choval\Async\CancelException;
use Choval\Async\Exception;
use Closure;
use Generator;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
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
    private static $forks = [];
    private static $forks_limit;
    private static $dones_promises = [];
    private static $dones = [];
    private static $dones_res = [];
    private static $dones_key = 0;
    private static $mem_limit;



    /**
     *
     * Sets the loop to use if none passed
     *
     */
    public static function setLoop(LoopInterface $loop)
    {
        static::$loop = $loop;
        static::loadMemLimit();
    }



    /**
     *
     * Loads the memory limit from ini
     *
     */
    public static function loadMemLimit(?string $limit = null)
    {
        if (is_null($limit)) {
            $limit = ini_get('memory_limit');
            if ($limit < 0) {
                return;
            }
        }
        $limit_n = (int)$limit;
        $limit_u = str_replace($limit_n, '', $limit);
        $limit = $limit_n;
        switch ($limit_u) {
            case 'T':
            case 'TB':
                $limit = $limit * 1024;
                // no break
            case 'G':
            case 'GB':
                $limit = $limit * 1024;
                // no break
            case 'M':
            case 'MB':
                $limit = $limit * 1024;
                // no break
            case 'K':
            case 'KB':
                $limit = $limit * 1024;
                break;
        }
        if ($limit) {
            static::$mem_limit = $limit;
        }
        return static::$mem_limit;
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
            static::$forks_limit = 50;
        }
        return static::$forks_limit;
    }



    /**
     *
     * Add a fork
     *
     */
    public static function addFork(string $id, Promise\Promise $promise)
    {
        static::$forks[$id] = $promise;
        $promise->always(function () use ($id) {
            unset(static::$forks[$id]);
        });
    }



    /**
     *
     * Remove a fork
     *
     */
    private static function removeFork($id)
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
        return static::resolve(function () {
            $limit = static::getForksLimit();
            $count = count(static::$forks);
            if ($count < $limit) {
                return true;
            }
            yield Promise\any(static::$forks);
            return true;
        }, $loop);
    }



    /**
     * Gets the loop to use if none passed
     *
     * @return LoopInterface
     */
    public static function getLoop()
    {
        if (empty(static::$loop)) {
            $loop = Loop::get();
            static::setLoop($loop);
        }
        return static::$loop;
    }



    /**
     * Returns if a loop was set
     *
     * @return bool
     */
    public static function hasLoop()
    {
        return static::$loop ? true : false;
    }



    /**
     * Wait
     */
    public static function wait($promise, ?float $timeout = null, float $interval = 0.001)
    {
        return static::waitWithLoop(static::getLoop(), $promise, $timeout, $interval);
    }
    public static function waitWithLoop(LoopInterface $loop, $promise, ?float $timeout = null, float $interval = 0.001)
    {
        return static::syncWithLoop($loop, $promise, $timeout, $interval);
    }



    /**
     * Sync
     * We run Block\await with a catch multiple times, to avoid having
     * it block timers and promises added to the loop after calling sync.
     */
    public static function sync($promise, float $timeout = null, float $interval = 0.001)
    {
        return static::syncWithLoop(static::getLoop(), $promise, $timeout, $interval);
    }
    public static function syncWithLoop(LoopInterface $loop, $promise, float $timeout = null, float $interval = 0.001)
    {
        if ($interval < 0) {
            throw new Exception('Interval must be 0 or positive float');
        }
        $res = null;
        $err = null;
        if (!is_a($promise, PromiseInterface::class)) {
            $promise = static::resolve($promise, $loop);
        }
        if (!is_null($timeout) && $timeout > 0) {
            $promise = static::timeoutWithLoop($loop, $promise, $timeout);
        }
        $done = false;
        $final = $promise->then(
            function ($r) use (&$res, &$done) {
                $res = $r;
                $done = true;
            },
            function ($e) use (&$err, &$done) {
                $err = $e;
                $done = true;
            }
        );
        $periodic = $loop->addPeriodicTimer($interval, function () use ($loop) {
            $loop->stop();
        });
        while (!$done) {
            try {
                $loop->run();
            } catch (\RuntimeException $e) {
                if ($e->getMessage() != 'Can\'t shift from an empty datastructure') {
                    $loop->cancelTimer($periodic);
                    throw $e;
                }
            }
        }
        $loop->cancelTimer($periodic);
        if ($err) {
            if (!is_a($err, \Throwable::class)) {
                throw new \RuntimeException($err->getMessage());
            }
            throw $err;
        }
        return $res;
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
        $timer = null;
        $defer = new Deferred(function ($resolve, $reject) use (&$timer, $loop) {
            if ($timer) {
                $loop->cancelTimer($timer);
            }
            $resolve();
        });
        $timer = $loop->addTimer($time, function ($timer) use ($defer, $time) {
            $defer->resolve($time);
            unset($defer, $time, $timer);
        });
        return $defer->promise();
    }



    /**
     * Kills a process
     */
    protected static function waitProcessExits(int $pid, ?LoopInterface $loop = null)
    {
        if (!extension_loaded('pcntl')) {
            throw new Exception('pcntl extension required', 500);
        }
        if ($pid <= 0) {
            throw new Exception('PID must be greater than zero', 500);
        }
        if (!$loop) {
            $loop = static::getLoop();
        }
        $defer = new Deferred();
        $timer = $loop->addPeriodicTimer(0.001, function () use ($pid, $defer) {
            $r = pcntl_waitpid($pid, $status, \WNOHANG);
            if (!$r) {
                $defer->resolve($r);
            }
        });
        $defer->promise()->always(function () use ($timer, $loop) {
            $loop->cancelTimer($timer);
        });
        return $defer->promise();
    }
    protected static function killProcess(int $pid, int $signal=15, ?LoopInterface $loop = null)
    {
        if (!extension_loaded('posix')) {
            throw new Exception('posix extension required', 500);
        }
        if (!$loop) {
            $loop = static::getLoop();
        }
        $promises = [];
        exec("pstree -p $pid", $output);
        $all = implode(' ', $output);
        preg_match_all('/[0-9]+/', $all, $matches);
        $pids = $matches[0] ?? [];
        array_reverse($pids);
        $pids = array_unique($pids);
        foreach ($pids as $pid) {
            $pid = intval($pid);
            if ($pid) {
                posix_kill($pid, $signal);
                if ($loop) {
                    $promises[] = static::waitProcessExits($pid, $loop);
                }
            }
        }
        if ($loop) {
            return Promise\all($promises);
        }
        return;
    }



    /**
     * Execute
     */
    public static function execute(string $cmd, float $timeout = 0, ?callable $outputfn = null)
    {
        return static::executeWithLoop(static::getLoop(), $cmd, $timeout, $outputfn);
    }
    public static function executeWithLoop(LoopInterface $loop, string $cmd, float $timeout = 0, ?callable $outputfn = null)
    {
        if (!extension_loaded('sockets')) {
            throw new Exception('sockets extension required', 500);
        }
        if (is_null($timeout)) {
            $timeout = ini_get('max_execution_time');
        }
        $proc = new Process($cmd);
        $err = false;
        $timer = false;
        // Defer with cancel callback
        $defer = new Deferred(function () use ($proc, $loop, $cmd) {
            $proc->stdin->end();
            foreach ($proc->pipes as $pipe) {
                $pipe->close();
            }
            $pid = $proc->getPid();
            if ($pid) {
                static::killProcess($pid, 9, $loop);
            }
        });
        $id = bin2hex(random_bytes(16));
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        // Timeout
        if ($timeout > 0) {
            $timer = $loop->addTimer($timeout, function () use ($proc, &$err, $timeout, $loop, $defer) {
                $err = new Exception('Timed out after ' . $timeout . ' secs');
                $defer->reject($err);
                $proc->stdin->end();
                foreach ($proc->pipes as $pipe) {
                    $pipe->close();
                }
                $pid = $proc->getPid();
                if ($pid) {
                    static::killProcess($pid, 15, $loop);
                }
            });
        }
        static::resolve(function () use ($loop, $timeout, $defer, $id, $trace, $proc, $outputfn, $timer, &$err) {
            yield static::waitFreeFork($loop);
            static::addFork($id, $defer->promise());
            $buffer = '';
            $echo = false;
            if (!empty(getenv('ASYNC_EXECUTE_ECHO'))) {
                $echo = true;
            }
            $proc->start($loop);
            // TODO: Writes buffer to a file if it gets too large
            if ($outputfn) {
                $proc->stdout->on('data', $outputfn);
            } else {
                $proc->stdout->on('data', function ($chunk) use (&$buffer, $echo) {
                    $buffer .= $chunk;
                    if ($echo) {
                        $first = "  [ASYNC EXECUTE]  ";
                        $chunk = trim($chunk);
                        $lines = explode("\n", $chunk);
                        foreach ($lines as $line) {
                            fwrite(STDOUT, $first . $line . "\n");
                        }
                    }
                });
            }
            $proc->stdout->on('error', function (\Exception $e) use (&$err) {
                $err = $e;
            });
            $proc->on('exit', function ($exitCode, $termSignal) use ($defer, &$buffer, $timer, $loop, &$err, $proc, $id, $trace) {
                static::removeFork($id);
                if ($timer) {
                    $loop->cancelTimer($timer);
                }
                $proc->stdin->end();
                $proc->stdin->close();
                $proc->stdout->close();
                foreach ($proc->pipes as $pipe) {
                    $pipe->close();
                }
                if ($err) {
                    return $defer->reject($err);
                }
                if (!is_null($termSignal)) {
                    return $defer->reject(new Exception('Process terminated with code: ' . $termSignal, $termSignal, $trace));
                }
                if ($exitCode) {
                    return $defer->reject(new Exception('Process exited with code: ' . $exitCode . "\n$buffer", $exitCode, $trace));
                }
                $defer->resolve($buffer);
                $pid = $proc->getPid();
                if ($pid) {
                    static::killProcess($pid, 15, $loop);
                }
            });
        });
        return $defer->promise();
    }



    /**
     * Timeout
     */
    public static function timeout($fn, float $timeout)
    {
        return static::timeoutWithLoop(static::getLoop(), $fn, $timeout);
    }
    public static function timeoutWithLoop(LoopInterface $loop, $fn, float $timeout)
    {
        if (is_a($fn, PromiseInterface::class)) {
            $promise = $fn;
        } else {
            $promise = static::resolve($fn, $loop);
        }
        $defer = new Deferred(function ($resolve, $reject) use ($promise) {
            $promise->cancel();
            $reject(new CancelException());
        });
        $timer = $loop->addTimer($timeout, function () use ($defer, $timeout, $promise) {
            // Note: This could also return a timeout exception instead of an async\exception
            $defer->reject(new Exception('Timed out after ' . $timeout . ' secs'));
            $promise->cancel();
        });
        $promise->done(
            function ($res) use ($defer, $loop, $timer) {
                $loop->cancelTimer($timer);
                $defer->resolve($res);
            },
            function ($e) use ($defer, $loop, $timer) {
                $loop->cancelTimer($timer);
                $defer->reject($e);
            }
        );
        return $defer->promise();
    }



    /**
     * Retry
     */
    public static function retry($fn, int $retries = 10, float $frequency = 0.001, $ignores = null)
    {
        return static::retryWithLoop(static::getLoop(), $fn, $retries, $frequency, $ignores);
    }
    public static function retryWithLoop(LoopInterface $loop, $fn, int $retries = 10, float $frequency = 0.001, $ignores = null)
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $promise = null;
        $timer = null;
        $defer = new Deferred(function ($resolve, $reject) use (&$promise, &$timer, $loop) {
            if ($promise) {
                $promise->cancel();
            }
            if ($timer) {
                $loop->cancelTimer($timer);
            }
            $reject(new CancelException());
        });
        if (is_null($ignores)) {
            $ignores = \Exception::class;
        }
        if (!is_array($ignores)) {
            $ignores = [$ignores];
        }
        $error = null;
        $on_success = function ($res) use ($defer) {
            $defer->resolve($res);
        };
        $on_fail = function ($e) use (&$promise, &$error, $defer, $ignores) {
            $error = $e;
            $error_message = $e->getMessage();
            $raise = true;
            foreach ($ignores as $ignore) {
                if ($error_message == $ignore || is_a($e, $ignore)) {
                    $raise = false;
                    break;
                }
            }
            if ($raise) {
                $defer->reject($error);
            } else {
                $promise = null;
            }
        };
        $retries--;
        $promise = static::resolve($fn, $loop);
        $promise->done($on_success, $on_fail);
        $timer = $loop->addPeriodicTimer(
            $frequency,
            function ($timer) use (
                $fn,
                &$retries,
                &$error,
                $defer,
                &$promise,
                &$trace,
                $on_success,
                $on_fail,
                $loop
            ) {
                if (is_null($promise)) {
                    if ($retries < 0) {
                        if (empty($error)) {
                            $error = new Exception('Retry exhausted attempts', 0, $trace);
                        }
                        $loop->cancelTimer($timer);
                        return $defer->reject($error);
                    }
                    $retries--;
                    $promise = static::resolve($fn, $loop);
                    $promise->done($on_success, $on_fail);
                }
            }
        );
        $defer->promise()->always(function () use ($timer, $loop) {
            if ($timer) {
                $loop->cancelTimer($timer);
            }
        });
        return $defer->promise();
    }



    /**
     * Async
     */
    public static function async($fn, array $args = [])
    {
        return static::asyncWithLoop(static::getLoop(), $fn, $args);
    }
    public static function asyncWithLoop(LoopInterface $loop, $fn, array $args = [])
    {
        if (!extension_loaded('pcntl')) {
            throw new Exception('pcntl extension required', 500);
        }
        if (!extension_loaded('posix')) {
            throw new Exception('posix extension required', 500);
        }
        if (!extension_loaded('sockets')) {
            throw new Exception('sockets extension required', 500);
        }
        $pid = false;
        $id = bin2hex(random_bytes(16));
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $defer = new Deferred(function () use (&$pid, $loop) {
            static::removeFork($id);
            if ($pid) {
                static::killProcess($pid, 9, $loop);
            }
        });
        $defer->promise()->always(function () use ($id, &$pid, $loop) {
            static::removeFork($id);
            if ($pid) {
                static::killProcess($pid, 9, $loop);
            }
        });
        static::waitFreeFork($loop)->done(
            function () use ($loop, $fn, &$args, $defer, $id, &$trace, &$pid) {
                static::addFork($id, $defer->promise());
                // Only linux sockets
                $sockets = [];
                if (socket_create_pair(\AF_UNIX, \SOCK_STREAM, 0, $sockets) === false) {
                    return $defer->reject(new Exception('socket_create_pair failed: ' . socket_strerror(socket_last_error()), 0, $trace));
                }
                gc_collect_cycles(); // Force GC before fork
                $pid = pcntl_fork();
                if ($pid == -1) {
                    return $defer->reject(new Exception('Async fork failed', 0, $trace));
                } elseif ($pid) {
                    // Parent
                    $buffer = '';
                    $loop->addPeriodicTimer(0.001, function ($timer) use ($pid, $defer, &$sockets, $loop, &$buffer, $id, $trace) {
                        while (($data = socket_recv($sockets[1], $chunk, 1024, \MSG_DONTWAIT)) > 0) { // !== false) {
                            $buffer .= $chunk;
                        }
                        $waitpid = pcntl_waitpid($pid, $status, \WNOHANG);
                        if ($waitpid == $pid) {
                            $loop->cancelTimer($timer);
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
                            $error_code = pcntl_get_last_error();
                            $error_str = pcntl_strerror($error_code);
                            fwrite(STDOUT, "error: $error_str\n\n");
                            $loop->cancelTimer($timer);
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
                    static::$forks=[];
                    $loop = static::getLoop();
                    $loop->stop();
                    // Child
                    register_shutdown_function(function () use ($loop) {
                        // We use SIGKILL because it doesn't close resources
                        posix_kill(getmypid(), 9);
                    });
                    $sid = posix_setsid();
                    if ($sid < 0) {
                        exit(1);
                    }
                    try {
                        if ($fn instanceof PromiseInterface) {
                            $res = static::syncWithLoop($loop, $fn);
                        } else {
                            $res = static::syncWithLoop($loop, call_user_func_array($fn, $args));
                        }
                        $res = serialize($res);
                    } catch (\Exception $e) {
                        $err = new Exception($e->getMessage(), $e->getCode(), $trace);
                        $res = serialize($err);
                    }
                    $written = socket_send($sockets[0], $res, strlen($res), MSG_EOR|MSG_EOF);
                    if ($written === false) {
                        exit(1);
                    }
                    exit(0);
                }
            },
            function ($e) use ($defer) {
                $defer->reject($e);
            }
        );
        return $defer->promise();
    }



    /**
     * Unwraps a generator and solves yielded promises using a LoopEvent
     */
    private static function unwrapGeneratorWithLoop(Generator $generator, LoopInterface $loop, int $depth = 0)
    {
        $promise = $generator->current();
        $defer = new Deferred(function ($resolve, $reject) use ($generator, $promise) {
            if (is_a($promise, Promise\Promise::class)) {
                $promise->cancel();
            }
            $generator->throw(new CancelException());
        });
        $func = function () use ($generator, $defer, &$promise, $loop, &$func, $depth) {
            try {
                while (is_a($promise, Closure::class)) {
                    $promise = $promise();
                }
                while (is_a($promise, Generator::class)) {
                    $promise = static::unwrapGeneratorWithLoop($promise, $loop, $depth + 1);
                }
            } catch (\Throwable $e) {
                $promise = new RejectedPromise($e);
            }
            if (!is_a($promise, PromiseInterface::class)) {
                $promise = new FulfilledPromise($promise);
            }
            $promise
                ->then(
                    function ($res) use ($generator, $defer, &$promise, $loop, &$func) {
                        try {
                            $generator->send($res);
                            if ($generator->valid()) {
                                $promise = $generator->current();
                                $loop->addTimer(0, $func);
                            } else {
                                $return = $generator->getReturn();
                                return $defer->resolve($return);
                            }
                        } catch (\Throwable $e) {
                            return $defer->reject($e);
                        }
                    },
                    function ($e) use ($generator, $defer, &$promise, $loop, &$func) {
                        try {
                            if (is_array($e)) {
                                while (($te=array_shift($e))) {
                                    $generator->throw($te);
                                    if ($generator->valid()) {
                                        $promise = $generator->current();
                                        $loop->addTimer(0, $func);
                                    } else {
                                        $return = $generator->getReturn();
                                        return $defer->resolve($return);
                                    }
                                }
                            } else {
                                $generator->throw($e);
                                if ($generator->valid()) {
                                    $promise = $generator->current();
                                    $loop->addTimer(0, $func);
                                } else {
                                    $return = $generator->getReturn();
                                    return $defer->resolve($return);
                                }
                            }
                        } catch (\Throwable $ee) {
                            return $defer->reject($ee);
                        }
                    }
                );
        };
        $loop->addTimer(0, $func);
        return $defer->promise();
    }



    /**
     * Unwraps a generator and solves yielded promises
     */
    private static function unwrapGenerator(Generator $generator, int $depth = 0)
    {
        $promise = $generator->current();
        try {
            while (is_a($promise, Closure::class)) {
                $promise = $promise();
            }
        } catch (\Throwable $e) {
            return new RejectedPromise($e);
        }
        while (is_a($promise, Generator::class)) {
            $promise = static::unwrapGenerator($promise, $depth + 1);
        }
        if (!is_a($promise, PromiseInterface::class)) {
            if (!$generator->valid()) {
                return $generator->getReturn();
            }
            try {
                $generator->send($promise);
            } catch (\Throwable $e) {
                if ($generator->valid()) {
                    $generator->throw($e);
                } else {
                    return new RejectedPromise($e);
                }
            }
            if ($generator->valid()) {
                return static::unwrapGenerator($generator, $depth + 1);
            }
            return $generator->getReturn();
        }
        $defer = new Deferred(function ($resolve, $reject) use ($generator, $promise) {
            if (is_a($promise, Promise\Promise::class)) {
                $promise->cancel();
            }
            // $reject(new CancelException());
            $generator->throw(new CancelException());
        });
        $promise
            ->then(
                function ($res) use ($generator, $depth) {
                    try {
                        $generator->send($res);
                    } catch (\Throwable $e) {
                        if ($generator->valid()) {
                            $generator->throw($e);
                        } else {
                            throw $e;
                        }
                    }
                    if ($generator->valid()) {
                        return static::unwrapGenerator($generator, $depth + 1);
                    }
                    return $generator->getReturn();
                },
                function ($e) use ($generator, $depth) {
                    try {
                        $generator->throw($e);
                    } catch (\Throwable $e2) {
                        if ($generator->valid()) {
                            $generator->throw($e2);
                        } else {
                            throw $e2;
                        }
                    }
                    if ($generator->valid()) {
                        return static::unwrapGenerator($generator, $depth + 1);
                    }
                    return $generator->getReturn();
                }
            )
            ->then(
                function ($res) use ($defer) {
                    return $defer->resolve($res);
                },
                function ($e) use ($defer) {
                    return $defer->reject($e);
                }
            );
        return $defer->promise();
    }



    /**
     * Resolves silently.
     * Uses resolve.
     */
    public static function resolveSilent($fn, &$exception=false, ?LoopInterface $loop = null)
    {
        $prom = static::resolve($fn, $loop);
        $defer = new Deferred(function ($resolve, $reject) use ($prom, &$exception) {
            $prom->cancel();
            $exception = new CancelException();
            $resolve();
        });
        $prom->done(
            function ($res) use ($defer) {
                $defer->resolve($res);
            },
            function ($e) use (&$exception, $defer) {
                $exception = $e;
                $defer->resolve();
            }
        );
        return $defer->promise();
    }



    /**
     * Resolves multiple things:
     * - Closure
     * - Generator
     * - Promise
     * - Stream
     */
    public static function resolve($fn, ?LoopInterface $loop = null)
    {
        if (is_a($fn, Closure::class)) {
            try {
                while (is_a($fn, Closure::class)) {
                    $fn = $fn();
                }
            } catch (\Exception $e) {
                return new RejectedPromise($e);
            }
        }
        if (is_a($fn, Generator::class)) {
            if (is_null($loop)) {
                $loop = static::$loop;
            }
            if ($loop) {
                $fn = static::unwrapGeneratorWithLoop($fn, $loop);
            } else {
                while (is_a($fn, Generator::class)) {
                    $fn = static::unwrapGenerator($fn);
                }
            }
        }
        if (is_a($fn, ReadableStreamInterface::class)) {
            $prom = Stream\buffer($fn);
        } elseif (is_a($fn, PromiseInterface::class)) {
            $prom = $fn;
        } else {
            return new FulfilledPromise($fn);
        }
        $canceled = false;
        $defer = new Deferred(function ($resolve, $reject) use ($prom, &$canceled) {
            $canceled = true;
            if (is_a($prom, Promise\Promise::class)) {
                $prom->cancel();
            }
            $reject(new CancelException());
        });
        $prom
            ->then(
                function ($res) use ($defer) {
                    $defer->resolve($res);
                },
                function ($e) use ($defer) {
                    $defer->reject($e);
                }
            );
        return $defer->promise();
    }


    /**
     * Calls a function in async mode
     */
    public static function call($fn, $args)
    {
        $first = current($args);
        if ($first instanceof LoopInterface) {
            $loop = array_shift($args);
        } else {
            $loop = static::getLoop();
        }
        return static::asyncWithLoop($loop, $fn, $args);
    }


    /**
     * Recursive glob
     */
    public static function rglob(string $pattern, string $ignore = '', int $flags = 0)
    {
        return static::rglobWithLoop(static::getLoop(), $pattern, $ignore, $flags);
    }
    public static function rglobWithLoop(LoopInterface $loop, string $pattern, string $ignore = '', int $flags = 0)
    {
        return static::resolve(function () use ($loop, $pattern, $ignore, $flags) {
            $ignore_exp = false;
            $ignore_str = false;
            if ($ignore) {
                $res = yield static::asyncWithLoop($loop, function () use ($ignore) {
                    error_reporting(0);
                    return preg_match($ignore, null) === false ? false : true;
                });
                if ($res === false) {
                    $ignore_str = $ignore;
                } else {
                    $ignore_exp = $ignore;
                }
            }
            if ($ignore_exp) {
                if (preg_match($ignore_exp, $pattern)) {
                    return [];
                }
            } elseif ($ignore_str) {
                if (strpos($pattern, $ignore_str) !== false) {
                    return [];
                }
            }
            $files = yield static::asyncWithLoop($loop, 'glob', [$pattern, $flags]);
            foreach ($files as $pos => $file) {
                if ($ignore_exp) {
                    if (preg_match($ignore_exp, $file)) {
                        unset($files[$pos]);
                    }
                } elseif ($ignore_str) {
                    if (strpos($file, $ignore_str) !== false) {
                        unset($files[$pos]);
                    }
                }
            }
            $pattern_name = basename($pattern);
            $pattern_dir = dirname($pattern);
            $pattern_dir_subs = $pattern_dir . DIRECTORY_SEPARATOR . '*';
            $dirs = yield static::asyncWithLoop($loop, 'glob', [$pattern_dir_subs, GLOB_ONLYDIR | GLOB_NOSORT ]);
            if (empty($dirs)) {
                return $files;
            }
            foreach ($dirs as $dir) {
                if ($ignore_exp) {
                    if (preg_match($ignore_exp, $dir)) {
                        continue;
                    }
                } elseif ($ignore_str) {
                    if (strpos($dir, $ignore_str) !== false) {
                        continue;
                    }
                }
                $tmp = yield static::rglobWithLoop($loop, $dir . DIRECTORY_SEPARATOR . $pattern_name, $flags);
                if (empty($tmp)) {
                    continue;
                }
                $files = array_merge($files, $tmp);
            }
            return $files;
        }, $loop);
    }


    /**
     * Checks if a promise has finished without needing to wait for it
     */
    public static function isDone(PromiseInterface $promise, &$result = null)
    {
        return static::isDoneWithLoop(static::getLoop(), $promise, $result);
    }
    public static function isDoneWithLoop(LoopInterface $loop, PromiseInterface $promise, &$result = null)
    {
        if (!($key = array_search($promise, static::$dones_promises))) {
            $key = ++static::$dones_key;
            if ($key === PHP_INT_MAX) {
                static::$dones_key = 0;
            }
            $good = function ($res) use ($key, $loop) {
                $loop->stop();
                static::$dones_res[$key] = $res;
                static::$dones[$key] = 1;
                unset(static::$dones_promises[$key]);
            };
            $bad = function ($e) use ($key, $loop) {
                $loop->stop();
                static::$dones_res[$key] = $e;
                static::$dones[$key] = -1;
                unset(static::$dones_promises[$key]);
            };
            static::$dones_promises[$key] = $promise;
            $promise->then($good, $bad);
        }
        $loop->addTimer(0, function () use ($loop) {
            $loop->stop();
        });
        try {
            $loop->run();
        } catch (\RuntimeException $e) {
            if ($e->getMessage() != 'Can\'t shift from an empty datastructure') {
                throw $e;
            }
        }
        $res = static::$dones[$key] ?? false;
        if ($res) {
            $result = static::$dones_res[$key];
        }
        unset(static::$dones[$key]);
        unset(static::$dones_res[$key]);
        return $res;
    }



    /**
     * Waits for this memory space
     */
    public static function waitMemory(int $bytes, float $freq=0.001)
    {
        return static::waitMemoryWithLoop(static::getLoop(), $bytes, $freq);
    }
    public static function waitMemoryWithLoop(LoopInterface $loop, int $bytes, float $freq=0.001)
    {
        $bytes += 16384;    // Required memory for this wait
        if (is_null(static::$mem_limit)) {
            static::loadMemLimit();
        }
        if (is_null(static::$mem_limit)) {
            return -1;
        }
        $usage = memory_get_usage();
        $diff = static::$mem_limit - $usage;
        if ($bytes > static::$mem_limit) {
            throw new Exception('Cannot wait for more memory than the allowed limit of '.static::$mem_limit);
        }
        if ($diff >= $bytes) {
            return $diff;
        }
        $timer = null;
        $defer = new Deferred(function ($resolve, $reject) use (&$timer, $loop) {
            if ($timer) {
                $loop->cancelTimer($timer);
            }
            $reject(new CancelException());
        });
        $timer = $loop->addPeriodicTimer($freq, function ($timer) use ($defer, $bytes, $loop) {
            $usage = memory_get_usage();
            $diff = static::$mem_limit - $usage;
            if ($diff >= $bytes) {
                $loop->cancelTimer($timer);
                $defer->resolve($diff);
                unset($defer);
            }
        });
        return $defer->promise();
    }


    /**
     * Measures the time of a promise
     */
    public static function timer($fn, &$time)
    {
        return static::timerWithLoop(static::getLoop(), $fn, $time);
    }
    public static function timerWithLoop(LoopInterface $loop, $fn, &$time)
    {
        $start = microtime(true);
        $prom = static::resolve($fn, $loop);
        $prom->always(function () use ($start, &$time) {
            $time = microtime(true) - $start;
        });
        return $prom;
    }
}
