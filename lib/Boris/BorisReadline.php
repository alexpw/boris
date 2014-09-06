<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;


from('Hoa')

/**
 * \Hoa\Console
 */
-> import('Console.~');

use Hoa\Console\Cursor;
use Hoa\Console\Readline\Readline as HoaReadline;
use Hoa\Console\Readline\Autocompleter\Word as AutoWord;

/**
 * The Readline client is what the user spends their time entering text into.
 *
 * Input is collected and sent to {@link \Boris\EvalWorker} for processing.
 */
class BorisReadline extends HoaReadline
{
  private $historyFile;

  /**
   * Create a new ReadlineClient using $socket for communication.
   *
   * @param resource $socket
   */
  public function __construct()
  {
    parent::__construct();

    // ctrl+d
    $this->addMapping('\C-D', array($this, 'onEOT'));
  }

  // ctrl+c like behavior, used by Reader
  public function onAbort($self = null) {
    Cursor::clear('line');
    echo $this->getPrefix();
    $this->resetLine();
    $this->setBuffer('');
    return self::STATE_CONTINUE;
  }

  // End of Transmission (EOT)
  public function onEOT($self = null) {
    $this->setLine(false); // mimic readline() - return false for ctrl+d
    $this->setBuffer(false);
    return self::STATE_BREAK;
  }

  /**
   * Init history.
   *
   * @access  public
   * @return  void
   */
  public function initHistory($file) {
      if (is_readable($file)) {
        $this->historyFile = $file;
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $size  = count($lines);
        $this->_history        = $lines;
        $this->_historyCurrent = $size - 1;
        $this->_historySize    = $size;
      }
  }
  /**
   * Save history.
   *
   * @access  public
   * @return  void
   */
  public function saveHistory () {
      if (is_writeable($this->historyFile)) {
        file_put_contents($this->historyFile, implode("\n", $this->_history));
      }
  }
}
