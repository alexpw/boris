<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

/**
 * EvalWorker is responsible for evaluating PHP expressions in forked processes.
 */
class EvalWorker
{
  const EXIT_ABNORMAL = 255;
  const ACTION_EVAL_STMT    = "eval";
  const ACTION_AUTOCOMPLETE = "\1";
  const ACTION_DELIVER      = "\2";

  private $socket;
  private $exports = array();
  private $startHooks = array();
  private $failureHooks = array();
  private $ppid;
  private $pid;
  private $cancelled;
  private $inspector;
  private $completer;
  private $macros;
  private $userExceptionHandler;

  /**
   * Create a new worker using the given socket for communication.
   *
   * @param resource $socket
   */
  public function __construct($socket)
  {
    $this->socket    = $socket;
    $this->inspector = new Inspector\Dump();
    $this->completer = new Completer($this);
    stream_set_blocking($socket, 0);
  }

  /**
   * Set local variables to be placed in the workers's scope.
   *
   * @param array|string $local
   * @param mixed $value, if $local is a string
   */
  public function setLocal($local, $value = null)
  {
    if (!is_array($local)) {
      $local = array($local => $value);
    }

    $this->exports = array_merge($this->exports, $local);
  }

  /**
   * Set hooks to run inside the worker before it starts looping.
   *
   * @param array $hooks
   */
  public function setStartHooks($hooks)
  {
    $this->startHooks = $hooks;
  }

  /**
   * Set hooks to run inside the worker after a fatal error is caught.
   *
   * @param array $hooks
   */
  public function setFailureHooks($hooks)
  {
    $this->failureHooks = $hooks;
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
   * Set the preloaded Macros ArrayObject for input transforms.
   *
   * @param ArrayObject $macros Elements of [regex pattern] => [replacer str/fn]
   */
  public function setMacros($macros)
  {
    $this->macros = $macros;
  }


  /**
   * Start the worker.
   *
   * This method never returns.
   */
  public function start()
  {
    $scope = $this->runHooks($this->startHooks);
    fwrite($this->socket, SocketComm::SIGNAL_READY);

    for (;;) {
      declare(ticks = 1); // required "for the signal handler to function"
      // don't exit on ctrl-c
      pcntl_signal(SIGINT, SIG_IGN, true);

      $this->cancelled = false;

      if (null === ($request = SocketComm::waitForRequest($this->socket))) {
        continue;
      }

      if (method_exists($this, $request->method)) {

        $method   = $request->method;
        $response = $this->$method($request->body, $scope);

        SocketComm::sendResponse($this->socket, $request, $response);

      } else { // No matching ACTION
        throw new \RuntimeException(sprintf("Bad request action", $input[0]));
        $response = array('status' => SocketComm::STATUS_EXITED);
      }

      if (! isset($response['status']) ||
                  $response['status'] === SocketComm::STATUS_EXITED) {
        exit(0);
      }

    } // eo for
  }

  private function complete($input, &$scope)
  {
    $status = SocketComm::STATUS_OK;
    $body   = $this->completer->getCompletions($input->line, true, $scope);
    return compact('status', 'body');
  }

  private function evalAndPrint($input, &$scope)
  {
    $input = $this->transform($input);
    list($status, $result) = $this->forkAndEval($input, $scope);

    if (preg_match('/\s*return\b/i', $input)) {
      fwrite(STDOUT, sprintf("%s\n", $this->inspector->inspect($result)));
    }
    return array('status' => $status);
  }

  private function forkAndEval($input, &$scope)
  {
    $result = NULL;
    $status = SocketComm::STATUS_OK;

    $this->ppid = posix_getpid();
    $this->pid  = pcntl_fork();

    if ($this->pid < 0) {
      throw new \RuntimeException('Failed to fork child labourer');
    } else if ($this->pid > 0) {
      // kill the child on ctrl-c
      pcntl_signal(SIGINT, array($this, 'cancelOperation'), true);
      pcntl_waitpid($this->pid, $pidStatus);

      if (!$this->cancelled && $pidStatus != (self::EXIT_ABNORMAL << 8)) {
        $status = SocketComm::STATUS_EXITED;
      } else {
        $this->runHooks($this->failureHooks);
        $status = SocketComm::STATUS_FAILED;
      }
    } else {
      // exception handlers normally exit, so Boris will exit too
      $oldexh = set_exception_handler(array($this, 'delegateExceptionHandler'));
      if ($oldexh && !$this->userExceptionHandler) {
        $this->userExceptionHandler = $oldexh; // remember it
      } else {
        restore_exception_handler();
      }

      // undo ctrl-c signal handling ready for user code execution
      pcntl_signal(SIGINT, SIG_DFL, true);
      $pid = posix_getpid();

      Debug::log('input', $input);

      $input  = $this->transform($input);
      $result = $this->evalInScope($input, $scope);

      if (posix_getpid() != $pid) {
        // whatever the user entered caused a forked child
        // (totally valid, but we don't want that child to loop and wait for input)
        exit(0);
      }
      $this->expungeOldWorker();
    }
    return array($status, $result);
  }

  /**
   * While a child process is running, terminate it immediately.
   */
  public function cancelOperation() {
    echo "Cancelling...\n";
    $this->cancelled = true;
    posix_kill($this->pid, SIGKILL);
    pcntl_signal_dispatch();
  }

  /**
   * If any user-defined exception handler is present, call it,
   * but be sure to exit correctly.
   */
  public function delegateExceptionHandler($ex) {
    call_user_func($this->userExceptionHandler, $ex);
    exit(self::EXIT_ABNORMAL);
  }

  private function evalInScope($input, &$scope) {
    static $unsetKeys = array(
      'unsetKeys' => 1,
      'input'     => 1,
      'scope'     => 1,
      'result'    => 1,
    );
    extract($scope);
    $result = eval($input);
    while (is_string($result) && stripos($result, 'return ') === 0) {
      $result = eval($result);
    }
    $scope = array_diff_key(get_defined_vars(), $unsetKeys);
    return $result;
  }

  private function runHooks($hooks) {
    extract($this->exports);

    foreach ($hooks as $hook) {
      if (is_string($hook)) {
        eval($hook);
      } elseif (is_callable($hook)) {
        call_user_func($hook, $this, get_defined_vars());
      } else {
        throw new \RuntimeException(
          sprintf(
            'Hooks must be closures or strings of PHP code. Got [%s].',
            gettype($hook)
          )
        );
      }

      // hooks may set locals
      extract($this->exports);
    }

    return get_defined_vars();
  }

  private function expungeOldWorker() {
    posix_kill($this->ppid, SIGTERM);
    pcntl_signal_dispatch();
  }

  private function transform($input) {
    if ($input === null) {
      return null;
    }
    if ($this->macros) {
      foreach ($this->macros as $pattern => $replacer) {
        if (is_string($replacer)) {
          $input = preg_replace($pattern, $replacer, $input);
        } else {
          $input = preg_replace_callback($pattern, $replacer, $input);
        }
      }
    }
    return $input;
  }
}
