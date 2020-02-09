# Choval\Async

A collection of functions for programming in [ReactPHP](https://reactphp.org).

* [Install](#install)
* [Usage](#usage)
* Functions
  * [execute](#execute)
  * [resolve](#resolve)
  * [async](#async)
  * [sleep](#sleep)
  * [wait](#wait)
  * [chain_resolve](#chain_resolve)
  * [retry](#retry)
* [License](#license)

## Install

```sh
composer require choval/async
```

## Usage

```php
use Choval\Async;
// Set the Loop to avoid having to pass it with every call.
$loop = React\EventLoop\Factory::create();
Async\set_loop($loop);
```

If the loop is not set, all calls except `resolve` and `chain_resolve`  
need a [`LoopInterface`](https://github.com/reactphp/event-loop) as the first parameter.

### Using yield

Regular promise handling:

```php
use React\Promise\FulfilledPromise;

function future($i=0)
{
	return new FulfilledPromise($i+1);
}

future()
	->then(function($i) {
		return future($i);
	})
	->then(function($i) {
		return future($i);
	})
	->then(function($i) {
		return future($i);
	})
	->then(function($i) {
		return future($i);
	})
	->then(function($i) {
		echo $i;
	});

// Prints 5, but that chain nightmare...
```

With `Choval\Async`

```php
use Choval\Async;
use React\Promise\FulfilledPromise;

function future($i=0)
{
	return new FulfilledPromise($i+1);
}

Async\resolve(function() {
	$i = yield future();
	$i = yield future($i);
	$i = yield future($i);
	$i = yield future($i);
	$i = yield future($i);
	echo $i;
});

// Prints 5 as well ;-)
```

Or just loop it ...

```php
use Choval\Async;
use React\Promise\FulfilledPromise;

function future($i=0)
{
	return new FulfilledPromise($i+1);
}

Async\resolve(function() {
	$i=0;
	for($e=0;$e<5;$e++) {
		$i = yield future($i);
	}
	echo $i;
});
```


## Functions

### execute

Executes a command, like exec, but async.  
Returns a promise with the output of the command.

```php
Async\execute('echo "Wazza"')
  ->then(function($output) {
    // $output contains Wazza
  })
  ->otherwise(function($e) {
    // Throws an Exception if the execution fails
    // ie: 127 if the command does not exist
    $exitCode = $e->getCode();
  });
```


### resolve

Resolves a `Generator` or `Closure`. Allows using `yield` all over as `await`.

```php
$ab = function() {
  yield 1;
  yield 2;
  return 'Wazza';
};

$out = Async\resolve($ab);
// $out is a promise that resolves with Wazza
```

Allows you to ease promise handling like this:

```php
$ab = function() {
  $out = [];
  $out[] = yield Async\execute('echo -n hello');
  $out[] = yield Async\execute('echo -n world');
  return implode(' ', $out);
};

$out = Async\resolve($ab);
// $out is a promise that resolves with 'hello world'
```

### async

This can also be used to make blocking code run asynchronously.  
Creates a fork in the background.

Arguments can be passed in an array (`call_user_func_array`).


```php
$blocking_code = function($secs) {
  \sleep($secs);
  return time();
}
$secs = 1;
$promises = [];
$promises[] = Async\async( $blocking_code , [$secs]);
$promises[] = Async\async( $blocking_code , [$secs]);
$promises[] = Async\async( $blocking_code , [$secs]);
$promises[] = Async\async( $blocking_code , [$secs]);
$init = time();
Promise\all($promises)
  ->then( function($times) use ($init) {
    // $times will all be the same, as they ran simultaneously
    // instead of a one sec difference between each other.
    // Also, the promises take 1 sec to resolve instead of 4.

    // All times will be equal, and larger than init by 1 sec.
  });
```

There's a limit of 20 simultaneously running async forks.
This limit can be changed by calling `Async\setForksLimit`.

```php
Async\set_forks_limit(100);
echo Async\get_forks_limit(); // 100
```

When the limit is reached, the code will wait for a previous
fork to finish before running, keeping a max of async forks
at the set forks limit (100msec between checks).


### sleep

An async sleep function. This will keep your code async.

```php
$ab = function() {
  $start = time();
  yield Async\sleep(2);
  $end = time();
  return $end-$start;
};

$out = Async\resolve($ab);
// $out is a promise that resolves with 2
// +- microsecs
```

**DO NOT USE LIKE THIS**

As `Choval\Async\sleep` is non-blocking.

```php
$start = time();
Async\sleep(2);
$end = time();

// $start and $end will have the same time
// +- microsecs
```

### wait

(aka sync)

Makes async code blocking, this is based on Clue's block await.  
Handles `Generator`, `Closure`, `Promise`, `Buffer` and `array` of `Promise`.  

Use this for regular blocking scripts where async libraries are needed.

`wait` and `sync` are aliases.

**WARNING: This will make your code BLOCKING (aka NON-ASYNC)!**

```php
$res = Async\sync($generator);
$res = Async\sync($promise);
$res = Async\sync($promises);

$res = Async\wait($generator);
$res = Async\wait($promise);
$res = Async\wait($promises);
```


### chain\_resolve

This makes `Promise` or `Generator` run one after the other.  

```php
$calls = [];
$calls[] = function() { Async\execute('echo -n 1'); };
$calls[] = function() { Async\execute('echo -n 2'); };
$calls[] = function() { Async\execute('echo -n 3'); };
$calls[] = function() { Async\execute('echo -n 4'); };

Async\chain_resolve($calls)
  ->then($ordered) {
    // $ordered is
    // [ 1 , 2 , 3 , 4 ]
  });
```

Or alternatively, using a `Generator` and `Async\resolve`.  
`Async\chain_resolve` does this for you.

```php
$ab = function() {
  $out = [];
  $out[] = (int) yield execute('echo -n 1');
  $out[] = (int) yield execute('echo -n 2');
  $out[] = (int) yield execute('echo -n 3');
  $out[] = (int) yield execute('echo -n 4');
  return $out;
};

Async\resolve($ab)
  ->then($ordered) {
    // $ordered is 
    // [ 1 , 2 , 3 , 4 ]
  });
```

### retry

Retries a function multiples times before finally accepting the last `Exception`.  
This can catch a specific `Exception` class or message.

```php
$times = 5;
$func = function() use (&$times) {
  if(--$times) {
    throw new \Exception('bad error');
  }
  return 'good';
};
$retries = 8;
Async\retry($func, $retries, 0.1, 'bad error')
  ->then(function($res) use (&$retries, &$times) {
    // $res is 'good'
    // $retries is 3
    // $times is 0
  });
```

```php
/**
 * @param LoopInterface $loop (optional)
 * @param callable $func
 * @param int $retries=10 (optional)
 * @param float $frequency=0.1 (optional)
 * @param string $type (optional) The Throwable class to catch or string to match
 *
 * @return Promise
 */
```

### timeout

Alias of `React\Promise\Timer\timeout()` with a resolve for the promise, allowing to pass a `Generator` or a `Closure`.

```php
$func = function() {
    yield Async\sleep(2);
    return true;
};
Async\timeout($func, 1.5)
    ->then(function($r) {
        // Will not reach here
    })
    ->otherwise(function($e) {
        // Will reach here
        $timedout_seconds = $e->getTimeout();
    });
```

### is\_done

Checks if a `Promise` has been _resolved_ or _rejected_. This returns a boolean.

```php
$defer = new Deferred();
$loop->addTimer(1, function () use ($defer) {
    $defer->resolve(true);
});
$promise = $defer->promise();
$i=0;
while(!Async\is_done($promise)) {
    $i++;
}
echo "Promise finished\n";
// $i is around 20k+ in my laptop on battery
```

### rglob

A recursive `glob` with an ignore parameter.

Consider the following files:

```
/files/
/files/a.php
/files/b.php
/files/c.php
/files/1/a.php
/files/1/b.php
/files/1/c.php
/files/2/a.php
/files/2/b.php
/files/2/c.php
```

```php
$loop = React\EventLoop\Factory::create();
Async\rglob('/files/*.php', 'a')
	->then(function($files) {
		/*
		$files has:
			/files/b.php
			/files/c.php
			/files/1/b.php
			/files/1/c.php
			/files/2/b.php
			/files/2/c.php
		 */
	});
```

### PHP file functions

The following functions are available with the same parameters as their PHP versions,
but run using `Async\async` and can have a `LoopInterface` as their first parameter.

- file\_get\_contents
- file\_put\_contents
- file\_exists
- is\_file
- is\_dir
- is\_link
- sha1\_file
- md5\_file
- mime\_content\_type
- realpath
- fileatime
- filectime
- filemtime
- file
- filesize
- copy
- rename
- unlink
- touch
- mkdir
- rmdir
- scandir
- glob

Example:

```php
use Choval\Async;
$loop = React\EventLoop\Factory::create();
Async\file($loop, '/etc/hosts')
	->then(function($lines) {
		var_dump($lines);
	});
```

## License

MIT, see LICENSE

