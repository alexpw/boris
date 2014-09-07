<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris\Inspector;

/**
 * Passes values through var_dump() to inspect them.
 */
class Dump implements Inspector
{
  public function inspect($variable)
  {
    ob_start();
    var_dump($variable);
    return sprintf(" → %s", trim(ob_get_clean()));
  }
}
