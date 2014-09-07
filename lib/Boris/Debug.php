<?php

namespace Boris;

class Debug
{
	public static function log($name, $var)
	{
		ob_start();
		var_dump($var);
		$out = ob_get_clean();
        file_put_contents('/tmp/dbg', "$name: $out\n", FILE_APPEND);
	}
}
