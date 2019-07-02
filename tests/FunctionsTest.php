<?php

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise\Deferred;
use React\Promise;

use function Choval\Async\execute;
use function Choval\Async\resolve_generator;
use function Choval\Async\sleep;
use function Choval\Async\sync;
use function Choval\Async\async;
use function Choval\Async\chain_resolve;
use function Choval\Async\retry;

class FunctionsTest extends TestCase {
  
  static $loop;

  public static function setUpBeforeClass() {
    static::$loop = Factory::create();
  }




  public function testSync() {
    $loop = static::$loop;
    $rand = rand();

    $defer = new Deferred;
    $defer->resolve( $rand );
    $res = sync( $loop, $defer->promise() );
    $this->assertEquals( $rand, $res );
  }




  /**
   * @depends testSync
   */
  public function testExecute() {
    $loop = static::$loop;
    $rand = rand();

    $res = sync( $loop, execute( $loop, 'echo '.$rand) );
    $this->assertEquals( $rand, trim($res) );
  }




  /**
   * Executes one after the other.
   * @depends testSync
   * @depends testExecute
   */
  public function testChainResolve() {
    $loop = static::$loop;

    $responses = sync( $loop, chain_resolve([
      function() use ($loop) { return execute($loop, 'sleep 0.1 && echo 1'); },
      function() use ($loop) { return execute($loop, 'sleep 0.1 && echo 2'); },
      function() use ($loop) { return execute($loop, 'sleep 0.1 && echo 3'); },
      function() use ($loop) { return execute($loop, 'sleep 0.1 && echo 4'); },
      function() use ($loop) { return execute($loop, 'thismustfail_zzz'); },
    ]), 5);

    $this->assertEquals( '1', trim($responses[0]) );
    $this->assertEquals( '2', trim($responses[1]) );
    $this->assertEquals( '3', trim($responses[2]) );
    $this->assertEquals( '4', trim($responses[3]) );
    $this->assertInstanceOf( \Exception::class, $responses[4] );

  }



  /**
   * @depends testSync
   */
  public function testSleep() {
    $loop = static::$loop;
    $delay = 0.5;
 
    $start = microtime(true);
    sync( $loop, sleep( $loop, $delay ) );

    $diff = microtime(true) - $start;
    $this->assertGreaterThanOrEqual( $delay, $diff );
  }



  /**
   * 
   */
  public function testResolveGenerator() {
    $loop = static::$loop;

    $rand = rand();
    $func = function() use ($loop, $rand) {
      $var = yield execute($loop, 'echo '.$rand);
      return 'yield+'.$var;
    };
    $res = sync( $loop, resolve_generator($func()) );

    $this->assertEquals('yield+'.$rand, trim($res) );

    $ab = function() use ($loop) {
      $out = [];
      $out[] = (int) trim( yield execute($loop, 'echo 1') );
      $out[] = (int) trim( yield execute($loop, 'echo 2') );
      $out[] = (int) trim( yield execute($loop, 'echo 3') );
      $out[] = (int) trim( yield execute($loop, 'echo 4') );
      $out[] = (int) trim( yield execute($loop, 'echo 5') );
      $out[] = (int) trim( yield execute($loop, 'echo 6') );
      return $out;
    };

    $res = sync($loop, resolve_generator( $ab ) );
    $this->assertEquals([1,2,3,4,5,6], $res);

    $res = sync($loop, resolve_generator( $ab() ) );
    $this->assertEquals([1,2,3,4,5,6], $res);

    $res = sync($loop, resolve_generator( function() use ($ab) { return $ab; } ) );
    $this->assertEquals([1,2,3,4,5,6], $res);
  }



  public function testAsyncBlockingCode() {
    $loop = static::$loop;

    $func = function() {
      \sleep(1);
      return microtime(true);
    };
    $start = microtime(true);
    $promises = [];
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promises[] = async($loop, $func);
    $promise = Promise\all($promises);
    $mid = microtime(true);
    $rows = sync($loop, $promise);
    $end = microtime(true);

    $this->assertLessThanOrEqual( 1, $mid-$start);
    $this->assertLessThanOrEqual( 2, $end-$mid);
    foreach($rows as $row) {
      $this->assertGreaterThanOrEqual($mid, $row);
      $this->assertLessThanOrEqual($end, $row);
    }
  }


  public function testAsyncArguments() {
    $loop = static::$loop;

    $func = function($a, $b, $c) {
      return $a + $b + $c;
    };
    $vals = [1,2,3];
    $res = sync( $loop, async( $loop, $func, $vals ) );
    $this->assertEquals( array_sum($vals), $res );
  }


  public function testRetry() {
    $loop = static::$loop;

    $times = 5;
    $id = uniqid();
    $func = function() use (&$times, $id) {
      if(--$times) {
        throw new \Exception('bad error');
      }
      return $id;
    };
    $retries = 6;
    $res = sync( $loop, retry( $loop, $func, $retries ) );
    $this->assertEquals($id, $res);
    $this->assertEquals( 1, $retries);
    $this->assertEquals( 0, $times);
  }


}


