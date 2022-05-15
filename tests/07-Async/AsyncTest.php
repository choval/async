<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class AsyncTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }



    public function testAsyncBlockingCode()
    {
        $times = 10;
        $sleep_secs = 1;

        $func = function () use ($sleep_secs) {
            \sleep($sleep_secs);
            return microtime(true);
        };
        $start = microtime(true);
        $promises = [];
        for ($i = 0;$i < $times;$i++) {
            $promises[] = Async\async($func);
        }
        $promise = Promise\all($promises);
        $mid = microtime(true);
        $rows = Async\wait($promise, $sleep_secs * 2);
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
        $msg = "Hello world";
        $func = function ($msg) {
            return $msg;
        };
        $test = Async\wait(Async\async($func, [$msg]));
        $this->assertEquals($msg, $test);
    }



    public function testAsyncStress()
    {
        $factor = 3;
        $limit = Async\get_forks_limit();
        $limit *= $factor;
        $func = function ($i) {
            \usleep(1);
            return $i;
        };
        $start = microtime(true);
        $promises = [];
        for ($i = 0;$i < $limit;$i++) {
            $promises[] = Async\async($func, [$i]);
        }
        $res = Async\wait(Promise\all($promises));
        foreach ($res as $k=>$v) {
            $this->assertStringContainsString($k, $v);
        }
        $end = microtime(true);
        $diff = $end - $start;
        $this->assertLessThanOrEqual(($limit / $factor), $diff);
        $this->assertEquals($limit, count($res));
    }


    public function testAsyncMemoryStress()
    {
        $factor = 3;
        $limit = Async\get_forks_limit();
        $limit *= $factor;
        // Tests memory
        $func = function ($i) {
            return $i.str_repeat(' ', 1024*1024);
        };
        $startmem = memory_get_usage(true);
        $promises = [];
        for ($i = 0;$i < $limit;$i++) {
            $res = Async\wait(Async\async($func, [$i]));
            $this->assertStringContainsString($i, $res);
        }
        $endmem = memory_get_usage(true);
        $diffmem = $endmem - $startmem;
        $this->assertLessThanOrEqual(($limit/$factor)*1024*1024, $diffmem);
    }



    public function testAsyncArguments()
    {
        $func = function ($a, $b, $c) {
            return $a + $b + $c;
        };
        $vals = [1, 2, 3];
        $res = Async\wait(Async\async($func, $vals));
        $this->assertEquals(array_sum($vals), $res);
    }



    public function testAsyncWaitWithBackgroundProcesses()
    {
        $loop = Async\get_loop();
        $i = 0;
        $defer = new Deferred();
        $promise = $defer->promise();
        $loop->addTimer(1, function () use ($defer) {
            $defer->resolve(true);
        });
        $loop->futureTick(function () use (&$i) {
            $res = Async\wait(
                Async\async(function () use ($i) {
                    for ($e = 0;$e < 8;$e++) {
                        \usleep(1);
                        $i++;
                    }
                    return $i;
                })
            );
            $i = $res;
        });
        $this->assertLessThanOrEqual(1, $i);
        Async\wait($promise, 1.2);
        $this->assertGreaterThanOrEqual(0.8, $i);
    }



    public function testExceptionInAsync()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('test');
        $promise = Async\async(function () {
            throw new \Exception('test');
            return true;
        });
        Async\wait($promise, 0.1);
    }



    public function testRetryStressWithAsync()
    {
        $times = 100;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            $times--;
            return Async\async(function () use ($id, $times) {
                if ($times) {
                    throw new \Exception('bad error async');
                }
                return $id;
            });
        };
        $retries = $times+1;
        $res = Async\wait(Async\retry(
            fn: $func,
            retries: $retries,
            frequency: 0.01,
            ignores: 'bad error async'
        ), $retries*0.1);
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);
    }
}
