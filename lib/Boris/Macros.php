<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

class Macros extends \ArrayIterator {

  const REGEX_USE    = '/^\s*use ([\w\s,_\\\]+?);\s*/i';
  const REGEX_SPLICE = '/(.*?)~@(\$[\w\_]+)(.*)/';

  public function __construct() {
      $input = array();
      $input[self::REGEX_USE]    = $this->useMacro();
      $input[self::REGEX_SPLICE] = $this->spliceMacro();
      parent::__construct($input);
  }

  private function spliceMacro() {
    return function ($matches) {
      list(, $before, $var, $after) = $matches;
      $q_before = "'$before'";
      $q_after  = "'$after'";
      $s = '
          $__xs = implode(\', \', '.$var.');
          $__qs = '.$q_before.' . $__xs . '.$q_after.';
          return eval(\'return $__qs;\');
      ';
      return $s;
    };
  }

  private function useMacro() {
    return function ($match) {
      $stmt = $match[1];
      if (($asPosition = stripos($stmt, ' as ')) !== false) {
        $class   = substr($stmt, 0, $asPosition);
        $aliases = substr($stmt, $asPosition + 4);
        $aliases = explode(',', $aliases);
      } else {
        $class   = $stmt;
        $alias   = substr($class, strrpos($class, '\\') + 1);
        $aliases = array($alias);
      }
      $output = '';
      $class  = trim($class);
      foreach ($aliases as $alias) {
        $output .= sprintf("class_alias('%s', '%s');", $class, trim($alias));
      }
      return $output;
    };
  }
}
