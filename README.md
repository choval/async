# Choval\Async

A few functions to ease the use of Async..

## Install

```sh
composer require choval/async
```

## Usage

### execute

Executes a command, like exec, but async.  
Returns a promise with the output of the command.

```php
execute( $loop, 'echo "Wazza"')
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

$out = resolve_generator($ab);
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

$out = resolve_generator($ab);
// $out is a promise that resolves with 'hello world'
```

### sleep

An async sleep function. This will keep your code async.

```php
$ab = function() use ($loop) {
  $start = time();
  yield sleep($loop, 2);
  $end = time();
  return $end-$start;
};

$out = resolve_generator($ab);
// $out is a promise that resolves with 2
// +- microsecs
```

**DO NOT USE LIKE THIS**

```php
$start = time();
sleep($loop, 2);
$end = time();

// $start and $end will have the same time
// +- microsecs
```

As `Choval\Async\sleep` is non-blocking. Check the first `sleep` example for use.

### sync

Makes async code blocking, this is based on Clue's block await, but handles Promises, array of promises as well as Generators.
Use this for tests and regular blocking scripts where you need to call some async libraries.  

**WARNING: This will make your code BLOCKING (aka NON-ASYNC)!**

```php
sync($loop, $generator);
sync($loop, $promise);
sync($loop, $promises);
```

### chain\_resolve

This makes sures promises are resolved one after the other.  
This was created for non-generator scenarios, but a Generator is much better.  
Leaving this for legacy code, but check the suggested method.

```php
$calls = [];
$calls[] = function() use ($loop) { execute( $loop, 'echo 1' ); };
$calls[] = function() use ($loop) { execute( $loop, 'echo 2' ); };
$calls[] = function() use ($loop) { execute( $loop, 'echo 3' ); };
$calls[] = function() use ($loop) { execute( $loop, 'echo 4' ); };

chain_resolve($calls)
  ->then($ordered) {
    // $ordered is
    // [ 1 , 2 , 3 , 4 ]
  });
```

Using a generator and `resolve_generator`

```php
$ab = function() use ($loop) {
  $out = [];
  $out[] = (int) yield execute($loop, 'echo 1');
  $out[] = (int) yield execute($loop, 'echo 2');
  $out[] = (int) yield execute($loop, 'echo 3');
  $out[] = (int) yield execute($loop, 'echo 4');
  return $out;
};

resolve_generator($ab)
  ->then($ordered) {
    // $ordered is 
    // [ 1 , 2 , 3 , 4 ]
  });
```

## License

MIT, see LICENSE

