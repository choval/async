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
    if(!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array( [Async::class, 'waitWithLoop'], $args );
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
    if(!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array( [Async::class, 'syncWithLoop'], $args );
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
    if(!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array( [Async::class, 'sleepWithLoop'], $args );
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
    if(!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array( [Async::class, 'executeWithLoop'], $args );
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
    if(!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array( [Async::class, 'asyncWithLoop'], $args );
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
    if(!($first instanceof LoopInterface)) {
        $loop = Async::getLoop();
        array_unshift($args, $loop);
    }
    return call_user_func_array( [Async::class, 'retryWithLoop'], $args );
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
    return Async::resolveGenerator($gen);
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
