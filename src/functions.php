<?php
namespace Choval\Async;

/**
 *
 * By default all functions are async and return a promise,
 * unless the method name has Sync in it
 *
 */

use Clue\React\Block;
use React\Promise\Deferred;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Promise\Stream;
use React\Promise\RejectedPromise;
use React\Promise;



/**
 *
 * Executes a command, returns the buffered response
 *
 */
function execute(LoopInterface $loop, string $cmd, float $timeout=-1, &$exitCode=0, &$termSignal=0) {
  return Async::execute($loop, $cmd, $timeout, $exitCode, $termSignal);
}



/**
 *
 * Resolves a generator
 *
 */
function resolve_generator($gen) {
  return Async::resolve_generator($gen);
}



/**
 *
 * Non blocking sleep, that allows Loop to keep ticking in the back
 *
 */
function sleep(LoopInterface $loop, float $time) {
  return Async::sleep($loop, $time);
}



/**
 *
 * Wait for a promise (makes code synchronous) or stream (buffers)
 *
 */
function sync(LoopInterface $loop, $promise, float $timeout = NULL ) {
  return Async::sync($loop, $promise, $timeout);
}



/**
 *
 * Retries a function for X times and eventually returns the exception.
 *
 */
function retry(LoopInterface $loop, $func, int &$retries=10, float $frequency=0.1, string $type=null) {
  return Async::retry($loop, $func, $retries, $frequency, $type);
}



/**
 *
 * Runs blocking code asynchronously.
 * Returns a promise
 *
 */
function async(LoopInterface $loop, $func, array $args=[]) {
  return Async::async($loop, $func, $args);
}



/**
 *
 * Run promises one after the other
 * Chained... Returns a promise too
 *
 * Receives an array of functions that will be called one after the other
 *
 */
function chain_resolve() {
  return call_user_func_array( [Async::class, 'chain_resolve'], func_get_args());
}



