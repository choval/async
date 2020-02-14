<?php

use Choval\Async;
use Choval\Async\CancelException;
use Choval\Async\Exception as AsyncException;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Factory;
use React\Promise;
use React\Promise\Deferred;

class FilesTest extends TestCase
{
    public static $loop;

    public static function setUpBeforeClass(): void
    {
        static::$loop = Factory::create();
        Async\set_loop(static::$loop);
    }


    public function testFileGetContents()
    {
        Async\wait(function () {
            $random = bin2hex(random_bytes(16));
            $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'async.tmp';
            file_put_contents($tmp, $random);
            $contents = yield Async\file_get_contents($tmp);
            $this->assertEquals($random, $contents);
            unlink($tmp);
        });
    }


    public function testCallPhpFunctionsInAsync()
    {
        Async\wait(function () {
            $random = bin2hex(random_bytes(16));
            $tmp = tempnam(sys_get_temp_dir(), 'asynctest');

            $time = time();
            $written = yield Async\file_put_contents($tmp, $random);
            $this->assertEquals(strlen($random), $written);

            $this->assertTrue( yield Async\file_exists($tmp) );

            $data = yield Async\file_get_contents($tmp);
            $this->assertEquals($random, $data);

            $data = yield Async\filemtime($tmp);
            $this->assertEquals($time, $data);

            $data = yield Async\sha1_file($tmp);
            $this->assertEquals( sha1_file($tmp), $data);

            yield Async\unlink($tmp);

            $exists = yield Async\is_file($tmp);
            $this->assertFalse($exists);
        });
    }


    public function testPhpAsyncFunctionsStress()
    {
        Async\wait(function () {
            $file = __FILE__;
            $times = 100;
            while(--$times) {
                $res = yield Async\is_file($file);
                $this->assertTrue($res);
            }
        });
    }


    public function testGlobs()
    {
        Async\wait(function () {
            $dir = __DIR__.'/../../src/*.php';
            $files0 = glob($dir);
            $files1 = yield Async\glob($dir);
            $files2 = yield Async\rglob($dir);
            $this->assertEquals($files0, $files1);
            $this->assertEquals($files0, $files2);

            $dir = __DIR__.'/../../src/';
            $files3 = scandir($dir);
            $files4 = yield Async\scandir($dir);
            $this->assertEquals($files3, $files4);
        });
    }
}
