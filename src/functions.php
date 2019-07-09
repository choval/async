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
      $proc->stdin->end();
      foreach ($proc->pipes as $pipe) {
        $pipe->close();
      }
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
function resolve_generator($gen) {
  $defer = new Deferred;
  if($gen instanceof \Generator) {
    $call = \Amp\call(function() use ($gen) { return yield from $gen; });
  } else if($gen instanceof \Closure || is_callable($gen)) {
    $call = \Amp\call(function() use ($gen) { return $gen(); });
  } else {
    throw new \Exception('Unsupported generator');
  }
  $call->onResolve(function($err, $res) use ($defer) {
    if($err) {
      return $defer->reject($err);
    }
    if($res instanceof \Generator || $res instanceof \Closure) {
      return $defer->resolve( resolve_generator($res) );
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
  } else if($promise instanceof \Generator || $promise instanceof \Closure) {
    return Block\await( resolve_generator( $promise ) , $loop, $timeout);
  }
  return $promise;
}



/**
 *
 * Retries a function for X times and eventually returns the exception.
 *
 */
function retry(LoopInterface $loop, $func, int &$retries=10, float $frequency=0.1, string $type=null) {
  if(is_null($type)) {
    $type = \Exception::class;
  }
  $resolver = function() use($loop, $frequency, &$retries, $func, $type) {
    $last = false;
    while($retries--) {
      try {
        $res = yield resolve_generator($func);
        return $res;
      } catch(\Exception $e) {
        $last = $e;
        $msg = $e->getMessage();
        if($msg != $type && !($e instanceof $type)) {
          throw $e;
        }
      }
      yield sleep($loop, $frequency);
    }
    throw $last;
  };
  return resolve_generator($resolver);
}


/**
 *
 * Runs blocking code asynchronously.
 * Returns a promise
 *
 */
function async(LoopInterface $loop, $func, array $args=[]) {
  $defer = new Deferred;

  $sockets = array();
  $domain = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN' ? AF_INET : AF_UNIX);
  if (socket_create_pair($domain, SOCK_STREAM, 0, $sockets) === false) {
    return new RejectedPromise( new \Exception('socket_create_pair failed: '.socket_strerror(socket_last_error()) ) );
  }

  $pid = pcntl_fork();
  if ($pid == -1) {
    return new RejectedPromise( new \Exception('Async fork failed') );
  } else if ($pid) {
    // Parent
    $loop->addPeriodicTimer(0.001, function($timer) use ($pid, $defer, &$sockets, $loop) {
      if(pcntl_waitpid(0, $status) != -1) {
        $loop->cancelTimer($timer);
        if(!pcntl_wifexited($status)) {
          $code = pcntl_wexitstatus($status);
          $defer->reject( new \Exception('child failed with status: '.$code) );
          socket_close($sockets[1]);
          socket_close($sockets[0]);
          return;
        }
        $buffer = '';
        while( ($data = socket_recv($sockets[1], $chunk, 8192, MSG_DONTWAIT)) !== false) {
          $buffer .= $chunk;
        }
        $data = unserialize($buffer);
        socket_close($sockets[1]);
        socket_close($sockets[0]);
        $defer->resolve($data);
      }
    });
    return $defer->promise();
  } else {
    // Child
    $res = call_user_func_array($func, $args);
    $res = serialize($res);
    if (socket_write($sockets[0], $res, strlen($res)) === false) {
      exit(1);
    }
    socket_close($sockets[0]);
    exit;
  }
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



