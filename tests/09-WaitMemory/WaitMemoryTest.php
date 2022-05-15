<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class WaitMemoryTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }


    public function testWaitMemory()
    {
        Async\wait(function () {
            Async\load_memory_limit('128M');
            $size = 4096;
            $mem_req = 1024*1024*16;    // 16MB
            $init_free = $free = yield Async\wait_memory($mem_req);
            /*
            $sets = round( $mem_req / $size * 0.8);
            $loop = round( $free / $size * 1.1 / $sets );
             */
            $loops = $loop = round($free / $size * 1.1);
            $min = $free;
            $start = time();
            while ($loop--) {
                $free = yield Async\wait_memory($mem_req, 0.1);
                if ($free < $min) {
                    $min = $free;
                }
                /*
                for($i=0;$i<$sets;$i++) {
                    Async\sleep(1);
                }
                 */
                Async\sleep(1);
            }
            $diff = time()-$start;
            $this->assertLessThan($init_free, $min);
            $this->assertGreaterThan($mem_req, $min);
            $this->assertLessThan($loops / 1000, $diff);
        });
    }
}
