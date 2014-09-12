<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris\Autocomplete\Completions;

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
if (PHP_MINOR_VERSION < 4) {
  require $path . 'Completions53-.php';
} else {
  require $path . 'Completions54+.php';
}
