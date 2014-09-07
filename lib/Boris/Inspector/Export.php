<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris\Inspector;

/**
 * Passes values through var_export() to inspect them.
 */
class Export implements Inspector
{
  public function inspect($variable)
  {
    return sprintf(" → %s", var_export($variable, true));
  }
}
