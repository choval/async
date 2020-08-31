<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class ExecuteTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }


    public function testExecute()
    {
        $rand = rand();

        $res = Async\wait(Async\execute('echo ' . $rand, 1));
        $this->assertEquals($rand, trim($res));

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(127);
        $res = Async\wait(Async\execute('non-existing-command', 1));
    }


    public function testStress()
    {
        $limit = 1000;
        for ($i=0;$i<$limit;$i++) {
            $rand = rand();
            $out = Async\wait(Async\execute('echo '. $rand), 1);
            $this->assertEquals($rand, trim($out));
        }
    }


    public function testExecuteKill()
    {
        $this->expectException(\Exception::class);
        $res = Async\wait(Async\execute('sleep 1', 0.5));
    }


    public function testZombieGeneratedWhenPromiseCanceled()
    {
        Async\wait(function () {
            $e = '';
            $res = yield Async\silent(Async\timeout(Async\execute('sleep 1'), 0.1), $e);
            $zombies = yield Async\silent(Async\execute('ps aux|grep " Z "|grep -v "grep"'));
            $this->assertEmpty($zombies);
        });
    }
}
