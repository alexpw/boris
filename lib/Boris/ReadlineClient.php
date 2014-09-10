<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

use Hoa\Console\Cursor;
use Hoa\Console\Readline\Autocompleter as AC;

/**
 * The Readline client is what the user spends their time entering text into.
 *
 * Input is collected and sent to {@link \Boris\SocketComm} for processing.
 */
class ReadlineClient
{
  private $socket;
  private $reader;

  /**
   * Create a new ReadlineClient using $socket for communication.
   *
   * @param resource $socket
   */
  public function __construct($socket)
  {
    $this->socket = $socket;
    $this->reader = new Readliner;
    $this->reader->setAutocompleter(
      new Autocomplete\Callback(array($this, 'completionCallback'))
    );
    /*
    $functions = get_defined_functions();
    $internal  = $functions['internal'];
    sort($internal);
    $this->reader->setAutocompleter(
        new AC\Aggregate(array(
            new AC\Path('/Volumes/CODE/admgmt.answers.com/htdocs/laravel'),
            new AC\Path('/tmp'),
            new AC\Word($internal),
        ))
    );
    */
  }

  public function __destruct()
  {
    if ($this->socket) {
      fclose($this->socket);
    }
  }

  /**
   * Start the client with an prompt and readline history path.
   *
   * This method never returns.
   *
   * @param string $prompt
   * @param string $historyFile
   */
  public function start($prompt, $historyFile)
  {
    $this->reader->initHistory($historyFile);

    declare(ticks = 1); // required "for the signal handler to function"
    pcntl_signal(SIGCHLD, SIG_IGN);
    pcntl_signal(SIGINT, array($this, 'clear'), true); // ctrl+c

    // wait for the worker to finish executing hooks
    if (fread($this->socket, 1) != SocketComm::SIGNAL_READY) {
      throw new \RuntimeException('EvalWorker failed to start');
    }

    $parser = new ShallowParser();
    $buf    = '';
    $lineno = 1;

    // remove terminal color codes
    $cleanPrompt = $prompt;
    $cleanPrompt = preg_replace('/\\033\[\d;\d+m/', '', $cleanPrompt);
    $cleanPrompt = preg_replace('/\\033\[0m/',      '', $cleanPrompt);

    $promptMore = str_pad('*> ', mb_strlen($cleanPrompt), ' ', STR_PAD_LEFT);
    $promptMore = str_replace('*', "\033[0;31m*\033[0m", $promptMore);

    for (;;) {

      $prompter     = sprintf('[%d] %s', $lineno, $prompt);
      $prompterMore = sprintf('[%d] %s', $lineno, $promptMore);

      $line = $this->reader->readLine(
        ($buf ? $prompterMore : $prompter), $prompterMore
      );

      $ctrlD = ($line === false);
      // we want both of these to act like exit(0):
      // - readline return false on ctrl-d
      // - user shortcut 'exit'... (must be done here, b/c $parser waits for ';'
      if ($ctrlD || trim($line) === 'exit') {
        $line = 'exit(0);';
        $buf = '';
      }

      $buf .= "$line\n";

      if ($statements = $parser->statements($buf)) {
        ++$lineno;

        $buf = '';
        foreach ($statements as $stmt) {
          // Add complete stmts to the history, instead of just 1 line.
          $this->reader->addHistory($stmt);

          $request = array('method' => 'evalAndPrint', 'body' => $stmt);
          $written = SocketComm::sendRequest($this->socket, $request);

          if ($written === false) {
            throw new \RuntimeException('Socket error: failed to write data');
          } else if ($written > 0) {
            $response = SocketComm::readResponse($this->socket);

            if (! is_object($response)) {
                $this->reader->saveHistory();
                echo "Exiting: corrupted response, request was \n";
                var_export($request);
                echo "\n";
                exit(255);
            } else {
              switch ($response->status) {
                case SocketComm::STATUS_OK:     break;
                case SocketComm::STATUS_FAILED: break 2;
                case SocketComm::STATUS_EXITED:
                  $this->reader->saveHistory(array('exit','exit;','exit(0'));
                  if ($ctrlD) {
                    echo "\n";
                  }
                  exit(0);
              }
            }
          }
        }
      }

    } // eo for
  }

  /**
   * ctrl+c like behavior, used by Reader
   * @param int $code SIGINT = 2 on osx
   */
  public function clear($code)
  {
    /**
     * ctrl+c cases:
     *  "Cancelling...":
     *    - $line is not empty, $buf is empty
     *    - We don't draw the prompt, b/c we're past the EVALUATE stage.
     *  User typed something, but hasn't hit enter to evaluate:
     *    - $line and $buf are not empty
     *    - We need to redraw the prompt
     *  User hasn't typed anything:
     *    - $line and $buf are empty
     *    - We need to redraw the prompt
     */
    $line = $this->reader->getLine();
    $buf  = $this->reader->getBuffer();
    if (empty($line) || trim($buf) !== '') {
        Cursor::clear('line');
        echo $this->reader->getPrefix();
        $this->reader->setLine(null);
    }
  }

  /**
   * Callback to perform readline completion.
   *
   * Sends a message over the socket to the EvalWorker requesting a
   * list of completions, in order to complete on functions &
   * variables in the REPL scope.
   */
  public function completionCallback($prefix)
  {
    $line = $this->reader->getLine();
    if (empty($line)) {
      return array();
    }
    /* get the word within the line, being completed */
    $current  = $this->reader->getLineCurrent();
    $fragment = substr($line, 0, $current);
    if (($pos = strrpos($line, ' ')) !== false) {
      $pos++;
      $word = substr($line, $pos + 1, ($current - $pos));
    } else {
      $word = $line;
    }
    Debug::log(__FUNCTION__, compact('prefix', 'line', 'current', 'fragment', 'word'));
    $line = $fragment;

    /* Call the EvalWorker to perform completion */
    $request = array(
      'method'   => 'complete',
      'body'     => compact('line', 'current', 'word'),
    );
    SocketComm::sendRequest($this->socket, $request);

    $response = SocketComm::readResponse($this->socket);
    if (! $response || $response->status !== SocketComm::STATUS_OK) {
       return array($word);
    }
    #list($start, $end, $completions) = array($response->start, $response->end,
    #                                         $response->completions);

    /* PHP's readline extension is not very configurable and tends
     * to pick the wrong boundaries for the symbol to complete.  Fix
     * up the returned completions accordingly. */
    /*
    $rl_start = $rl_info['point'] - strlen($word);
    $rl_end = $rl_info['point'];
    if(!$completions) return array($word);
    if($start < $rl_start) {
      foreach($completions as &$c) {
        $c = substr($c, $rl_start - $start);
      }
    } elseif($start > $rl_start) {
      foreach($completions as &$c) {
        $c = substr($line, $rl_start, $start - $rl_start) . $c;
      }
    }
    */
    if (! empty($response->body)) {
      return $response->body->completions;
    }
    return array();
  }
}
