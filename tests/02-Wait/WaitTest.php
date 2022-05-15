<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class WaitTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }

    public function testSyncNotBlockOthersInTheLoop()
    {
        $loop = Async\get_loop();
        $i = 0;
        $defer = new Deferred();
        $promise = $defer->promise();
        $loop->addTimer(0.5, function () use ($defer) {
            $defer->resolve(true);
        });
        $loop->addPeriodicTimer(0.1, function () use (&$i) {
            $i++;
        });
        $this->assertLessThanOrEqual(1, $i);
        Async\wait($promise, 1);
        $this->assertGreaterThanOrEqual(1, $i);
    }



    public function testSyncTimeout()
    {
        $this->expectException(AsyncException::class);
        $res = Async\wait(Async\sleep(0.1), 0.05);
    }



    public function testWaitWithExecuteInsideResolve()
    {
        $ab = Async\resolve(function () {
            yield Async\sleep(0.1);
            $res = yield Async\execute('echo ok');
            return $res;
        });
        $out = Async\wait($ab, 0.2);
        $this->assertEquals('ok', trim($out));
    }



    public function testNestingNightmare()
    {
        $id = uniqid();
        $func_a = function () use ($id) {
            return Async\async(function () use ($id) {
                usleep(1);
                return $id;
            });
        };
        $func_b = function () use ($func_a) {
            return Async\wait($func_a());
        };
        $res = Async\wait(Async\resolve($func_b));
        $this->assertEquals($id, $res);
    }



    public function testBlockInsidePromise()
    {
        $id = uniqid();
        $func_a = function () use ($id) {
            yield Async\async(function () {
                sleep(1);
            });
            return $id;
        };
        $func_b = function () use ($func_a) {
            return Async\wait(Async\resolve($func_a));
        };
        $func_c = function () use ($func_b) {
            return Async\wait($func_b());
        };
        $res = Async\wait($func_c());
        $this->assertEquals($id, $res);
    }
}
