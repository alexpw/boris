<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris\Autocomplete\Completions;

if (PHP_MINOR_VERSION < 4) {
  require 'Completions.53-';
} else {
  require 'Completions.54+';
}
