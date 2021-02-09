<?php

namespace Choval\Async {

/**
 * By default all functions are async and return a promise,
 * unless the method name has Sync in it
 */

use Choval\Async\Async;
use Closure;
use Generator;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\Stream;
use React\Stream\ReadableStreamInterface;

/**
 * Creates and sets the event-loop
 *
 * @return LoopInterface
 */
function init()
{
    $loop = Factory::create();
    Async::setLoop($loop);
    return $loop;
}



/**
 * Loads a memory limit
 * This is called automatically on set_loop/init
 *
 * @param string $limit = null
 * @return int
 */
function load_memory_limit($limit = null)
{
    return Async::loadMemLimit($limit);
}



/**
 * Alias of load_memory_limit
 *
 * @param string $limit = null
 * @return int
 */
function load_mem_limit($limit = null)
{
    return Async::loadMemLimit($limit);
}



/**
 * Sets the loop
 *
 * @param LoopInterface $loop
 *
 * @return void
 */
function set_loop(LoopInterface $loop)
{
    return Async::setLoop($loop);
}



/**
 * Gets the event-loop
 *
 * @return LoopInterface
 */
function get_loop()
{
    return Async::getLoop();
}



/**
 * Sets the forking limit for async
 *
 * @param int $limit
 *
 * @return void
 */
function set_forks_limit(int $limit)
{
    return Async::setForksLimit($limit);
}



/**
 * Gets the forking limit for async
 *
 * @return int
 */
function get_forks_limit()
{
    return Async::getForksLimit();
}



/**
 * Wait for a promise (makes code synchronous) or stream (buffers)
 *
 * @param LoopInterface $loop = ?
 * @param string $cmd
 * @param float $timeout = ?
 *
 * @return mixed
 */
function wait()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'waitWithLoop'], $args);
}



/**
 * Alias of wait
 *
 * @param LoopInterface $loop = ?
 * @param string $cmd
 * @param float $timeout = ?
 *
 * @return mixed
 */
function sync()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'syncWithLoop'], $args);
}



/**
 * Non blocking sleep, that allows Loop to keep ticking in the back
 *
 * @param LoopInterface $loop = ?
 * @param float $time
 *
 * @return Promise
 */
function sleep()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'sleepWithLoop'], $args);
}



/**
 * Executes a command, returns the buffered response
 *
 * @param LoopInterface $loop = ?
 * @param string $cmd
 * @param float $timeout = -1
 *
 * @return Promise
 */
function execute()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'executeWithLoop'], $args);
}



/**
 * Runs blocking code asynchronously.
 *
 * @param LoopInterface $loop = ?
 * @param callable $func
 * @param array $args = []
 *
 * @return Promise
 */
function async()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'asyncWithLoop'], $args);
}



/**
 * Retries a function for X times and eventually returns the exception.
 *
 * @param LoopInterface $loop = ?
 * @param callable $func
 * @param int $retries = 10
 * @param float $frequency = 0.1
 * @param mixed $ignoreErrors = []
 *
 * @return Promise
 */
function retry()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'retryWithLoop'], $args);
}



/**
 * Resolves a Generator or Closure
 *
 * @param LoopInterface $loop = ?
 * @param Generator|Closure $gen
 *
 * @return Promise
 */
function resolve($gen, LoopInterface $loop = null)
{
    return Async::resolve($gen, $loop);
}



/**
 * Calls and saves exceptions instead of throwing them
 *
 * @param LoopInterface $loop = ?
 * @param Generator|Closure|Promise $gen
 * @param Exception $e
 *
 * @return Promise
 */
function silent($gen, &$exception=false, LoopInterface $loop = null)
{
    return Async::resolveSilent($gen, $exception, $loop);
}



/**
 * Buffers a stream and returns a promise
 *
 * @param ReadableStreamInterface $stream
 * @param int $maxLength = ?
 *
 * @return Promise
 */
function buffer(ReadableStreamInterface $stream, $maxLength = null)
{
    return Stream\buffer($stream, $maxLength);
}


