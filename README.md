# Choval\Async


A library to ease handling promises in [ReactPHP](https://reactphp.org).

* [Install](#install)
* [Usage](#usage)
* [License](#license)
* Functions
  * [async](#async) Run blocking code in async mode
  * [execute](#execute) Execute a command
  * [is\_done](#is_done) Instantly return if the Promise is resolved or rejected
  * [resolve](#resolve) Use yield with promises!
  * [retry](#retry) Retry a function multiple times
  * [silent](#silent) Silently resolve
  * [sleep](#sleep) Non-blocking sleep
  * [timeout](#timeout) Adds a timeout to a Promise
  * [timer](#timer) Allows to time a Promise
  * [wait](#wait) Make async code synchronous
  * [wait\_memory](#wait_memory) Waits for a number of RAM bytes to be available
  * [PHP file functions](#file_functions) PHP's blocking file functions in async mode


## Install

```sh
composer require choval/async
```

## Notes

Tested on PHP 7.2 and PHP 7.3. Currently used in production systems. Untested on PHP 7.4.

## Usage

```php
use Choval\Async;
use React\EventLoop\Factory;

$loop = Factory::create();

// Set the Loop to avoid having to pass it with every call.
Async\set_loop($loop);
```

If the loop is not set, all calls except `resolve` need a [`LoopInterface`](https://github.com/reactphp/event-loop) as the first parameter.


### Using yield

The ugly way:

```php
function future($i=0)
{
	return new React\Promise\FulfilledPromise($i+1);
}

future()
	->then(function ($i) {
		return future($i);
	})
	->then(function ($i) {
		return future($i);
	})
	->then(function ($i) {
		return future($i);
	})
	->then(function ($i) {
		return future($i);
	})
	->then(function ($i) {
		echo $i;
	});

// Prints 5, but that chain nightmare...
```

Using `yield`, remember `future()` is returning a `Promise`.  
And we're not blocking other events in the loop ;-)

```php
Async\resolve(function () {
	$i = yield future();
	$i = yield future($i);
	$i = yield future($i);
	$i = yield future($i);
	$i = yield future($i);
	echo $i;
});

// Prints 5 as well ;-)
```

Or in a while-loop

```php
Async\resolve(function () {
	$i = 0;
    while($i<5) {
        $i = yield future($i);
    }
	echo $i;
});
```

## Functions

### is\_done

Checks if a `Promise` has been _resolved_ or _rejected_. This returns a boolean, not a `Promise`.  

```php
$defer = new React\Promise\Deferred();
$loop->addTimer(1, function () use ($defer) {
    $defer->resolve(true);
});
$promise = $defer->promise();
$i = 0;
while(!Async\is_done($promise)) {
    $i++;
}
echo "Promise finished with $i loops\n";
```


### resolve

This is what will let you `yield` promises, it's like Node.js [`await`](https://github.com/caolan/async).

```php
$promise = Async\resolve(function () {
  yield 1;
  yield 2;
  return 'Wazza';
});
// $promise resolves with Wazza
```

Take for example the following async events.

```php
$defer1 = new React\Promise\Deferred();
$loop->addTimer(1, function () use ($defer1) {
	$defer1->resolve('hello');
});
$defer2 = new React\Promise\Deferred();
$loop->addTimer(0.5, function () use ($defer2) {
	$defer2->resolve('world');
});

$promise = Async\resolve(function () use ($defer1, $defer2) {
  $out = [];
  $out[] = yield $defer1->promise();
  $out[] = yield $defer2->promise();
  return implode(' ', $out);
});
```

`$promise` resolves with `hello world` in 1 sec, despite the second promise resolving first.

What if you need to run multiple async simultaneously?

```php
$promise = Async\resolve(function () {
	$fetch = [
		'bing' => 
			Async\execute('curl https://bing.com/'),
		'duckduckgo' => 
			Async\execute('curl https://duckduckgo.com/'),
		'google' => 
			Async\execute('curl https://google.com/'),
	];
	$sources = yield React\Promise\all($fetch);
	return $sources;
});
```

#### Memory usage

If `Async\resolve` is called without an `EventLoop` (as the second parameter), it will fall back to unwrapping the Generator by chaining promises, which can use a considerably high ammount of memory if `yield` is inside a loop.  
Therefore, `resolve` will try to retrieve the `EventLoop` set from `Async\init` or `Async\setLoop`.

### silent

Similar to `resolve`, but will catch any `Exception` and save it in the second parameter.  
If it fails, the promise will resolve with null.

```php
$fn = function () {
    throw new \Exception('hey!');
};
$promise = Async\silent($fn, $e);
// Promise resolves with null
// $e will hold an the hey! exception
```

### execute

Executes a command asynchronously.  
Returns a `Promise` with the output of the command.

```php
Async\execute('echo "Wazza"')
  ->then(function ($output) {
    // $output contains Wazza\n
  })
  ->otherwise(function ($e) {
    // Throws an Exception if the execution fails
    // ie: 127 if the command does not exist
    $exitCode = $e->getCode();
  });
```

A `timeout` parameter (in seconds) can be passed.

### sleep

An asynchronous `sleep` function. This won't block other events.

```php
$promise = Async\resolve(function () {
  $start = time();
  yield Async\sleep(2);
  $end = time();
  return $end-$start;
});
// $promise resolves in ~2 seconds
```

Remember this is a non-blocking `sleep`, if you do not wait for it or yield inside an Async\resolve, the `Promise` will solve in the background.

```php
$start = time();
Async\sleep(2);
$end = time();
// $start and $end will be the same
```

### wait

Also knowsn as `sync`, makes asynchronous code blocking. Use this when you need to use an async library in a sync/blocking scenario.

This function receives one of the following: `Generator`, `Closure` or `PromiseInterface`.

```php
$start = time();
Async\wait(Async\sleep(2));
$end = time();
// $end == $start+2;
```

A second float parameter is a timeout in seconds, defaults to no timeout.

A third float parameter is the interval at which to check, defaults to 0.01 secs.
A low interval will consume much more CPU.

### async

Have a piece of blocking code that you need to run in async?
Use this, just keep in mind it is using `pcntl_fork`.

First parameter is a callable, second parameter is an array of parameters for the callable.

```php
$blocking_code = function ($secs) {
  sleep($secs);
  return time();
}

$secs = 1;
$promises = [];
$promises[] = Async\async($blocking_code, [$secs]);
$promises[] = Async\async($blocking_code, [$secs]);
$promises[] = Async\async($blocking_code, [$secs]);
$promises[] = Async\async($blocking_code, [$secs]);
$base = time()+$secs;

$times = Async\wait(React\Promise\all($promises));
foreach ($times as $time) {
	// $time === $base
}
```

There's a limit of 50 simultaneously running async forks.
This limit can be changed by calling `Async\set_forks_limit`.  
This limit is counted for `Async\execute` as well.

```php
Async\set_forks_limit(100);
echo Async\get_forks_limit(); // 100
```

When the limit is reached, the code will wait for any previous
fork to finish before continuing, keeping a max of async forks
at the set forks limit.

### retry

Runs a __function__ (Closure/Generator) up to `retries` times for a "good" return. Otherwise, returns the last Exception.  
This function can also ignore a set of Exception classes or messages.

```php
$times = 5;
$func = function () use (&$times) {
  if(--$times) {
    throw new \Exception('bad error');
  }
  return 'ok';
};
$retries = 6;
Async\retry($func, $retries, 0.1, 'bad error')
  ->then(function ($res) {
    // $res is 'ok'
  });
```

```php
/**
 * @param LoopInterface $loop (optional)
 * @param callable $func
 * @param int $retries=10 (optional)
 * @param float $frequency=0.001 (optional)
 * @param string $ignore_errors (optional) The Throwable class to catch or string to match against Exception->getMessage()
 *
 * @return Promise
 */
```

### timeout

Similar to `React\Promise\Timer\timeout()`, but allows a `Generator` or `Closure` too.

```php
$func = function () {
    yield Async\sleep(2);
    return true;
};
Async\wait(Async\timeout($func, 1.5));
// Throws an Exception due to the timeout 1.5 < 2
```

### timer

Saves the number of elapsed microseconds (float).

```php
Async\wait(function() {
    Async\timer(function () {
        Async\sleep(0.1);
    }, $msecs);
    print_r($msecs); // ~100ms
});
```

### wait\_memory

Waits for a number of memory bytes to be available.  
This is used inside loops to avoid memory exhaution due to multiple Promises being created and left in background.

```php
Async\wait(function () {
    $loop = 20000;
    $mem = 1024*1024*16; // 16MB
    while($loop--) {
        yield Async\waitMemory($mem);
        Async\sleep(1);
    }
});
```

A second parameter can be passed for the frequency to run the check.  
Returns the number of bytes remaining (`memory_limit` - `memory_get_usage()`).

### rglob

A recursive `glob` with an ignore parameter.

Consider the following files:

```
/files/
/files/a.txt
/files/b.txt
/files/a.php
/files/b.php
/files/c.php
/files/1/a.txt
/files/1/a.php
/files/1/b.php
/files/1/c.php
/files/2/a.php
/files/2/b.php
/files/2/c.php
```

```php
$files = Async\wait(Async\rglob('/files/*.php', 'a'));
/*
$files has:
/files/b.php
/files/c.php
/files/1/b.php
/files/1/c.php
/files/2/b.php
/files/2/c.php
*/
```

<a name="file_functions"></a>
### PHP file functions

The following functions are available with the same parameters as their PHP versions, but run using `Async\async` and accept a `LoopInterface` as their first parameter.

These are not ready for production and/or not tested/optimized. Please use with caution.

```
file_get_contents
file_put_contents
file_exists
is_file
is_dir
is_link
sha1_file
md5_file
mime_content_type
realpath
fileatime
filectime
filemtime
file
filesize
copy
rename
unlink
touch
mkdir
rmdir
scandir
glob
```

Example:

```php
$lines = Async\wait(Async\file('/etc/hosts'));
var_dump($lines);
```

## License

MIT, see LICENSE.
