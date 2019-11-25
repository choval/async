<?php

use Choval\Async;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;

use React\Promise\Deferred;

class TestResolveClass {
}

class FunctionsTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
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
    }


    public function testSyncNotBlockOthersInTheLoop()
    {
        $loop = Async\get_loop();
        $i = 0;
        $defer = new Deferred();
        $promise = $defer->promise();
        $loop->addTimer(0.5, function() use ($defer) {
            $defer->resolve(true);
        });
        $loop->addPeriodicTimer(0.1, function() use (&$i) {
            $i++;
        });
        $this->assertLessThanOrEqual(1, $i);
        Async\wait( $promise , 1.2);
        $this->assertGreaterThanOrEqual(1, $i);
    }


    /**
     * @depends testSync
     */
    public function testSyncTimeout()
    {
        $this->expectException(\React\Promise\Timer\TimeoutException::class);
        $res = Async\wait(Async\sleep(1), 0.5);
    }


    /**
     * @depends testSync
     */
    public function testExecute()
    {
        $rand = rand();

        $res = Async\wait(Async\execute('echo ' . $rand, 1));
        $this->assertEquals($rand, trim($res));

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(127);
        $res = Async\wait(Async\execute('non-existing-command', 1));
    }


    /**
     * @depends testExecute
     */
    public function testExecuteKill()
    {
        $this->expectException(\Exception::class);
        $res = Async\wait(Async\execute('sleep 1', 0.5));
    }




    /**
     * @depends testSync
     */
    public function testSleep()
    {
        $delay = 0.5;
 
        $start = microtime(true);
        Async\wait(Async\sleep($delay));
        $diff = microtime(true) - $start;
        $this->assertGreaterThanOrEqual($delay, $diff);
    }



    /**
     * Tests the resolve generator
     */
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
            return $ab;
        }));
        $this->assertEquals([1, 2, 3, 4, 5, 6], $res);
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
        Async\wait(Async\resolve(function() {
            yield Async\resolve(function() {
                $this->expectException(\Throwable::class);
                calling_non_existing_function();
            });
        }));
    }



    public function testResolveWithNonExistingClassMethod()
    {
        Async\wait(Async\resolve(function() {
            yield Async\resolve(function() {
                $this->expectException(\Throwable::class);
                TestResolveClass::non_existing_method();
            });
        }));
    }



    public function testExceptionInsideResolve()
    {
        $res = Async\wait( Async\resolve(function() {
            $res = true;
            try {
                yield Async\execute('sleep 2', 1);
                $res = false;
            } catch(\Exception $e) {
                echo "MESSAGE CAUGHT\n";
                echo $e->getMessage()."\n";
            }
            $this->assertTrue($res);
            return $res;
        }));
        $this->assertTrue($res);
    }



    public function testExceptionThrowInsideResolve()
    {
        $this->expectException(\Exception::class);
        $res = Async\wait( Async\resolve(function() {
            throw new \Exception('Oops');
            return 'FAIL';
        }));
    }



    /**
     * Executes one after the other.
     * @depends testSync
     * @depends testExecute
     * @depends testResolveGenerator
     */
    public function testChainResolve()
    {
        $calls = [
            function () {
                return Async\execute('sleep 0.1 && echo 1');
            },
            function () {
                return Async\execute('sleep 0.1 && echo 2');
            },
            function () {
                return Async\execute('sleep 0.1 && echo 3');
            },
            function () {
                return Async\execute('sleep 0.1 && echo 4');
            }
        ];
        $res = Async\chain_resolve($calls);
        $responses = Async\wait($res, 1);
        $this->assertEquals('1', trim($responses[0]));
        $this->assertEquals('2', trim($responses[1]));
        $this->assertEquals('3', trim($responses[2]));
        $this->assertEquals('4', trim($responses[3]));

        $res = call_user_func_array('Choval\\Async\\chain_resolve', $calls);
        $responses = Async\wait($res, 1);
        $this->assertEquals('1', trim($responses[0]));
        $this->assertEquals('2', trim($responses[1]));
        $this->assertEquals('3', trim($responses[2]));
        $this->assertEquals('4', trim($responses[3]));
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
            \usleep(0.1);
            return $i;
        };
        $promises = [];
        for($i=0;$i<$limit;$i++) {
            $promises[] = Async\async($func, [$i]);
        }
        $start = microtime(true);
        $res = Async\wait( $promises );
        $end = microtime(true);
        $diff = $end-$start;
        $this->assertLessThanOrEqual( ($limit/$factor) , $diff);
        $this->assertEquals($limit, count($res));
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
        $loop->addTimer(1, function() use ($defer) {
            $defer->resolve(true);
        });
        $loop->futureTick(function() use (&$i) {
            $res = Async\wait(
                Async\async(function() use ($i) {
                    for($e=0;$e<8;$e++) {
                        usleep(0.1);
                        $i++;
                    }
                    return $i;
                })
            );
            $i = $res;
        });
        $this->assertLessThanOrEqual(1, $i);
        Async\wait( $promise , 1.2);
        $this->assertGreaterThanOrEqual(0.8, $i);
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
        $retries = 6;
        $res = Async\wait(Async\retry($func, $retries, 0.1), 2);
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
        $times = 100;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            if (--$times) {
                throw new \Exception('bad error');
            }
            return $id;
        };
        $retries = $times+1;
        $res = Async\wait( Async\retry($func, $retries, 0.001, 'bad error') , 10 );
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);
    }


    public function testRetryStressWithAsync()
    {
        $times = 10;
        $id = uniqid();
        $func = function () use (&$times, $id) {
            --$times;
            return Async\async(function() use ($id, $times) {
                if ($times) {
                    throw new \Exception('bad error async');
                }
                return $id;
            });
        };
        $retries = $times+1;
        $res = Async\wait( Async\retry($func, $retries, 0.1, 'bad error async') , 10);
        $this->assertEquals($id, $res);
        $this->assertEquals(0, $times);
    }


    public function testNestingNightmare()
    {
        $id = uniqid();
        $func_a = function() use ($id) {
            return Async\async(function() use ($id) {
                usleep(1);
                return $id;
            });
        };
        $func_b = function() use ($func_a) {
            return Async\wait($func_a);
        };
        $res = Async\wait( Async\resolve($func_b) );
        $this->assertEquals($id, $res);
    }


    public function testBlockInsidePromise()
    {
        $id = uniqid();
        $func_a = function() use ($id) {
            yield Async\async(function() {
              sleep(1);  
            });
            return $id;
        };
        $func_b = function() use ($func_a) {
            return Async\wait($func_a);
        };
        $func_c = function() use ($func_b) {
            return Async\wait($func_b);
        };
        $res = Async\wait( $func_c );
        $this->assertEquals($id, $res);
    }


    public function testTimeout()
    {
        $defer = new Deferred();
        $promise = $defer->promise();
        static::$loop->addTimer(1, function() use ($defer) {
            $defer->resolve(true);
        });
        $this->expectException(\React\Promise\Timer\TimeoutException::class);
        $this->expectExceptionMessage('Timed out after 0.5 seconds');
        $res = Async\wait( Async\timeout($promise, 0.5), 1);
    }
}