/**
 * Timeout
 *
 * @param LoopInterface $loop = ?
 * @param Generator|Closure|Promise $func
 * @param float $timeout
 *
 * @return Promise
 */
function timeout()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'timeoutWithLoop'], $args);
}


/**
 * File get contents
 *
 * @param string $filename
 * @param bool $use_include_path = false
 * @param resource $context = ?
 * @param int $offset = 0
 * @param int $maxlen = ?
 *
 * @return string|false
 */
function file_get_contents()
{
    return Async::call('file_get_contents', func_get_args());
}


/**
 * File put contents
 */
function file_put_contents()
{
    return Async::call('file_put_contents', func_get_args());
}


/**
 * File exists
 */
function file_exists()
{
    return Async::call('file_exists', func_get_args());
}


function is_file()
{
    return Async::call('is_file', func_get_args());
}


function is_dir()
{
    return Async::call('is_dir', func_get_args());
}


function is_link()
{
    return Async::call('is_link', func_get_args());
}


function sha1_file()
{
    return Async::call('sha1_file', func_get_args());
}


function md5_file()
{
    return Async::call('md5_file', func_get_args());
}

function mime_content_type()
{
    return Async::call('mime_content_type', func_get_args());
}


function realpath()
{
    return Async::call('realpath', func_get_args());
}


function fileatime()
{
    return Async::call('fileatime', func_get_args());
}

function filectime()
{
    return Async::call('filectime', func_get_args());
}

function filemtime()
{
    return Async::call('filemtime', func_get_args());
}

function file()
{
    return Async::call('file', func_get_args());
}

function filesize()
{
    return ASync::call('filesize', func_get_args());
}

function copy()
{
    return Async::call('copy', func_get_args());
}

function rename()
{
    return Async::call('rename', func_get_args());
}

function unlink()
{
    return Async::call('unlink', func_get_args());
}

function touch()
{
    return Async::call('touch', func_get_args());
}

function mkdir()
{
    return Async::call('mkdir', func_get_args());
}

function rmdir()
{
    return Async::call('rmdir', func_get_args());
}

function scandir()
{
    return Async::call('scandir', func_get_args());
}

function glob()
{
    return Async::call('glob', func_get_args());
}


/**
 * Recursive glob
 *
 * @return array
 */
function rglob()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'rglobWithLoop'], $args);
}


/**
 * Checks if a promise is done
 *
 * @param Promise $promise
 * @param mixed $result
 *
 * @return bool
 */
function is_done()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'isDoneWithLoop'], $args);
}


/**
 * Waits for an amount of bytes
 * before resolving with the diff.
 *
 * @return int
 */
function wait_memory()
{
    $args = func_get_args();
    $first = current($args);
    if (!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array([Async::class, 'waitMemoryWithLoop'], $args);
}


/**
 * Measures the time it takes for a promise
 * to finish (resolve or reject)
 *
 * @param Closure|Generator|Promise $func
 * @param float $time
 *
 * @return mixed
 */
function timer($var1, &$var2, &$var3=null)
{
    if ($var1 instanceof LoopInterface) {
        return Async::timerWithLoop($var1, $var2, $var3);
    }
    return Async::timer($var1, $var2);
}


/**
 * Checks if an expression is a valid regexp
 *
 * @param string $exp
 * @param LoopInterface $loop = ?
 *
 * @return bool
 */
function is_valid_regexp()
{
    $args = func_get_args();
    $first = current($args);
    if ($first instanceof LoopInterface) {
        $loop = $first;
        array_shift($args);
    } else {
        $loop = Async::getLoop();
    }
    $exp = current($args);
    return Async::executeWithLoop($loop, __DIR__.'/is_valid_regexp '.escapeshellarg($exp))
        ->then(function ($res) {
            if ($res == 'yes') {
                return true;
            }
            return false;
        })
        ->otherwise(function () {
            return false;
        });
}



}
