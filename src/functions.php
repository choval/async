<?php

namespace Choval\Async {

/**
 * By default all functions are async and return a promise,
 * unless the method name has Sync in it
 */

use Choval\Async\Async;
use Closure;
use Generator;
use React\EventLoop\LoopInterface;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\Stream;
use React\Stream\ReadableStreamInterface;

/**
 * Creates and sets the event-loop
 *
 * @return LoopInterface
 */
function init()
{
    return Async::getLoop();
}



/**
 * Loads a memory limit
 * This is called automatically on set_loop/init
 *
 * @param string $limit = null
 * @return int
 */
function load_memory_limit(?string $limit = null)
{
    return Async::loadMemLimit($limit);
}



/**
 * Alias of load_memory_limit
 *
 * @param string $limit = null
 * @return int
 */
function load_mem_limit(?string $limit = null)
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
 * @param LoopInterface $loop
 * @param string $cmd
 * @param float $timeout
 *
 * @return mixed
 */
function wait(
    $promise,
    ?float $timeout = null,
    float $interval = 0.0001,
    ?LoopInterface $loop = null
) {
    $loop ??= Async::getLoop();
    return Async::waitWithLoop(
        loop: $loop,
        promise: $promise,
        timeout: $timeout,
        interval: $interval
    );
}



/**
 * Alias of wait
 *
 * @param LoopInterface $loop
 * @param string $cmd
 * @param float $timeout
 *
 * @return mixed
 */
function sync(
    $promise,
    ?float $timeout = null,
    float $interval = 0.0001,
    ?LoopInterface $loop = null
) {
    $loop ??= Async::getLoop();
    return Async::syncWithLoop(
        loop: $loop,
        promise: $promise,
        timeout: $timeout,
        interval: $interval
    );
}



/**
 * Non blocking sleep, that allows Loop to keep ticking in the back
 *
 * @param LoopInterface $loop
 * @param float $time
 *
 * @return Promise
 */
function sleep(
    float $time,
    ?LoopInterface $loop = null
) {
    $loop ??= Async::getLoop();
    return Async::sleepWithLoop(
        loop: $loop,
        time: $time
    );
}



/**
 * Executes a command, returns the buffered response
 *
 * @param LoopInterface $loop
 * @param string $cmd
 * @param float $timeout = -1
 *
 * @return Promise
 */
function execute(
    string $cmd,
    float $timeout = 0,
    ?callable $outputfn=null,
    ?LoopInterface $loop=null
) {
    $loop ??= Async::getLoop();
    return Async::executeWithLoop(
        loop: $loop,
        cmd: $cmd,
        timeout: $timeout,
        outputfn: $outputfn
    );
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
function async(
    $fn,
    array $args = [],
    ?LoopInterface $loop = null
) {
    $loop ??= Async::getLoop();
    return Async::asyncWithLoop(
        loop: $loop,
        fn: $fn,
        args: $args
    );
}



/**
 * Retries a function for X times and eventually returns the exception.
 *
 * @param ?LoopInterface $loop
 * @param $fn
 * @param int $retries = 10
 * @param float $frequency = 0.1
 * @param ?mixed $ignores
 *
 * @return Promise
 */
function retry(
    $fn,
    int $retries = 10,
    float $frequency = 0.01,
    $ignores = null,
    ?LoopInterface $loop = null
) {
    $loop ??= Async::getLoop();
    return Async::retryWithLoop(
        loop: $loop,
        fn: $fn,
        retries: $retries,
        frequency: $frequency,
        ignores: $ignores
    );
}



/**
 * Resolves a Generator or Closure
 *
 * @param LoopInterface $loop
 * @param Generator|Closure $fn
 *
 * @return Promise
 */
function resolve($fn, ?LoopInterface $loop = null)
{
    return Async::resolve($fn, $loop);
}



/**
 * Calls and saves exceptions instead of throwing them
 *
 * @param LoopInterface $loop
 * @param Generator|Closure|Promise $fn
 * @param Exception $e
 *
 * @return Promise
 */
function silent($fn, &$exception=false, ?LoopInterface $loop = null)
{
    return Async::resolveSilent($fn, $exception, $loop);
}



/**
 * Buffers a stream and returns a promise
 *
 * @param ReadableStreamInterface $stream
 * @param int $maxLength
 *
 * @return Promise
 */
function buffer(ReadableStreamInterface $stream, int $maxLength = null)
{
    return Stream\buffer($stream, $maxLength);
}


/**
 * Timeout
 *
 * @param LoopInterface $loop
 * @param Generator|Closure|Promise $fn
 * @param float $timeout
 *
 * @return Promise
 */
function timeout($fn, float $timeout, ?LoopInterface $loop=null)
{
    $loop ??= Async::getLoop();
    return Async::timeoutWithLoop($loop, $fn, $timeout);
}


/**
 * File get contents
 *
 * @param string $filename
 * @param bool $use_include_path = false
 * @param Resource $context = ?
 * @param int $offset = 0
 * @param int $maxlen = ?
 *
 * @return string|false
 */
function file_get_contents(
    string $filename,
    bool $use_include_path = false,
    $context = null,
    int $offset = 0,
    ?int $length = null
) {
    return Async::call('file_get_contents', func_get_args());
}


/**
 * File put contents
 */
function file_put_contents(
    string $filename,
    mixed $data,
    int $flags = 0,
    $context = null
) {
    return Async::call('file_put_contents', func_get_args());
}


/**
 * File exists
 */
function file_exists(
    string $filename
) {
    return Async::call('file_exists', func_get_args());
}


function is_file(
    string $filename
) {
    return Async::call('is_file', func_get_args());
}


function is_dir(
    string $filename
) {
    return Async::call('is_dir', func_get_args());
}


function is_link(
    string $filename
) {
    return Async::call('is_link', func_get_args());
}


function sha1_file(string $filename, bool $binary = false)
{
    return Async::call('sha1_file', func_get_args());
}


function md5_file(string $filename, bool $binary = false)
{
    return Async::call('md5_file', func_get_args());
}

function mime_content_type($filename)
{
    return Async::call('mime_content_type', func_get_args());
}


function realpath(string $path)
{
    return Async::call('realpath', func_get_args());
}


function fileatime(string $filename)
{
    return Async::call('fileatime', func_get_args());
}

function filectime(string $filename)
{
    return Async::call('filectime', func_get_args());
}

function filemtime(string $filename)
{
    return Async::call('filemtime', func_get_args());
}

function file(string $filename, int $flags = 0, $context = null)
{
    return Async::call('file', func_get_args());
}

function filesize(string $filename)
{
    return ASync::call('filesize', func_get_args());
}

function copy(string $from, string $to, $context = null)
{
    return Async::call('copy', func_get_args());
}

function rename(string $from, string $to, $context = null)
{
    return Async::call('rename', func_get_args());
}

function unlink(string $filename, $context = null)
{
    return Async::call('unlink', func_get_args());
}

function touch(string $filename, ?int $mtime = null, ?int $atime = null)
{
    return Async::call('touch', func_get_args());
}

function mkdir(
    string $directory,
    int $permissions = 0777,
    bool $recursive = false,
    $context = null
) {
    return Async::call('mkdir', func_get_args());
}

function rmdir(string $directory, $context = null)
{
    return Async::call('rmdir', func_get_args());
}

function scandir(string $directory, int $sorting_order = SCANDIR_SORT_ASCENDING, $context = null)
{
    return Async::call('scandir', func_get_args());
}

function glob(string $pattern, int $flags = 0)
{
    return Async::call('glob', func_get_args());
}


/**
 * Recursive glob
 *
 * @return array
 */
function rglob(string $pattern, string $ignore = '', int $flags = 0, ?LoopInterface $loop=null)
{
    $loop ??= Async::getLoop();
    return Async::rglobWithLoop($loop, $pattern, $ignore, $flags);
}


/**
 * Checks if a promise is done
 *
 * @param Promise $promise
 * @param mixed $result
 *
 * @return bool
 */
function is_done(PromiseInterface $promise, &$result=null, ?LoopInterface $loop=null)
{
    $loop ??= Async::getLoop();
    return Async::isDoneWithLoop($loop, $promise, $result);
}


/**
 * Waits for an amount of bytes
 * before resolving with the diff.
 *
 * @return int
 */
function wait_memory(int $bytes, float $freq=0.000001, ?LoopInterface $loop=null)
{
    $loop ??= Async::getLoop();
    return Async::waitMemoryWithLoop($loop, $bytes, $freq);
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
function timer($fn, &$time, ?LoopInterface $loop=null)
{
    $loop ??= Async::getLoop();
    return Async::timerWithLoop($loop, $fn, $time);
}


/**
 * Checks if an expression is a valid regexp
 *
 * @param string $exp
 * @param LoopInterface $loop = ?
 *
 * @return bool
 */
function is_valid_regexp(string $regexp, ?LoopInterface $loop=null)
{
    $loop ??= Async::getLoop();
    return Async::asyncWithLoop($loop, function () use ($regexp) {
        error_reporting(0);
        return preg_match($regexp, null) === false ? false : true;
    });
}



}
