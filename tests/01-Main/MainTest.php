<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class MainTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }



    public function testIsDone()
    {
        $loop = Async\get_loop();
        $defer = new Deferred();
        $loop->addTimer(0.1, function () use ($defer) {
            $defer->resolve(true);
        });
        $promise = $defer->promise();
        $i = 0;
        while (! Async\is_done($promise)) {
            $i++;
        }
        echo "Looped $i until the promise solved in 0.1 sec\n";
        $this->assertGreaterThan(100, $i);
    }


    public function testSync()
    {
        $rand = rand();

        $defer = new Deferred();
        $defer->resolve($rand);
        $res = Async\sync($defer->promise());
        $this->assertEquals($rand, $res);

        $res = Async\wait($defer->promise());
        $this->assertEquals($rand, $res);

        $times = 0;
        Async\wait(function () use (&$times) {
            $times++;
            yield Async\sleep(0.1);
            return $times;
        }, 1);
        $this->assertEquals(1, $times);
    }



    public function testResolveGenerator()
    {
        $rand = rand();
        $func = function () use ($rand) {
            $var = yield Async\execute('echo ' . $rand);
            return 'yield+' . $var;
        };
        $res = Async\wait(Async\resolve($func()));
        $this->assertEquals('yield+' . $rand, trim($res));

        $ab = function () {
            $out = [];
            $out[] = (int) trim(yield Async\execute('echo 1'));
            $out[] = (int) trim(yield Async\execute('echo 2'));
            $out[] = (int) trim(yield Async\execute('echo 3'));
            $out[] = (int) trim(yield Async\execute('echo 4'));
            $out[] = (int) trim(yield Async\execute('echo 5'));
            $out[] = (int) trim(yield Async\execute('echo 6'));
            return $out;
        };

        $res = Async\wait(Async\resolve($ab));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);

        $res = Async\wait(Async\resolve($ab()));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);

        $res = Async\wait(Async\resolve(function () use ($ab) {
            return yield $ab();
        }));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);
    }



    public function testResolveCancel()
    {
        $i = 0;
        $func = function () use (&$i) {
            while ($i < 3) {
                yield Async\sleep(0.5);
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
        $res = Async\wait($prom);
        $this->assertLessThan(3, $res);
        $this->assertLessThan(3, $i);
    }



    public function testResolveNoCancelBeforeTimeout()
    {
        $res = Async\wait(function () {
            return Async\resolve(function () {
                yield Async\sleep(0.1);
                return true;
            });
        }, 0.2);
        $this->assertTrue($res);
    }



    public function testResolveWithException()
    {
        $rand = rand();
        $func = function () use ($rand) {
            $var = yield Async\execute('echo ' . $rand);
            $var = trim($var);
            throw new \Exception($var);
            return 'fail';
        };
        try {
            $msg = Async\wait(Async\resolve($func), 1);
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
        $msg = Async\wait(Async\resolve($func3), 1);
    }



    public function testResolveWithNonExistingFunction()
    {
        Async\wait(Async\resolve(function () {
            yield Async\resolve(function () {
                $this->expectException(\Throwable::class);
                calling_non_existing_function();
            });
        }));
    }



    public function testResolveWithNonExistingClassMethod()
    {
        Async\wait(Async\resolve(function () {
            yield Async\resolve(function () {
                $this->expectException(\Throwable::class);
                TestResolveClass::non_existing_method();
            });
        }));
    }



    public function testExceptionInsideResolve()
    {
        $res = Async\wait(Async\resolve(function () {
            $res = false;
            try {
                yield Async\execute('sleep 1', 0.01);
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
        $res = Async\wait(Async\resolve(function () {
            yield;
            throw new AsyncException('Oops');
            $this->assertTrue(false);
        }), 0.5);
    }



    public function testExceptionThrowInsideMultipleResolve()
    {
        $this->expectException(AsyncException::class);
        $res = Async\wait(Async\resolve(function () {
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
        Async\wait(function () use ($func7) {
            $a = yield $func7;
            $this->assertEquals(7, $a);
        });
    }



    public function testTimerInsideResolveMess()
    {
        $func = function ($defer) {
            yield false;
            $i=0;
            $loop = Async\get_loop();
            $loop->addPeriodicTimer(0.001, function ($timer) use (&$i, $defer) {
                $i++;
                if ($i >= 1000) {
                    $defer->resolve($i);
                    Async\get_loop()->cancelTimer($timer);
                }
            });
        };
        return Async\wait(function () use ($func) {
            yield true;
            $defer = new Deferred();
            yield $func($defer);
            $val = yield $defer->promise();
            $this->assertEquals(1000, $val);
        });
    }



    public function testSleep()
    {
        $delay = 0.5;

        $start = microtime(true);
        Async\wait(Async\sleep($delay));
        $diff = microtime(true) - $start;
        $this->assertGreaterThanOrEqual($delay, $diff);
    }



    public function testAsyncResolveMemoryUsage()
    {
        $times = 100;
        $mem_records = [];
        while ($times--) {
            Async\wait(function () use (&$mem_records) {
                $limit = 10000;
                $i = 0;
                while ($limit--) {
                    yield Async\sleep(0.0000001);
                    $i++;
                }
                $mem = memory_get_usage(true);
                $mem_records[] = $mem;
            });
        }
        $max = max($mem_records);
        $values = array_count_values($mem_records);
        arsort($values);
        $freq = key($values);
        $this->assertLessThanOrEqual($freq, $max);
    }



    public function testTimeout()
    {
        $defer = new Deferred();
        $promise = $defer->promise();
        $this->expectException(AsyncException::class);
        $this->expectExceptionMessage('Timed out after 0.01 secs');
        $res = Async\wait(Async\timeout($promise, 0.01), 0.1);
    }



    public function testAsyncResolveSilent()
    {
        Async\wait(function () {
            $fn = function () {
                throw new \Exception('Hey!');
            };
            $res = yield Async\silent($fn, $e);
            $this->assertEmpty($res);
            $this->assertInstanceOf(Exception::class, $e);

            $res = yield Async\silent(Async\timeout(Async\execute('sleep 1'), 0.01), $e);
            $this->assertEmpty($res);
            $this->assertInstanceOf(Exception::class, $e);
        });
    }


    public function testAsyncSilentCancel()
    {
        Async\wait(function () {
            $a = false;
            $fn = function () use (&$a) {
                yield Async\sleep(1);
                $a = true;
                return $a;
            };
            $res = yield Async\silent($fn);
            $this->assertTrue($a);
            $a = false;
            $res = yield Async\silent(Async\timeout(Async\silent($fn), 0.01), $e);
            $this->assertInstanceOf(Exception::class, $e);
            $this->assertFalse($a);
        });
    }


    public function testAsyncTimer()
    {
        Async\wait(function () {
            $delay = 0.01;
            yield Async\timer(Async\sleep($delay), $time);
            $pct = round($time / $delay * 100);
            $abs = abs(100 - $pct);
            // Allows 5% diff on the delay
            $this->assertLessThan(5, $abs);
        });
    }


    public function testIsValidRegexp()
    {
        Async\wait(function () {
            $valid = yield Async\is_valid_regexp('asd');
            $this->assertFalse($valid);
            $valid = yield Async\is_valid_regexp('/a/');
            $this->assertTrue($valid);
            $valid = yield Async\is_valid_regexp('/"/');
            $this->assertTrue($valid);
        });
    }


    public static function tearDownAfterClass(): void
    {
        static::$loop->stop();
    }
}
