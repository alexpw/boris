<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

/**
 * The Readline client is what the user spends their time entering text into.
 *
 * Input is collected and sent to {@link \Boris\EvalWorker} for processing.
 */
class Reader
{
  private $_socket;
  private $_reader;

  /**
   * Create a new ReadlineClient using $socket for communication.
   *
   * @param resource $socket
   */
  public function __construct($socket) {
    $this->_socket = $socket;
    $this->_reader = new BorisReadline;
    #$this->_reader->setAutocompleter(new AutoWord(
    #  get_defined_functions()['internal']
    #));
  }

  public function __destruct() {
    if ($this->_socket) {
      fclose($this->_socket);
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
  public function start($prompt, $historyFile) {
    $this->_reader->initHistory($historyFile);
    #readline_completion_function(array($this, 'completion_function'));

    declare(ticks = 1);
    pcntl_signal(SIGCHLD, SIG_IGN);
    pcntl_signal(SIGINT, array($this->_reader, 'onAbort'), true); // ctrl+c

    // wait for the worker to finish executing hooks
    if (fread($this->_socket, 1) != EvalWorker::READY) {
      throw new \RuntimeException('EvalWorker failed to start');
    }

    $parser = new ShallowParser();
    $buf    = '';
    $lineno = 1;

    for (;;) {
      $prompter = sprintf(
        '[%d] %s',
        $lineno,
        ($buf == ''
          ? $prompt
          : str_pad('*> ', strlen($prompt), ' ', STR_PAD_LEFT))
      );
      $line = $this->_reader->readLine($prompter);
      #ob_start();
      #var_dump($line);
      #$dump = ob_get_clean();
      #file_put_contents('/tmp/dbg', "LINE: $dump\n", FILE_APPEND);

      if (false === $line) {// || substr(trim($line), 0, 4) === 'exit') {
        $buf = 'exit(0);'; // ctrl-d acts like exit
      }

      $buf .= "$line\n";

      if ($statements = $parser->statements($buf)) {
        ++$lineno;

        file_put_contents('/tmp/dbg', var_export(['rline' => $line, 'statements' => $statements], true).PHP_EOL, FILE_APPEND);
        $buf = '';
        foreach ($statements as $stmt) {
          if (false === ($written = fwrite($this->_socket,
                                            EvalWorker::EVALUATE . $stmt))) {
            throw new \RuntimeException('Socket error: failed to write data');
          }

          if ($written > 0) {
            $status = fread($this->_socket, 1);
            if ($status == EvalWorker::EXITED) {
              $this->_reader->saveHistory();
              echo "\n";
              exit(0);
            } elseif ($status == EvalWorker::FAILED) {
              break;
            }
          }
        }
      }

    } // eo for
  }

  /**
   * Callback to perform readline completion.
   *
   * Sends a message over the socket to the EvalWorker requesting a
   * list of completions, in order to complete on functions &
   * variables in the REPL scope.
   */
  public function completion_function($word) {
    $rl_info = readline_info();
    $line    = substr($rl_info['line_buffer'], 0, $rl_info['point']);

    /* HACK. Ugh. */
    if (false !== ($pos = strpos($word, '['))) {
      $prefix = substr($word, 0, $pos + 1);
    } else if (false !== ($pos = strpos($word, '::'))) {
      $prefix = substr($word, 0, $pos + 2);
    #} else if (false !== ($pos = strpos($word, '\\'))) {
    #  $prefix =
    #} else if (0 === stripos($line, 'use')) {
    #  $prefi =
    } else {
      $prefix = '';
    }

    #print("\nbuffer={$rl_info['line_buffer']}\ncompleting=$word\nprefix=$prefix\n");
    /* Call the EvalWorker to perform completion */
    #$send = json_encode(
    $this->_write($this->_socket, EvalWorker::COMPLETE . $line);
    $completions = $this->_read_unserialize();

    #file_put_contents('/tmp/dbg', var_export(get_defined_vars(), true).PHP_EOL, FILE_APPEND);
    /* HACK */
    if ($prefix) {
      $completions = array_map(
        function ($str) use ($prefix) {
          return $prefix . $str;
        },
        $completions
      );
    }
    return $completions;
  }

  /* TODO: refactor me */
  public function _write($socket, $data) {
    $total = strlen($data);
    for ($written = 0; $written < $total; $written += $wrote) {
      $wrote = fwrite($socket, substr($data, $written));
      if ($wrote === false) {
        throw new \RuntimeException(
          sprintf('Socket error: wrote only %d of %d bytes.',
                  $written, $total));
      }
    }
    return $written;
  }

  public function _read($socket, $bytes) {
    for ($read = ''; strlen($read) < $bytes; $read .= $fread) {
      $fread = fread($socket, $bytes - strlen($read));
    }
    return $read;
  }

  public function _read_unserialize() {
    /* Get response: expected to be one-byte opcode,
     * EvalWorker::RESPONSE, four bytes giving length of message,
     * and serialized data */
    $status = $this->_read($this->_socket, 1);
    if ($status !== EvalWorker::RESPONSE) {
      throw new \RuntimeException(sprintf('Bad response: 0x%x',
                                          ord($status)));
    }
    $length_packed   = $this->_read($this->_socket, 4);
    $length_unpacked = unpack('N', $length_packed);
    $length          = $length_unpacked[1];
    $serialized      = $this->_read($this->_socket, $length);
    return json_decode($serialized, true);
  }
}
