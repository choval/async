<?php
namespace Choval\Async;

use Clue\React\Block;
use React\Promise\Deferred;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\EventLoop\Factory;
use React\Promise\PromiseInterface;
use React\Promise\RejectedPromise;
use React\Promise\Stream;
use React\Promise;

use Generator;
use Closure;
use Evenement\EventEmitterInterface;

class Async {



  protected static $loop;



  /**
   * Sets the loop
   */
  public static function setLoop(LoopInterface $loop) {
    static::$loop = $loop;
  }



  /**
   * Gets the loop
   */
  public static function getLoop() {
    if(empty(static::$loop)) {
      static::$loop = Factory::create();
    }
    return static::$loop;
  }



  /**
   * Runs the internal loop if any
   */
  public function runInternalLoop() {
    if($this->internal_loop) {
      $this->internal_loop->run();
    }
    return $this;
  }



  /**
   * Auto set a variable
   */
  protected function autoSet($var) {
    if(is_object($var)) {
      if(is_a($var, LoopInterface::class)) {
        $this->loop = $var;
        return $this;
      }
      if(is_a($var, PromiseInterface::class)) {
        $this->func = $var;
        $this->func_type = PromiseInterface::class;
        return $this;
      }
      if(is_a($var, EventEmitterInterface::class)) {
        $this->func = $var;
        $this->func_type = EventEmitterInterface::class;
        return $this;
      }
      if(is_array($var)) {
        $this->func = $var;
        $this->func_type = 'array';
        return $this;
      }
      if(is_a($var, Generator::class)) {
        $this->func = $var;
        $this->func_type = Generator::class;
        return $this;
      }
      if(is_a($var, Closure::class)) {
        $this->func = $var;
        $this->func_type = Closure::class;
        return $this;
      }
    }
    if(is_int($var) || is_float($var)) {
      $this->timeout = $var;
    }
    if(is_string($var)) {
      $this->vars['string'] = $var;
    }
    // TODO: Error
    return $this;
  }



  /**
   * Gets the timeout
   */
  public function getTimeout() {
    if(is_null($this->timeout)) {
      $timeout = ini_get('max_execution_time');
      if($timeout <= 0) {
        return NULL;
      }
    }
    if($this->timeout < 0) {
      return NULL;
    }
    return $this->timeout;
  }



  /**
   * Sync
   */
  static function sync(LoopInterface $loop, $promise, float $timeout = NULL ) {
    if(is_null($timeout)) {
      $timeout = ini_get('max_execution_time');
    }
    if($timeout <= 0) {
      $timeout = NULL;
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
   * Sleep
   */
  static function sleep(LoopInterface $loop, float $time) {
    $defer = new Deferred;
    $loop->addTimer($time, function() use ($defer) {
      $defer->resolve(true);
    });
    return $defer->promise();
  }


  /**
   * Execute
   */
  static function execute(LoopInterface $loop, string $cmd, float $timeout=-1, &$exitCode=0, &$termSignal=0) {
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
    $err;
    if($timeout > 0) {
      $timer = $loop->addTimer($timeout, function() use ($proc, &$err) {
        $proc->stdin->end();
        foreach ($proc->pipes as $pipe) {
          $pipe->close();
        }
        $proc->terminate( \SIGKILL ?? 9 );
        $err = new \Exception('Process killed because of timeout');
      });
    }
    $proc->start( $loop );
    $proc->stdout->on('data', function($chunk) use (&$buffer) {
      $buffer .= $chunk;
    });
    $proc->stdout->on('error', function(\Exception $e) use (&$err) {
      $err = $e;
    });
    $proc->on('exit', function($exitCode, $termSignal) use ($defer, &$buffer, $cmd, $timer, $loop, &$err) {
      if($timer) {
        $loop->cancelTimer($timer);
      }
      // Clears any hanging processes
      $loop->addTimer(1, function() {
        pcntl_waitpid(-1, $status, WNOHANG);
      });
      if($err) {
        return $defer->reject($err);
      }
      if($exitCode) {
        return $defer->reject(new \Exception('Process finished with code: '.$exitCode));
      }
      $defer->resolve($buffer);
    });
    return $defer->promise();
  }


  /**
   * Resolve generator
   */
  static function resolve_generator($gen) {
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
   * Retry
   */
  static function retry(LoopInterface $loop, $func, int &$retries=10, float $frequency=0.1, string $type=null) {
    if(is_null($type)) {
      $type = \Exception::class;
    }
    $resolver = function() use($loop, $frequency, &$retries, $func, $type) {
      $last = new \Exception('Failed retries');
      while($retries--) {
        try {
          $res = yield resolve_generator($func);
          return $res;
        } catch(\Exception $e) {
          yield sleep($loop, $frequency);
          $last = $e;
          $msg = $e->getMessage();
          if($msg != $type && !($e instanceof $type)) {
            throw $e;
          }
        }
      }
      throw $last;
    };
    return resolve_generator($resolver);
  }


  /**
   * Async
   */
  static function async(LoopInterface $loop, $func, array $args=[]) {
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
   * Chain Resolve
   */
  static function chain_resolve() {
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



}


