<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

use Hoa\Console\Cursor;
use Hoa\Console\Readline\Autocompleter as AC;

/**
 * The Readline client is what the user spends their time entering text into.
 *
 * Input is collected and sent to {@link \Boris\EvalWorker} for processing.
 */
class ReadlineClient
{
  private $socket;
  private $reader;
  private $buf;
  private $prompter;
  private $prompterMore;

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
    #$this->reader->addMapping("\033[1;9A", [$this, 'bindAltUp']);
    #$this->reader->addMapping("\033[1;9B", [$this, 'bindAltDown']);
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
    pcntl_signal(SIGINT, array($this, 'bindClearLine'), true); // ctrl+c

    // wait for the worker to finish executing hooks
    if (fread($this->socket, 1) != SocketComm::SIGNAL_READY) {
      throw new \RuntimeException('EvalWorker failed to start');
    }

    $parser    = new ShallowParser();
    $lineno    = 1;
    $this->buf = '';

    // remove terminal color codes
    $cleanPrompt = $prompt;
    $cleanPrompt = preg_replace('/\\033\[\d;\d+m/', '', $cleanPrompt);
    $cleanPrompt = preg_replace('/\\033\[0m/',      '', $cleanPrompt);

    $promptMore = str_pad('*> ', mb_strlen($cleanPrompt), ' ', STR_PAD_LEFT);
    $promptMore = str_replace('*', "\033[0;31m*\033[0m", $promptMore);

    for (;;) {
      if (! $this->socket) {
        fwrite(STDERR, "Socket has gone away\n");
        exit(self::EXIT_ABNORMAL);
      }

      $this->prompter     = sprintf('[%d] %s', $lineno, $prompt);
      $this->prompterMore = sprintf('[%d] %s', $lineno, $promptMore);

      $line = $this->reader->readLine(
        ($this->buf ? $this->prompterMore : $this->prompter),
        $this->prompterMore
      );

      $ctrlD = ($line === false);
      // we want both of these to act like exit(0):
      // - readline return false on ctrl-d
      // - user shortcut 'exit'... (must be done here, b/c $parser waits for ';'
      if ($ctrlD || trim($line) === 'exit') {
        $line = 'exit(0);';
        $this->buf = '';
      }

      $this->buf .= "$line\n";

      if ($statements = $parser->statements($this->buf)) {
        ++$lineno;

        $this->buf = '';
        foreach ($statements as $stmt) {
          // Add complete stmts to the history, instead of just 1 line.
          $this->reader->addHistory($stmt);

          $request = array('method' => 'evalAndPrint', 'body' => $stmt);
          $written = SocketComm::sendRequest($this->socket, $request);

          if ($written === false) {
            throw new \RuntimeException('Socket error: failed to write data');
          } else {
            $response = SocketComm::readResponse($this->socket);

            if (! is_object($response)) {
                $this->reader->saveHistory();
                echo "Exiting: corrupted response, request was \n";
                var_export($request);
                echo "\n";
            } else {
              switch ($response->status) {
                case SocketComm::STATUS_OK:     break;
                case SocketComm::STATUS_FAILED: break 2;
                case SocketComm::STATUS_EXITED:
                  $this->reader->saveHistory(array('exit;','exit(0);'));
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
   * Clear the current line/buffer.
   * ctrl+c like behavior, used by Reader
   * @param int|object $code SIGINT = 2 on osx
   */
  public function bindClearLine($code = null)
  {
    $line    = $this->reader->getLine();
    $buffer  = trim($this->reader->getBuffer());

    // ctrl+c = Cancelling...
    if (! empty($line) && $buffer === '') {
      Cursor::clear('line');
    } else {
    // User is viewing their history, else typed something
      if (empty($this->buf)) {
        $mLines = substr_count($buffer, "\n");
      } else {
        $mLines = substr_count($this->buf, "\n");
      }
    // Redraw the prompt, by erasing the existing buffered lines
      Cursor::move('up', $mLines);
      Cursor::clear('line');
      Cursor::clear('down', $mLines);
      $this->buf = '';
      echo $this->prompter;
    }
    $this->reader->setLine(null);
    $this->reader->setBuffer('');
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
    #Debug::log(__FUNCTION__, compact('prefix', 'line', 'current', 'fragment', 'word'));
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

    if (! empty($response->body)) {
      return $response->body->completions;
    }
    return array();
  }
}
