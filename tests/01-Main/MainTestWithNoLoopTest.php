<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class MainTestWithNoLoopTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
    }


    public function testResolveGenerator()
    {
        $rand = rand();
        $func = function () use ($rand) {
            $var = yield Async\execute(static::$loop, 'echo ' . $rand);
            return 'yield+' . $var;
        };
        $res = Async\wait(static::$loop, Async\resolve($func()));
        $this->assertEquals('yield+' . $rand, trim($res));

        $ab = function () {
            $out = [];
            $out[] = (int) trim(yield Async\execute(static::$loop, 'echo 1'));
            $out[] = (int) trim(yield Async\execute(static::$loop, 'echo 2'));
            $out[] = (int) trim(yield Async\execute(static::$loop, 'echo 3'));
            $out[] = (int) trim(yield Async\execute(static::$loop, 'echo 4'));
            $out[] = (int) trim(yield Async\execute(static::$loop, 'echo 5'));
            $out[] = (int) trim(yield Async\execute(static::$loop, 'echo 6'));
            return $out;
        };

        $res = Async\wait(static::$loop, Async\resolve($ab));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);

        $res = Async\wait(static::$loop, Async\resolve($ab()));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);

        $res = Async\wait(static::$loop, Async\resolve(function () use ($ab) {
            return yield $ab();
        }));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);
    }



    public function testResolveCancel()
    {
        $i = 0;
        $func = function () use (&$i) {
            while ($i < 3) {
                yield Async\sleep(static::$loop, 0.5);
                $i++;
                echo "testResolveCancel $i\n";
            }
            // throw new \Exception('This should never be reached');
            return $i;
        };
        $prom = Async\resolve($func);
        static::$loop->addTimer(0.5, function () use ($prom) {
            echo "Cancel sent\n";
            $prom->cancel();
        });
        $this->expectException(CancelException::class);
        $res = Async\wait(static::$loop, $prom);
        $this->assertLessThan(3, $res);
        $this->assertLessThan(3, $i);
    }



    public function testResolveNoCancelBeforeTimeout()
    {
        function a()
        {
            return Async\resolve(function () {
                yield Async\sleep(MainTestWithNoLoopTest::$loop, 1);
                return true;
            });
        }
        $res = Async\wait(static::$loop, a(), 2);
        $this->assertTrue($res);
    }



    public function testResolveWithException()
    {
        $rand = rand();
        $func = function () use ($rand) {
            $var = yield Async\execute(static::$loop, 'echo ' . $rand);
            $var = trim($var);
            throw new \Exception($var);
            return 'fail';
        };
        try {
            $msg = Async\wait(static::$loop, Async\resolve($func), 1);
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
        $msg = Async\wait(static::$loop, Async\resolve($func3), 1);
    }



    public function testResolveWithNonExistingFunction()
    {
        Async\wait(static::$loop, Async\resolve(function () {
            yield Async\resolve(function () {
                $this->expectException(\Throwable::class);
                calling_non_existing_function();
            });
        }));
    }



    public function testResolveWithNonExistingClassMethod()
    {
        Async\wait(static::$loop, Async\resolve(function () {
            yield Async\resolve(function () {
                $this->expectException(\Throwable::class);
                TestResolveClass::non_existing_method();
            });
        }));
    }



    public function testExceptionInsideResolve()
    {
        $res = Async\wait(static::$loop, Async\resolve(function () {
            $res = false;
            try {
                yield Async\execute(static::$loop, 'sleep 2', 1);
                $res = false;
            } catch (\Exception $e) {
                $res = true;
                echo "MESSAGE CAUGHT\n";
                echo $e->getMessage() . "\n";
            }
            $this->assertTrue($res);
            return $res;
        }));
        $this->assertTrue($res);
    }



    public function testExceptionThrowInsideResolve()
    {
        $this->expectException(AsyncException::class);
        $res = Async\wait(static::$loop, Async\resolve(function () {
            yield;
            throw new AsyncException('Oops');
            $this->assertTrue(false);
        }), 0.5);
    }



    public function testExceptionThrowInsideMultipleResolve()
    {
        $this->expectException(AsyncException::class);
        $res = Async\wait(static::$loop, Async\resolve(function () {
            yield Async\resolve(function () {
                yield;
                throw new AsyncException('OopsMultiple');
                return 'FAIL';
            });
        }), 1);
    }



    public function testResolveDepth()
    {
        $func1 = function () {
            return yield 1;
        };
        $func2 = function () use ($func1) {
            $res = yield $func1;
            $res++;
            return $res;
        };
        $func3 = function () use ($func2) {
            $res = yield $func2;
            $res++;
            return $res;
        };
        $func4 = function () use ($func3) {
            $res = yield $func3;
            $res++;
            return $res;
        };
        $func5 = function () use ($func4) {
            $res = yield $func4;
            $res++;
            return $res;
        };
        $func6 = function () use ($func5) {
            $res = yield $func5;
            $res++;
            return $res;
        };
        $func7 = function () use ($func6) {
            $res = yield $func6;
            $res++;
            return $res;
        };
        Async\wait(static::$loop, function () use ($func7) {
            $a = yield $func7;
            $this->assertEquals(7, $a);
        });
    }



    public function testTimerInsideResolveMess()
    {
        $func = function ($defer) {
            yield false;
            $i=0;
            $loop = static::$loop;
            $loop->addPeriodicTimer(0.001, function ($timer) use (&$i, $defer) {
                $i++;
                if ($i >= 1000) {
                    $defer->resolve($i);
                    static::$loop->cancelTimer($timer);
                }
            });
        };
        return Async\wait(static::$loop, function () use ($func) {
            yield true;
            $defer = new Deferred();
            yield $func($defer);
            $val = yield $defer->promise();
            $this->assertEquals(1000, $val);
        });
    }



    public function testAsyncResolveMemoryUsage()
    {
        $times = 1;
        $memories = [];
        while ($times--) {
            Async\wait(static::$loop, function () use (&$memories) {
                $limit = 100000;
                $i = 0;
                $prev = memory_get_usage();
                while ($limit--) {
                    yield Async\sleep(static::$loop, 0.0000001);
                    $i++;
                }
                $mem = memory_get_usage();
                $diff = $mem - $prev;
                $this->assertLessThanOrEqual(16384*$i, $diff);
            });
        }
    }


    public function testTimeout()
    {
        $defer = new Deferred();
        $promise = $defer->promise();
        $this->expectException(AsyncException::class);
        $this->expectExceptionMessage('Timed out after 0.5 secs');
        $res = Async\wait(static::$loop, Async\timeout(static::$loop, $promise, 0.5), 1);
    }


    public static function tearDownAfterClass(): void
    {
        static::$loop->stop();
    }
}
