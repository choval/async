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




/**
 *
 * Executes a command, returns the buffered response
 *
 */
function execute(LoopInterface $loop, string $cmd, float $timeout=-1, &$exitCode=0, &$termSignal=0) {
  if(is_null($timeout)) {
    $timeout = ini_get('max_execution_time');
  }
  if($timeout < 0) {
    $timeout = null;
  }
  $defer = new Deferred;
  $buffer = '';
  $proc = new Process($cmd);
  $timer = false;
  if($timeout > 0) {
    $timer = $loop->addTimer($timeout, function() use ($proc) {
      $proc->terminate();
    });
  }
  $proc->start( $loop );
  $proc->stdout->on('data', function($chunk) use (&$buffer) {
    $buffer .= $chunk;
  });
  $proc->on('exit', function($procExitCode, $procTermSignal) use ($defer, &$buffer, &$exitCode, &$termSignal, $cmd, $timer, $loop) {
    $exitCode = $procExitCode;
    $termSignal = $procTermSignal;
    if($timer) {
      $loop->cancelTimer($timer);
    }
    if($exitCode) {
      return $defer->reject(new \Exception('Process finished with code: '.$exitCode));
    }
    $defer->resolve($buffer);
  });
  return $defer->promise();
}







/**
 *
 * Resolves a generator
 *
 */
function resolve_generator(\Generator $gen) {
  $defer = new Deferred;
  $call = \Amp\call(function() use ($gen) { return yield from $gen; });
  $call->onResolve(function($err, $res) use ($defer) {
    if($err) {
      return $defer->reject($err);
    }
    return $defer->resolve($res);
  });
  return $defer->promise();
}




/**
 *
 * Non blocking sleep, that allows Loop to keep ticking in the back
 *
 */
function sleep(LoopInterface $loop, float $time) {
  $defer = new Deferred;
  $loop->addTimer($time, function() use ($defer) {
    $defer->resolve(true);
  });
  return $defer->promise();
}




/**
 *
 * Wait for a promise (makes code synchronous) or stream (buffers)
 *
 */
function sync(LoopInterface $loop, $promise ,float $timeout = -1 ) {
  if(is_null($timeout)) {
    $timeout = ini_get('max_execution_time');
  }
  if($timeout < 0) {
    $timeout = null;
  }
  if($promise instanceof \React\Promise\PromiseInterface) {
    return Block\await( $promise, $loop, $timeout );
  } else if($promise instanceof \Evenement\EventEmitterInterface) {
    return Block\await( Stream\buffer( $promise ), $loop, $timeout );
  } else if(is_array($promise)) {
    return Block\awaitAll( $promise, $loop, $timeout );
  } else if($promise instanceof \Generator) {
    return Block\await( resolve_generator( $promise ) , $loop, $timeout);
  }
  return $promise;
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
  // Accepts multiple params or an array
  $functions = func_get_args();
  if(count($functions) == 1 && is_array($functions[0])) {
    $functions = $functions[0];
  }
  // Prepares the end function
  $defer = new \React\Promise\Deferred;
  $results = [];
  $keys = array_keys($functions);
  // Dummy
  $prev = function() {
    return new \React\Promise\FulfilledPromise(true);
  };
  $functions[] = function() use ($defer, &$results, $keys) {
    $defer->resolve($results);
  };
  $key = 0;
  foreach($functions as $function) {
    $prev = function() use ($prev, $function, &$results, $key) {
      return $prev()
        ->then(function() use (&$results, $key, $function) {
          $subd = new \React\Promise\Deferred;
          $function()
            ->then(function($res) use (&$results, $key, $function, $subd) {
              $results[ $key ] = $res;
              $subd->resolve(true);
            })
            ->otherwise(function($e) use (&$results, $key, $function, $subd) {
              $results[ $key ] = $e; // ->getMessage();
              $subd->resolve(false);
            });
          return $subd->promise();
        });
    };
    $key++;
  }
  $prev();
  return $defer->promise();
}



