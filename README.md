# Choval\Async

A few functions to ease the use of Async..

## Install

```sh
composer require choval/async
```

## Usage

```php
use Choval\Async;
// Set the Loop to avoid having to pass it with every call.
$loop = Factory::create();
Async\set_loop($loop);
```

If the loop is not set, all calls except `resolve` and `chain_resolve`  
need a `LoopInterface` as the first parameter.

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

Resolves a `Generator` or `Closure`.

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


```php
$blocking_code = function() {
  \sleep(1);
  return time();
}
$promises = [];
$promises[] = Async\async( $blocking_code );
$promises[] = Async\async( $blocking_code );
$promises[] = Async\async( $blocking_code );
$promises[] = Async\async( $blocking_code );
$init = time();
Promise\all($promises)
  ->then( function($times) use ($init) {
    // $times will all be the same, as they ran simultaneously
    // instead of a one sec difference between each other.
    // Also, the promises take 1 sec to resolve instead of 4.

    // All times will be equal, and larger than init by 1 sec.
  });
```


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

### wait (or sync)

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

## License

MIT, see LICENSE

