# Choval\Async

A few functions to ease the use of Async..

## Install

```sh
composer require choval/async
```

## Usage

```
use Choval\Async;
```

### execute

Executes a command, like exec, but async.  
Returns a promise with the output of the command.

```php
Async\execute( $loop, 'echo "Wazza"')
  ->then(function($output) {
    // $output contains Wazza
  });
```

### resolve\_generator

Resolves a Generator function.

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
$ab = function() use ($loop) {
  $out = [];
  $out[] = yield execute($loop, 'echo hello');
  $out[] = yield execute($loop, 'echo world');
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
$promises[] = Async\async( $loop, $blocking_code );
$promises[] = Async\async( $loop, $blocking_code );
$promises[] = Async\async( $loop, $blocking_code );
$promises[] = Async\async( $loop, $blocking_code );
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
$ab = function() use ($loop) {
  $start = time();
  yield Async\sleep($loop, 2);
  $end = time();
  return $end-$start;
};

$out = Async\resolve($ab);
// $out is a promise that resolves with 2
// +- microsecs
```

**DO NOT USE LIKE THIS**

```php
$start = time();
Async\sleep($loop, 2);
$end = time();

// $start and $end will have the same time
// +- microsecs
```

As `Choval\Async\sleep` is non-blocking. Check the first `sleep` example for use.

### sync (alias: wait)

Makes async code blocking, this is based on Clue's block await, but handles Promises, array of promises as well as Generators.
Use this for tests and regular blocking scripts where you need to call some async libraries.  

`wait` is an alias of `sync`.

**WARNING: This will make your code BLOCKING (aka NON-ASYNC)!**

```php
Async\sync($loop, $generator);
Async\sync($loop, $promise);
Async\sync($loop, $promises);

Async\wait($loop, $generator);
Async\wait($loop, $promise);
Async\wait($loop, $promises);
```


### chain\_resolve

This makes sures promises are resolved one after the other.  
Uses a `Generator` and `Async\resolve` to run one after the other.

```php
$calls = [];
$calls[] = function() use ($loop) { execute( $loop, 'echo 1' ); };
$calls[] = function() use ($loop) { execute( $loop, 'echo 2' ); };
$calls[] = function() use ($loop) { execute( $loop, 'echo 3' ); };
$calls[] = function() use ($loop) { execute( $loop, 'echo 4' ); };

Async\chain_resolve($calls)
  ->then($ordered) {
    // $ordered is
    // [ 1 , 2 , 3 , 4 ]
  });
```

Or alternatively, using a `Generator` and `Async\resolve`.  
`Async\chain_resolve` does this for you.

```php
$ab = function() use ($loop) {
  $out = [];
  $out[] = (int) yield execute($loop, 'echo 1');
  $out[] = (int) yield execute($loop, 'echo 2');
  $out[] = (int) yield execute($loop, 'echo 3');
  $out[] = (int) yield execute($loop, 'echo 4');
  return $out;
};

Async\resolve($ab)
  ->then($ordered) {
    // $ordered is 
    // [ 1 , 2 , 3 , 4 ]
  });
```

### retry

Retries a function multiples times before finally accepting the exception.  
This can catch a specific Exception class or message.

```php
$times = 5;
$func = function() use (&$times) {
  if(--$times) {
    throw new \Exception('bad error');
  }
  return 'good';
};
$retries = 8;
Async\retry($loop, $func, $retries, 0.1, 'bad error')
  ->then(function($res) use (&$retries, &$times) {
    // $res is 'good'
    // $retries is 3
    // $times is 0
  });
```

```
Parameters:
  LoopInterface $loop
  Closure or Generator $func
  int &$retries=10,
  float $frequency=0.1 (seconds)
  string $type='Exception' (Exception class or specific Exception Message)
```

## License

MIT, see LICENSE

