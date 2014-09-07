<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris;

class SocketComm
{
  const SIGNAL_READY  = "\0";
  const STATUS_OK     = 'ok';
  const STATUS_EXITED = 'exited';
  const STATUS_FAILED = 'failed';

  public static function sendRequest($socket, $request)
  {
    $request['type'] = 'request';
    Debug::log('sendRequest', $request);
    return self::writeMessage($socket, $request);
  }

  public static function sendResponse($socket, $request, $response)
  {
    $response['type']   = 'response';
    $response['method'] = $request->method;
    Debug::log('sendResponse', $response);
    return self::writeMessage($socket, $response);
  }

  public static function readResponse($socket) {
    return self::unpackSocket($socket);
  }

  public static function waitForRequest($socket)
  {
    if ($frame = self::readStream($socket)) {
      return self::unpackFrame($frame);
    }
  }

  public static function read($socket, $bytes)
  {
    for ($read = ''; strlen($read) < $bytes; $read .= $fread) {
      $fread = @fread($socket, $bytes - strlen($read));
    }
    return $read;
  }

  public static function writeMessage($socket, $msg)
  {
    $frame = self::packMessage($msg);
    return self::write($socket, $frame);
  }

  public static function write($socket, $data)
  {
    $total = strlen($data);
    for ($written = 0; $written < $total; $written += $fwrite) {
      $fwrite = @fwrite($socket, substr($data, $written));
      if ($fwrite === false) {
        throw new \RuntimeException(
          sprintf('Socket error: wrote %d of %d bytes.', $written, $total));
      }
    }
    return $written;
  }

  private static function packMessage($msg)
  {
    $serialized = json_encode($msg);
    $frame      = pack('N', strlen($serialized)) . $serialized;
    return $frame;
  }

  private static function unpackFrame($frame)
  {
    $length_packed   = substr($frame, 0, 4);
    $length_unpacked = unpack('N', $length_packed);
    $json_msg = substr($frame, 4, $length_unpacked[1]);
    return json_decode($json_msg);
  }

  private static function unpackSocket($socket)
  {
    $length_packed   = fread($socket, 4);
    $length_unpacked = unpack('N', $length_packed);
    $json_msg        = self::read($socket, $length_unpacked[1]);
    return json_decode($json_msg);
  }

  public static function readStream($socket)
  {
    $read   = array($socket);
    $except = array($socket);

    if (self::selectStream($read, $except) > 0) {
      if ($read) {
        return stream_get_contents($read[0]);
      } else if ($except) {
        throw new \UnexpectedValueException("Socket error: closed");
      }
    }
  }

  private static function selectStream(&$read, &$except)
  {
    static $alwaysTrue = null;
    if ($alwaysTrue === null) {
      $alwaysTrue = function () { return true; };
    }
    $write = null;
    set_error_handler($alwaysTrue, E_WARNING);
    $result = stream_select($read, $write, $except, 10);
    restore_error_handler();
    return $result;
  }
}
