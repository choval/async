<?php

use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

use function Choval\Async\async;
use function Choval\Async\chain_resolve;
use function Choval\Async\execute;
use function Choval\Async\resolve;
use function Choval\Async\retry;
use function Choval\Async\sleep;
use function Choval\Async\sync;

class FunctionsTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
    }




    public function testSync()
    {
        $loop = static::$loop;
        $rand = rand();

        $defer = new Deferred();
        $defer->resolve($rand);
        $res = sync($loop, $defer->promise());
        $this->assertEquals($rand, $res);
    }


    /**
     * @depends testSync
     */
    public function testSyncTimeout()
    {
        $loop = static::$loop;

        $this->expectException(\React\Promise\Timer\TimeoutException::class);
        $res = sync($loop, sleep($loop, 1), 0.5);
    }


    /**
     * @depends testSync
     */
    public function testExecute()
    {
        $loop = static::$loop;
        $rand = rand();

        $res = sync($loop, execute($loop, 'echo ' . $rand));
        $this->assertEquals($rand, trim($res));
    }


    /**
     * @depends testExecute
     */
    public function testExecuteKill()
    {
        $loop = static::$loop;
        $this->expectException(\Exception::class);
        $res = sync($loop, execute($loop, 'sleep 1', 0.5));
    }




    /**
     * @depends testSync
     */
    public function testSleep()
    {
        $loop = static::$loop;
        $delay = 0.5;
 
        $start = microtime(true);
        sync($loop, sleep($loop, $delay));

        $diff = microtime(true) - $start;
        $this->assertGreaterThanOrEqual($delay, $diff);
    }



    /**
     *
     */
    public function testResolveGenerator()
    {
        $loop = static::$loop;

        $rand = rand();
        $func = function () use ($loop, $rand) {
            $var = yield execute($loop, 'echo ' . $rand);
            return 'yield+' . $var;
        };
        $res = sync($loop, resolve($func()));

        $this->assertEquals('yield+' . $rand, trim($res));

        $ab = function () use ($loop) {
            $out = [];
            $out[] = (int) trim(yield execute($loop, 'echo 1'));
            $out[] = (int) trim(yield execute($loop, 'echo 2'));
            $out[] = (int) trim(yield execute($loop, 'echo 3'));
            $out[] = (int) trim(yield execute($loop, 'echo 4'));
            $out[] = (int) trim(yield execute($loop, 'echo 5'));
            $out[] = (int) trim(yield execute($loop, 'echo 6'));
            return $out;
        };

        $res = sync($loop, resolve($ab));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);

        $res = sync($loop, resolve($ab()));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);

        $res = sync($loop, resolve(function () use ($ab) {
            return $ab;
        }));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);
    }



    public function testResolveWithException()
    {
        $loop = static::$loop;
        $rand = rand();
        $func = function () use ($loop, $rand) {
            $var = yield execute($loop, 'echo ' . $rand);
            $var = trim($var);
            throw new \Exception($var);
            return 'fail';
        };
        try {
            $msg = sync($loop, resolve($func));
        } catch (\Exception $e) {
            $msg = $e->getMessage();
        }
        $this->assertEquals($rand, $msg);

        $func2 = function () {
            throw new \Exception('Crap');
        };

        $func3 = function () use ($func2) {
            $var = yield $func2();
            return $var;
        };

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Crap');
        $msg = sync($loop, resolve($func3), 1);
    }



    /**
     * Executes one after the other.
     * @depends testSync
     * @depends testExecute
     * @depends testResolveGenerator
     */
    public function testChainResolve()
    {
        $loop = static::$loop;

        $calls = [
            function () use ($loop) {
                return execute($loop, 'sleep 0.1 && echo 1');
            },
            function () use ($loop) {
                return execute($loop, 'sleep 0.1 && echo 2');
            },
            function () use ($loop) {
                return execute($loop, 'sleep 0.1 && echo 3');
            },
            function () use ($loop) {
                return execute($loop, 'sleep 0.1 && echo 4');
            }
        ];
        $res = chain_resolve($calls);
        $responses = sync($loop, $res, 1);
        $this->assertEquals('1', trim($responses[0]));
        $this->assertEquals('2', trim($responses[1]));
        $this->assertEquals('3', trim($responses[2]));
        $this->assertEquals('4', trim($responses[3]));

        $res = call_user_func_array('Choval\\Async\\chain_resolve', $calls);
        $responses = sync($loop, $res, 1);
        $this->assertEquals('1', trim($responses[0]));
        $this->assertEquals('2', trim($responses[1]));
        $this->assertEquals('3', trim($responses[2]));
        $this->assertEquals('4', trim($responses[3]));
    }



    public function testAsyncBlockingCode()
    {
        $loop = static::$loop;
        $times = 10;
        $sleep_secs = 1;

        $func = function () use ($sleep_secs) {
            \sleep($sleep_secs);
            return microtime(true);
        };
        $start = microtime(true);
        $promises = [];
        for ($i = 0;$i < $times;$i++) {
            $promises[] = async($loop, $func);
        }
        $promise = Promise\all($promises);
        $mid = microtime(true);
        $rows = sync($loop, $promise, $sleep_secs * 2);
        $end = microtime(true);

        $this->assertLessThanOrEqual(1, $mid - $start);
        $this->assertLessThan($times, $end - $mid);
        foreach ($rows as $row) {
            $this->assertGreaterThanOrEqual($mid, $row);
            $this->assertLessThanOrEqual($end, $row);
        }
    }



    public function testAsyncEcho()
    {
        $loop = static::$loop;

        $func = function () {
            $msg = "Hello world";
            $this->expectOutputString($msg);
            echo $msg;
        };
        $test = sync($loop, async($loop, $func));
        $this->assertTrue(true);
    }



    public function testAsyncArguments()
    {
        $loop = static::$loop;

        $func = function ($a, $b, $c) {
            return $a + $b + $c;
        };
        $vals = [1, 2, 3];
        $res = sync($loop, async($loop, $func, $vals));
        $this->assertEquals(array_sum($vals), $res);
    }


    public function testRetry()
    {
        $loop = static::$loop;

        $times = 5;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            if (--$times) {
                throw new \Exception('bad error');
            }
            return $id;
        };
        $retries = 6;
        $res = sync($loop, retry($loop, $func, $retries));
        $this->assertEquals($id, $res);
        $this->assertEquals(1, $retries);
        $this->assertEquals(0, $times);
    }
}
