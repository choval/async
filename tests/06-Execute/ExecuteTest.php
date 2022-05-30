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
        $limit = 100;
        for ($i=0;$i<$limit;$i++) {
            $rand = rand();
            $out = Async\wait(Async\execute('echo '. $rand), 1);
            $this->assertEquals($rand, trim($out));
        }
    }


    public function testExecuteKill()
    {
        $this->expectException(\Exception::class);
        $res = Async\wait(Async\execute('sleep 1 && echo out', 0.01));
    }


    public function testNoZombieGeneratedWhenPromiseCanceled()
    {
        Async\wait(function () {
            $e = '';
            $res = yield Async\silent(Async\timeout(Async\execute('sleep 2'), 0.01), $e);
            $this->assertInstanceOf(AsyncException::class, $e);
            $zombies = zombie_find();
            $this->assertEmpty($zombies);
        });
    }


    public function testNoZombieOnProcessExit()
    {
        Async\wait(function () {
            yield Async\silent(Async\execute('ls "this should return exit1"'), $e);
            $this->assertInstanceOf(AsyncException::class, $e);
            $zombies = zombie_find();
            $this->assertEmpty($zombies);

            // Now, SPAM IT!
            $promises = [];
            $eses = [];
            for ($i=0;$i<100;$i++) {
                $promises[] = Async\silent(Async\execute('ls "this should return exit1"'), $eses[$i]);
            }
            yield Promise\all($promises);
            $zombies = zombie_find();
            $this->assertEmpty($zombies);
            foreach ($eses as $e) {
                $this->assertInstanceOf(AsyncException::class, $e);
            }
        });
    }


    public function testExecuteTimerZombies()
    {
        Async\wait(function () {
            $promises = [];
            $eses = [];
            for ($i=0;$i<100;$i++) {
                $eses[$i] = false;
                $promises[] = Async\silent(Async\execute('sleep 1 && echo '.$i, 0.01), $eses[$i]);
            }
            yield Promise\all($promises);
            $zombies = zombie_find();
            echo "Found ".count($zombies)." zombies\n";
            // Gives the system a sec to reap
            yield Async\sleep(1);
            $zombies = zombie_find();
            $this->assertEmpty($zombies);
            foreach ($eses as $i=>$e) {
                $this->assertInstanceOf(AsyncException::class, $e);
            }
        });
    }


    public function testChildProcessZombies()
    {
        Async\wait(function () {
            $sleepbin = __DIR__.'/../scripts/phpsleep';
            $sleepbin = realpath($sleepbin);
            $cmd = 'echo chain && '.$sleepbin.' 2';
            yield Async\silent(Async\execute($cmd, 0.01));
            $zombies = zombie_find();
            $this->assertEmpty($zombies);
        });
    }


    public function testExecuteOutputfn()
    {
        Async\wait(function () {
            $g = false;
            yield Async\silent(Async\execute('echo hello', 1, function ($d) use (&$g) {
                $g = trim($d);
            }));
            $this->assertEquals('hello', $g);
        });
    }
}
