# Boris

This is a fork, please see and consider [the original](https://github.com/d11wtq/boris).  This fork is fully functional, but is not battle tested.  The rest of this README has been modified to accurately reflect this fork.

## Differences from Original

* Redefine existing functions and (basic) classes to quickly experiment with code.
 * Available when runkit ext is installed (optional).
* Swapped PHP's readline for a custom one based on [Hoa Console](hoa-project.net/En/Literature/Hack/Console.html)
  * This provided full control over the UX, but a lot of potential bugs reinventing the basics.
* Autocomplete, thanks to [joddie](https://github.com/joddie/boris/tree/completion-and-network-hacks)!
 * Backported to support php 5.3
* "Macro" support, allows transforming the input before it is evaled.
  * Support for PHP's "use" statements, just a macro.
* Multi-line history.

## Intro
A REPL for PHP.

> **Announcement:** I'm looking to add one or two additional collaborators with
> commit access. If you are actively involved in open source and have a GitHub
> profile for review, ping me on Twitter (@d11wtq) to express your interest.
> Experienced developers with active GitHub projects only.

![Demo](http://dl.dropbox.com/u/508607/BorisDemo-v4.gif "Quick Demo")

Python has one. Ruby has one. Clojure has one. Now PHP has one too. Boris is PHP's missing REPL (read-eval-print loop), allowing developers to experiment with PHP code in the terminal in an interactive manner.  If you make a mistake, it doesn't matter, Boris will report the error and keep on going.

Everything you enter into Boris is evaluated and the result inspected so you
can understand what is happening.  State is maintained between inputs, allowing you to gradually build up a solution to a problem.

## Why?

I have come to find PHP's lack of a real REPL to be frustrating and was not able to find an existing implementation that was complete.

Facebook's [phpsh](http://phpsh.org/) is written in Python, abandoned, and most of the feature list doesn't work for me in the latest commits.

Ieure's [PHP_Repl](https://github.com/ieure/php_repl) is 5 years old.  It's got some great stuff, but terminates on fatals, a deal breaker.

## Installation

### 1. Via packagist

For use with composer.

  - https://packagist.org/packages/alexpw/boris

### 2. Directly from this repo

This is great if you want to stay really up-to-date, but I (alexpw) may commit unstable code to master, at least until someone else starts using this fork.

    git clone git://github.com/alexpw/boris.git
    cd boris
    ./bin/boris

### 3. Build your own phar

You can also build a PHAR file using [Box](http://box-project.org/):

    box build

This will create a `boris.phar` file. Feel free to move it into your bin
directory:

    chmod +x boris.phar
    mv boris.phar /usr/local/bin/boris

### Pro Tip

Add boris to your $PATH for easy access.

## Usage

When Boris starts, you will be at the `php>` prompt. PHP code you enter at
this prompt is evaluated.  If an expression spans multiple lines, Boris will
collect the input and then evaluate the expression when it is complete. Press CTRL-C to clear a multi-line input buffer if you make a mistake.

    php> $x = 1;
    // 1
    php> $y = 2;
    // 2
    php> "x + y = " . ($x + $y);
    // "x + y = 3"
    php> exit

You can also use CTRL-D to exit the REPL.

### Cancelling long-running operations

Long-running operations, such as infinite loops, may be cancelled at any time without quitting the REPL, by using CTRL-C while the operation is running.

    php> for ($i = 0; ; ++$i) {
      *>   if ($i % 2 == 0) printf("Tick\n");
      *>   else             printf("Tock\n");
      *>   sleep(1);
      *> }
    Tick
    Tock
    Tick
    Tock
    Tick
    Tock
    Tick
    ^CCancelling...
    php>

### Using Boris with your application loaded

You can also use Boris as part of a larger project (e.g. with your application
environment loaded).

    require_once 'lib/autoload.php';

    $boris = new \Boris\Boris('myapp> ');
    $boris->start();

The constructor parameter is optional and changes the prompt.

If you want to pass local variables straight into Boris (e.g. parts of your
application), you can do that too (thanks to [@dhotston](https://github.com/dhotston)):

    $boris = new \Boris\Boris('myapp> ');
    $boris->setLocal(array('appContext' => $appContext));
    $boris->start();

In the above example, $appContext will be present inside the REPL.

### Using start hooks

It is possible to add callbacks to Boris that are executed inside the REPL
before it begins looping. Any number of hooks can be added, and they will be
executed in order. Any variables set or exported by the hook will become
visible from inside the REPL and consequently, to subsequent hooks that are
run.

There are two ways to specify hooks: as arbitrary strings of PHP code to
evaluate, or as callbacks given as closures. Both approaches allow you access
to the scope, though you need to do slightly more work with the callback
approach.

    // this will simply be evaluated in the REPL scope
    // the variables $foo and $bar will be visible inside the REPL
    $boris->onStart('$foo = 42; $bar = 2; echo "Hello Boris!\n";');

    // this will be passed the REPL and it's scope as arguments
    // any changes to the scope can be expressed by invoking the usual
    // methods on the REPL
    $boris->onStart(function($worker, $scope){
      extract($scope); // we could also just access specific vars by key

      echo '$foo * $bar = ' . ($foo * $bar) . "\n";

      $worker->setLocal('name', 'Chris');
    });

Above we added two hooks. The first just gets passed to `eval()` and leaves
`$foo` and `$bar` in scope. The second uses the callback style and reads its
variables from the `$scope` parameter, then sets variables into the REPL
with `setLocal()`.

### User configuration files

If you have, things you always want to do when Boris starts, such as load
useful utility functions, change the prompt or set local variable, you
may create a ~/.borisrc.php file, which will be loaded whenever Boris starts up (also supports ~/.borisrc, but you'll lose syntax highlighting).

The contents of this file are just arbitrary PHP code. You are *not* inside
the REPL itself in this file, but you have access to `$boris`, which is the
REPL object. Here's an example ~/.borisrc.php that sets the prompt.

    <?php

    /* File: ~/.borisrc.php */

    $boris->setPrompt('prompt> ');

Boris will also look under your current working directory for this file. If
it finds one on both locations, they will both be loaded by default (not that
this is customizable at the code level). If you need to execute code in the
REPL itself, use hooks as documented above.

Thanks to [@filp](https://github.com/filp) for this feature!

### Customizing the output

After each expression you enter, Boris passes it through an Inspector to get a
representation that is useful for debugging. The default is does some nice
highlighting of the data types in the value, to make it easier to read at a
glance, but you can change this behaviour.

Any object that has an `inspect($variable)` method may be used for this purpose.

    $boris->setInspector(new BlinkInspector());

Boris comes with three alternatives out of the box:

  * \Boris\ColoredInspector, which does data-type highlighting and is the default
  * \Boris\DumpInspector, which uses a simple, but effective var_dump()
  * \Boris\ExportInspector, which uses var_export()

Note that you can change this from inside the REPL too:

    php> $this->setInspector(new \Boris\ExportInspector());
    // NULL
    php> "Test";
    // 'Test'

To further customize object output within `\Boris\ColoredInspector`, you may
subclass and override the `objectVars($value)` method:

    class MyInspector extends \Boris\ColoredInspector {
        public function objectVars($value) {
            if ($value instanceof User) {
                return array('user_id' => $value->getUserId());
            }

            return parent::objectVars($value);
        }
    }

This overrides the default behavior of simply calling [`get_object_vars()`][get_object_vars]
on the object, allowing you to display properties that may be otherwise obfuscated
behind magic methods or property visibility.

  [get_object_vars]: http://php.net/get_object_vars

## Boris doesn't display exceptions or errors when running in my app?

Boris honours your environment. If your application has error handlers
installed, they will mask the error. Likewise, if an exception handler is
installed, you won't see a backtrace (unless your exception handler displays it).

Since Boris is much more useful when you can see errors in the console, the best
thing to do is to disable any exception/error handlers when your application
is running inside of Boris.

## What about PHP's interactive mode?

PHP's interactive mode does not print the result of evaluating expressions and
more importantly, it exits if you type something that produces a fatal error,
such as invoking a function/method that does not exist, or an uncaught
exception.  Boris is designed to be robust, like other REPLs, so you can
experiment with things that you know may error, without losing everything.

## Architecture Overview

This section of the README only applies to those curious enough to read the
code. Boris is quite different to other PHP REPLs, because it deals with fatal
errors (not Exceptions, fatal errors) in a special way.

Boris will only work on POSIX systems (Linux and Mac OS).  This is primarily
because it depends on the ability to fork, but also because it plays with signals
a lot too.

Boris is made up of two parts:

  1. A REPL worker process, which receives expressions to evaluate and print
  2. A readline client, which simply takes your input, sends it to the worker
     and then loops

If all errors in PHP were exceptions, building a REPL would be simple. This is
not the case, however.  Some PHP errors are truly fatal and cannot be caught.
In order to prevent such fatal errors from killing the REPL, the worker looks
something like this:

    for(;;) {
      $input = accept_some_input();
      if (fork_child()) {
        wait_for_child();
      } else { // inside child
        var_dump(eval($input));
        kill_parent();
      }
    }

The child is forked with all current variables and resources.  It evaluates the
input then kills the parent, then the loop continues inside the child, waiting
for the next input.

While the child is evaluating the input, the parent waits. The parent is
expecting the worst—that the child will die abnormally—at which point the parent
continues waiting for input and does not terminate.  The state remains unchanged.

After each expression is evaluated, the worker reports back to the main process
with a status code of 0 (keep running) or 1 (terminate).

The main process (readline) of Boris is much more straightforward.  It takes
your input, performs a (very) shallow parse on it, in order to decide if it
needs to wait for further input, or evaluate the input (one statement at a time)
it has received.  If the worker reports back with a status code of 1, the process
terminates, otherwise the next iteration of the loop is entered.

## Will it work with...?

Boris depends on the following PHP features:

  - PHP >= 5.3
  - The [PCNTL](http://php.net/pcntl) functions
  - The [POSIX](http//php.net/posix) functions

And optionally can use:

  - [Runkit](http://php.net/runkit)

There's no chance it can work on Windows, due to the dependency on POSIX
features (the code is almost entirely dependant on POSIX).

### Installing runkit via pecl
Runkit has not been released to a pecl repo for many years, despite being maintained, afaik.  So, you really want to just install from source.  Luckily, pecl can do this for you.

 1. Download the [latest source](http://git.php.net/?p=pecl/php/runkit.git;a=summary)
 2. Extract and cd into it.
 3. sudo pecl install package.xml
 4. If, like me, you get complaints of missing test files:
   - Open package.xml and remove the offending lines.
   - Retry the install (#3).
 5. Ensure your php.ini contains extension=runkit.so and php -m lists the module.

Now when you try to redefine a re-existing function or basic class, it will succeed, rather than display a Fatal.

## Copyright & Licensing

Boris is written and maintained by Chris Corbyn (@d11wtq). You can use the
code as you see fit. See the LICENSE file for details.
