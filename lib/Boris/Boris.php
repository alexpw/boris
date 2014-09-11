<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

/**
 * Boris is a tiny REPL for PHP.
 */
class Boris
{
  const VERSION = "1.0.12";

  private $prompt = "\033[1;35mphp\033[0m\033[0;37m>\033[0m ";
  private $historyFile;
  private $exports = array();
  private $startHooks = array();
  private $failureHooks = array();
  private $inspector;
  private $macros;

  /**
   * Create a new REPL, which consists of an evaluation worker and a readline client.
   *
   * @param string $prompt, optional
   * @param string $historyFile, optional
   */
  public function __construct($prompt = null, $historyFile = null)
  {
    if ($prompt !== null) {
      $this->setPrompt($prompt);
    }

    $this->historyFile = $historyFile
      ? $historyFile
      : sprintf('%s/.boris_history', getenv('HOME'));

    $this->macros = new Macros();
  }

  /**
   * Add a new hook to run in the context of the REPL when it starts.
   *
   * @param mixed $hook
   *
   * The hook is either a string of PHP code to eval(), or a Closure accepting
   * the EvalWorker object as its first argument and the array of defined
   * local variables in the second argument.
   *
   * If the hook is a callback and needs to set any local variables in the
   * REPL's scope, it should invoke $worker->setLocal($var_name, $value) to
   * do so.
   *
   * Hooks are guaranteed to run in the order they were added and the state
   * set by each hook is available to the next hook (either through global
   * resources, such as classes and interfaces, or through the 2nd parameter
   * of the callback, if any local variables were set.
   *
   * @example Contrived example where one hook sets the date and another
   *          prints it in the REPL.
   *
   *   $boris->onStart(function($worker, $vars){
   *     $worker->setLocal('date', date('Y-m-d'));
   *   });
   *
   *   $boris->onStart('echo "The date is $date\n";');
   */
  public function onStart($hook)
  {
    $this->startHooks[] = $hook;
  }

  /**
   * Add a new hook to run in the context of the REPL when a fatal error occurs.
   *
   * @param mixed $hook
   *
   * The hook is either a string of PHP code to eval(), or a Closure accepting
   * the EvalWorker object as its first argument and the array of defined
   * local variables in the second argument.
   *
   * If the hook is a callback and needs to set any local variables in the
   * REPL's scope, it should invoke $worker->setLocal($var_name, $value) to
   * do so.
   *
   * Hooks are guaranteed to run in the order they were added and the state
   * set by each hook is available to the next hook (either through global
   * resources, such as classes and interfaces, or through the 2nd parameter
   * of the callback, if any local variables were set.
   *
   * @example An example if your project requires some database connection cleanup:
   *
   *   $boris->onFailure(function($worker, $vars){
   *     DB::reset();
   *   });
   */
  public function onFailure($hook)
  {
    $this->failureHooks[] = $hook;
  }

  /**
   * Set a local variable, or many local variables.
   *
   * @example Setting a single variable
   *   $boris->setLocal('user', $bob);
   *
   * @example Setting many variables at once
   *   $boris->setLocal(array('user' => $bob, 'appContext' => $appContext));
   *
   * This method can safely be invoked repeatedly.
   *
   * @param array|string $local
   * @param mixed $value, optional
   */
  public function setLocal($local, $value = null)
  {
    if (!is_array($local)) {
      $local = array($local => $value);
    }

    $this->exports = array_merge($this->exports, $local);
  }

  /**
   * Sets the Boris prompt text
   *
   * @param string $prompt
   */
  public function setPrompt($prompt)
  {
    $this->prompt = $prompt;
  }

  /**
   * Set an Inspector object for Boris to output return values with.
   *
   * @param object $inspector any object the responds to inspect($v)
   */
  public function setInspector($inspector)
  {
    $this->inspector = $inspector;
  }

  /**
   * Set an Macro pattern for Boris to transform input expressions.
   *
   * @param string $match A regex pattern to compare against input expressions.
   * @param string|callable $replacer A replacer string or callback function.
   */
  public function setMacro($match, $replacer)
  {
    $this->macros[$match] = $replacer;
  }

  /**
   * Start the REPL (display the readline prompt).
   *
   * This method never returns.
   */
  public function start()
  {
    if (! isset($this->inspector)) {
      $this->inspector = new Inspector\Colored();
    }

    $this->displayWelcome();
    $this->forkAndStart();
  }

  private function displayWelcome()
  {
    printf("Boris %s\n", self::VERSION);
    printf("PHP %s\n", PHP_VERSION);
    printf("%16s: Control+D or exit\n", 'Exit');
    printf("%16s: Control+C\n", 'Clear Line');
    printf("%16s: Stored in vars \$_ (also \$_1), \$_2, \$_3, respectively\n", 'Past Results');
  }

  private function forkAndStart()
  {
    declare(ticks = 1); // required "for the signal handler to function"
    pcntl_signal(SIGINT, SIG_IGN, true);

    if (! $pipes = stream_socket_pair(
      STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP)) {
      throw new \RuntimeException('Failed to create socket pair');
    }

    $pid = pcntl_fork();

    if ($pid > 0) {

      if (function_exists('setproctitle')) {
        setproctitle('boris (master)');
      }
      fclose($pipes[0]);
      $client = new ReadlineClient($pipes[1]);
      $client->start($this->prompt, $this->historyFile);

    } else if ($pid < 0) {

      throw new \RuntimeException('Failed to fork child process');

    } else {

      if (function_exists('setproctitle')) {
        setproctitle('boris (worker)');
      }
      fclose($pipes[1]);
      $worker = new EvalWorker($pipes[0]);
      $worker->setLocal($this->exports);
      $worker->setStartHooks($this->startHooks);
      $worker->setFailureHooks($this->failureHooks);
      $worker->setInspector($this->inspector);
      $worker->setMacros($this->macros);
      $worker->start();
    }
  }
}
