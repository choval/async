<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\FulfilledPromise;
use React\Promise\Deferred;

class RetryTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }


    public function testRetry()
    {
        $times = 5;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            if (--$times) {
                throw new \Exception('bad error');
            }
            return $id;
        };
        $retries = $times+1;
        $res = Async\wait(Async\retry($func, $retries, 0.1, 'bad error'), 2);
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);

        $times = 5;
        $retries = 6;
        $func = function () use (&$times, $id) {
            if (--$times) {
                throw new \Exception('bad error');
            }
            throw new \Exception('final error');
        };
        $this->expectExceptionMessage('final error');
        $res = Async\wait(Async\retry($func, $retries, 0.1, 'bad error'));
    }



    public function testRetryImmediateResponse()
    {
        $start = microtime(true);
        $res = Async\wait(Async\retry(function () {
            return true;
        }, 1, 2));
        $end = microtime(true);
        $diff = $end-$start;
        $this->assertLessThan(0.1, $diff);
    }



    public function testRetryStress()
    {
        $times = 1000;
        $id = uniqid();
        $max_mem = memory_get_usage() * 2;
        $func = function () use (&$times, $id, $max_mem) {
            $mem = memory_get_usage();
            $this->assertLessThan($max_mem, $mem);
            if (--$times) {
                throw new \Exception('bad error');
            }
            return $id;
        };
        $retries = $times + 1;
        $res = Async\wait(Async\retry($func, $retries, 0, 'bad error'), 1);
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);
    }
}
