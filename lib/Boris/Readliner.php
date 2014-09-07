<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

use Hoa\Console\Readline\Readline as Readline;
use Hoa\Console\Cursor;
use Hoa\Console\Window;

/**
 * Add a few features to Hoa's Readline.
 */
class Readliner extends Readline {

  private $historyFile;

  /**
   * Add key mappings.
   */
  public function __construct()
  {
    parent::__construct();

    $this->addMapping('\C-D', array($this, 'bindEOT'));
    $this->addMapping('\C-L', array($this, 'bindClear'));
    #$this->addMapping('\C-P', array($this, 'bindClear'));
  }

  /**
   * End of Transmission (EOT)
   */
  public function bindEOT($self)
  {
    $this->setLine(false); // mimic readline() - return false for ctrl+d
    return self::STATE_BREAK;
  }

  /**
   * Clear the window, except for the current line.
   */
  public function bindClear($self)
  {
    Cursor::clear('all');
    echo $this->getPrefix() . $this->getLine();
    return self::STATE_NO_ECHO;
  }

  /**
   * Init history.
   *
   * @access  public
   * @return  void
   */
  public function initHistory($file)
  {
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
  public function saveHistory()
  {
    if (is_writeable($this->historyFile)) {
      $hs = array();
      $prev = null;
      foreach ($this->_history as $h) {
        // de-dupe: the user thinks up/down is broken/lagged; it's pointless.
        if ($h !== $prev) {
          $prev = $h;
          $hs[] = $h;
        }
      }
      file_put_contents($this->historyFile, implode("\n", $hs));
    }
  }

  /**
   * Go forward in the history.
   *
   * @access  public
   * @return  string
   */
  public function nextHistory() {

      if ($this->_historyCurrent + 1 >= $this->_historySize) {
          return $this->getLine();
      }

      return $this->getHistory(++$this->_historyCurrent);
  }

  /**
   * Tab binding.
   *
   * @access  public
   * @param   Readline  $self    Self.
   * @return  int
   */
  public function _bindTab(Readline $self)
  {
    $autocompleter = $self->getAutocompleter();
    $state         = static::STATE_CONTINUE | static::STATE_NO_ECHO;

    if (null === $autocompleter) {
      return $state;
    }

    $current = $self->getLineCurrent();
    $line    = $self->getLine();

    // we need at least 1 char to work with
    if (0 === $current) {
      return $state;
    }

    $matches = preg_match_all(
      '#' . $autocompleter->getWordDefinition() . '#u',
      $line,
      $words,
      PREG_OFFSET_CAPTURE
    );

    if (0 === $matches) {
        return $state;
    }

    for ($i = 0, $max = count($words[0]);
         $i < $max && $current > $words[0][$i][1];
         ++$i) {
    }
    $word = $words[0][$i - 1];

    if ('' === trim($word[0])) {
        return $state;
    }

    $prefix   = mb_substr($word[0], 0, $current - $word[1]);
    Debug::log('prefix', compact('line','words','word','prefix'));

    $solution = $autocompleter->complete($prefix);

    if (null === $solution || empty($solution)) {
        return $state;
    }
    $length   = mb_strlen($prefix);

    if (is_array($solution)) {
      if (count($solution) === 1) {
        $tail     = mb_substr($line, $current);
        $current -= $length;

        $line = mb_substr($line, 0, $current) . $solution[0] . $tail;
        $self->setLine($line);
        $self->setLineCurrent($current + mb_strlen($solution[0]));
        $self->setBuffer($line);

        Cursor::move('left', $length);
        echo $solution[0];
        Cursor::clear('right');
        echo $tail;
        Cursor::move('left', mb_strlen($tail));

        Debug::log('completion', compact('prefix','line','current','tail','length'));
        return $state;
      }

      $_solution = $solution;
      $count     = count($_solution) - 1;
      $cWidth    = 0;
      $window    = Window::getSize();
      $wWidth    = $window['x'];
      $cursor    = Cursor::getPosition();

      array_walk($_solution, function ( &$value ) use ( &$cWidth ) {
          $handle = mb_strlen($value);
          if ($handle > $cWidth) {
              $cWidth = $handle;
          }
      });
      array_walk($_solution, function (&$value) use (&$cWidth) {
          $handle = mb_strlen($value);
          if ($handle >= $cWidth) {
              return;
          }
          $value .= str_repeat(' ', $cWidth - $handle);
      });

      $mColumns = (int) floor($wWidth / ($cWidth + 2));
      $mLines   = (int) ceil(($count + 1) / $mColumns);
      --$mColumns;

      if (0 > $window['y'] - $cursor['y'] - $mLines) {

          Window::scroll('up', $mLines);
          Cursor::move('up', $mLines);
      }

      Cursor::save();
      Cursor::hide();
      Cursor::move('down LEFT');
      Cursor::clear('down');

      $i = 0;
      foreach ($_solution as $j => $s) {
          echo "\033[0m", $s, "\033[0m";
          if ($i++ < $mColumns) {
              echo '  ';
          } else {
              $i = 0;
              if (isset($_solution[$j + 1])) {
                  echo "\n";
              }
          }
      }

      Cursor::restore();
      Cursor::show();

      ++$mColumns;
      $read     = array(STDIN);
      $mColumn  = -1;
      $mLine    = -1;
      $coord    = -1;
      $unselect = function ( ) use ( &$mColumn, &$mLine, &$coord,
                                     &$_solution, &$cWidth ) {

          Cursor::save();
          Cursor::hide();
          Cursor::move('down LEFT');
          Cursor::move('right', $mColumn * ($cWidth + 2));
          Cursor::move('down', $mLine);
          echo "\033[0m" . $_solution[$coord] . "\033[0m";
          Cursor::restore();
          Cursor::show();

          return;
      };
      $select = function () use (&$mColumn, &$mLine, &$coord,
                                 &$_solution, &$cWidth) {
          Cursor::save();
          Cursor::hide();
          Cursor::move('down LEFT');
          Cursor::move('right', $mColumn * ($cWidth + 2));
          Cursor::move('down', $mLine);
          echo "\033[7m" . $_solution[$coord] . "\033[0m";
          Cursor::restore();
          Cursor::show();
      };

      $init = function () use (&$mColumn, &$mLine, &$coord, &$select) {

          $mColumn = 0;
          $mLine   = 0;
          $coord   = 0;
          $select();
      };

      while (true) {
          @stream_select($read, $write, $except, 30, 0);

          if (empty($read)) {
              $read = array(STDIN);
              continue;
          }

          switch ($char = $self->_read()) {

              case "\033[A":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = max(0, $coord - $mColumns);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\033[B":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = min($count, $coord + $mColumns);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\t":
              case "\033[C":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = min($count, $coord + 1);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\033[D":
                  if (-1 === $mColumn && -1 === $mLine) {
                      $init();
                      break;
                  }
                  $unselect();
                  $coord   = max(0, $coord - 1);
                  $mLine   = (int) floor($coord / $mColumns);
                  $mColumn = $coord % $mColumns;
                  $select();
                break;

              case "\n":
                  if (-1 !== $mColumn && -1 !== $mLine) {

                      $tail     = mb_substr($line, $current);
                      $current -= $length;
                      $self->setLine(
                          mb_substr($line, 0, $current) .
                          $solution[$coord] .
                          $tail
                      );
                      $self->setLineCurrent(
                          $current + mb_strlen($solution[$coord])
                      );

                      Cursor::move('left', $length);
                      echo $solution[$coord];
                      Cursor::clear('right');
                      echo $tail;
                      Cursor::move('left', mb_strlen($tail));
                  }

              default:
                  $mColumn = -1;
                  $mLine   = -1;
                  $coord   = -1;
                  Cursor::save();
                  Cursor::move('down LEFT');
                  Cursor::clear('down');
                  Cursor::restore();

                  if("\033" !== $char && "\n" !== $char) {

                      $self->setBuffer($char);

                      return $self->_readLine($char);
                  }

                  break 2;
          }
      }

        return $state;
    }

    $tail     = mb_substr($line, $current);
    $current -= $length;
    $self->setLine(
        mb_substr($line, 0, $current) .
        $solution .
        $tail
    );
    $self->setLineCurrent(
        $current + mb_strlen($solution)
    );

    Cursor::move('left', $length);
    echo $solution;
    Cursor::clear('right');
    echo $tail;
    Cursor::move('left', mb_strlen($tail));

    return $state;
  }
}
