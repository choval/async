<?php

namespace Choval\Async;

/**
 *
 * By default all functions are async and return a promise,
 * unless the method name has Sync in it
 *
 */

use Choval\Async\Async;
use Clue\React\Block;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise;
use React\Promise\Deferred;
use React\Promise\RejectedPromise;
use React\Promise\Stream;

/**
 * Sets the loop
 *
 * @param LoopInterface $loop
 */
function set_loop(LoopInterface $loop)
{
    return Async::setLoop($loop);
}



/**
 * Gets the loop
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
 * @param LoopInterface $loop (optional)
 * @param string $cmd
 * @param float $timeout=NULL
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
 * @param LoopInterface $loop (optional)
 * @param string $cmd
 * @param float $timeout=NULL
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
 * @param LoopInterface $loop (optional)
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
 * @param LoopInterface $loop (optional)
 * @param string $cmd
 * @param float $timeout=-1 (optional)
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
 * @param LoopInterface $loop (optional)
 * @param callable $func
 * @param array $args=[] (optional)
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
 * @param LoopInterface $loop (optional)
 * @param callable $func
 * @param int $retries=10 (optional)
 * @param float $frequency=0.1 (optional)
 * @param string $type (optional) The Throwable class to catch or string to match
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
 * @param Generator|Closure $gen
 *
 * @return Promise
 */
function resolve($gen)
{
    return Async::resolve($gen);
}



/**
 * Alias of resolve
 *
 * @param Generator|Closure $gen
 *
 * @return Promise
 */
function resolve_generator($gen)
{
    return Async::resolve($gen);
}



/**
 * Buffers a stream and returns a promise
 *
 * @param ReadableStreamInterface $stream
 * @param int $maxLength=null
 *
 * @return Promise
 */
function buffer(ReadableStreamInterface $stream, $maxLength = null)
{
    return Async::buffer($stream, $maxLength);
}


/**
 *
 * Run promises one after the other
 * Chained... Returns a promise too
 *
 * Receives an array of functions that will be called one after the other
 *
 */
function chain_resolve()
{
    return call_user_func_array([Async::class, 'chainResolve'], func_get_args());
}


/**
 *
 * Timeout
 *
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
 * true: Resolved or Rejected
 * false: pending
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
