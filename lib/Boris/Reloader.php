<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

final class Reloader
{
  public static function isReloadable($str)
  {
    return extension_loaded('runkit') &&
           preg_match('/^\s*(function|class)/i', $str) === 1;
  }

  public static function fromFile($file)
  {
    // RUNKIT_IMPORT_OVERRIDE    | RUNKIT_IMPORT_FUNCTIONS     |
    // RUNKIT_IMPORT_CLASSES     | RUNKIT_IMPORT_CLASS_METHODS |
    // RUNKIT_IMPORT_CLASS_PROPS | RUNKIT_IMPORT_CLASS_CONSTS
    // => 4127
    static $flags = 4127;
    return runkit_import($file, $flags);
  }

  public static function fromString($str)
  {
    $local_path = self::fileFromStr($str);
    $success = self::fromFile($local_path);
    unlink($local_path);
    return $success;
  }

  private static function fileFromStr($str, $ext = '.php')
  {
    $local_path = tempnam(sys_get_temp_dir(), __FUNCTION__ . '.') . $ext;
    file_put_contents($local_path, '<?php '.$str);
    return $local_path;
  }
}
