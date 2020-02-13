<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
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


    public function testRetryStress()
    {
        $times = 1000;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            if (--$times) {
                throw new \Exception('bad error');
            }
            return $id;
        };
        $retries = $times + 1;
        $res = Async\wait(Async\retry($func, $retries, 0.001, 'bad error'), 1.1);
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);
    }


    public function testRetryStressWithAsync()
    {
        $times = 10;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            --$times;
            return Async\async(function () use ($id, $times) {
                if ($times) {
                    throw new \Exception('bad error async');
                }
                return $id;
            });
        };
        $retries = $times + 1;
        $res = Async\wait(Async\retry($func, $retries, 0.1, 'bad error async'), 10);
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);
    }
}
