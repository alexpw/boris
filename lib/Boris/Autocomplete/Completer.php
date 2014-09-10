<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris\Autocomplete;

if (PHP_MAJOR_VERSION > 3) {
  require "Completions.php";   // traits
} else {
  require "Completions53.php"; // make do
}

use Boris\SocketComm;
use Boris\Debug;

#use Completions as Completions;

/**
 * Performs context-sensitive completion and information lookup.
 */
class Completer
{
  private $evalWorker;
  private $parser;
  private static $sources = array(
    'variable'       => 'Boris\Autocomplete\Completions\Variables',
    'function'       => 'Boris\Autocomplete\Completions\Functions',
    'constant'       => 'Boris\Autocomplete\Completions\Constant',
    'keyword'        => 'Boris\Autocomplete\Completions\Keywords',
    'class'          => 'Boris\Autocomplete\Completions\ClassNames',
    'interface'      => 'Boris\Autocomplete\Completions\Interfaces',
    'trait'          => 'Boris\Autocomplete\Completions\Traits',
    'method'         => 'Boris\Autocomplete\Completions\AllMethods',
    'property'       => 'Boris\Autocomplete\Completions\AllProperties',
    'staticproperty' => 'Boris\Autocomplete\Completions\AllStaticProperties',
    'classconstant'  => 'Boris\Autocomplete\Completions\AllClassConstants',
  );

  public function __construct($evalWorker) {
    if (PHP_MAJOR_VERSION < 4) {
      unset(self::$sources['trait']);
    }
    $this->evalWorker = $evalWorker;
    $this->parser = new Parser();
  }

  /**
   * Return information for tab-completing the end of $input.
   *
   * Returns an array containing keys 'start', 'end', and
   * 'completions'.  'start' and 'end' delimit the bounds of the
   * partial symbol to be completed.  'completions' is an array of
   * possible completions.  If no completions are available, returns
   * null.
   */
  public function getCompletions($input, $evaluate, $scope, $annotate = false) {
    $info = $this->parser->getCompletionInfo($input);
    if($info === null) return null;

    $source = null;
    switch($info->how) {
    case Parser::COMPLETE_MEMBER:
      $context = $this->getLiveContext($info->context, $evaluate, $scope);
      if ($context) {
        $source = new Completions\Members($context);
      }
      break;

    case Parser::COMPLETE_STATIC:
      $context = $this->getLiveContext($info->context, $evaluate, $scope);
      if ($context) {
        $source = new Completions\StaticMembers($context);
      }
      break;

    case Parser::COMPLETE_VARIABLE:
      $source = new Completions\Variables($scope);
      break;

    /* case Parser::COMPLETE_INDEX: */
    /*   $context = $this->getLiveContext($info->context, $evaluate, $scope); */
    /*   $source = new Completions\ArrayIndices($context); */
    /*   break; */

    case Parser::COMPLETE_CLASS:
      $this->stripInitialSlash($info);
      $source = new Completions\ClassConstructors;
      break;

    case Parser::COMPLETE_SYMBOL:
      $this->stripInitialSlash($info);
      $source = new Completions\BareNames;
      break;

    default:
      throw new \RuntimeException(sprintf(
        "Unexpected value %s returned from getCompletionInfo",
        $info->how));
    }
    if (!$source) {
      $source = new Completions\None;
    }

    $completions = $source->completions($info->symbol);
    $strings = array_map(function ($symbol) { return (string) $symbol; }, $completions);
    $response = array('start' => $info->start,
                      'end' => $info->end,
                      'completions' => self::names($completions));

    if ($annotate) {
      $response['annotations'] = self::annotationMap($completions);
    }
    return $response;
  }

  /**
   * Complete a specified kind of symbol
   */
  public function completeSymbol($prefix, $kind, $scope = array(), $annotate = false) {
    $source = $this->getSource($kind);
    $completions = $source->completions($prefix);
    if ($annotate) {
      return self::annotations($completions);
    } else {
      return self::names($completions);
    }
  }

  /**
   * Search all symbols and return full details.
   */
  public function apropos($filter, $scope, $kind = null) {
    $source = $this->getSource($kind);
    return self::annotations($source->apropos($filter));
  }

  private function getSource($kind, $scope = array()) {
    if (!$kind) {
      return new Completions\AllSymbols($scope);
    } elseif (is_array($kind)) {
      return new Completions\MergeSources(
        array_map(array($this, 'getSource'), $kind)
      );
    } elseif (isset(self::$sources[$kind])) {
      $class = self::$sources[$kind];
      return new $class;
    } else {
      user_error("Invalid symbol type '$kind'", E_USER_WARNING);
      return new Completions\None;
    }
  }

  public function whoImplements($interface) {
    assert (is_string($interface));
    $source = new Completions\ClassNames;
    return self::annotations(
      array_filter($source->symbols(), function ($symbol) use ($interface) {
      return $symbol->implementsInterface($interface);
    }));
  }

  public function whoUses($trait) {
    if (PHP_MAJOR_VERSION < 4) {
      return array();
    }
    assert (is_string($trait));
    $source = new Completions\MergeSources(array(
      new Completions\ClassNames,
      //new Completions\Traits,
    ));
    return self::annotations(
      array_filter($source->symbols(), function ($symbol) use ($trait) {
      return $symbol->usesTrait($trait);
    }));
  }

  public function whoExtends($class) {
    assert (is_string($class));
    $source = new Completions\ClassNames;
    return self::annotations(
      array_filter($source->symbols(), function ($symbol) use ($class) {
          return $symbol->extendsClass($class);
        }));
  }

  /**
   * Return an array of each symbol's name.
   */
  private static function names(array $symbols) {
    return array_values(array_map(function ($symbol) {
      return (string) $symbol;
    }, $symbols));
  }

  /**
   * Return an array consisting of each symbol's annotation.
   *
   * TODO: Replace by having symbols implement JSONSerializable
   */
  private static function annotations(array $symbols) {
    return array_values(array_map(function ($symbol) {
      return $symbol->annotate();
    }, $symbols));
  }

  /**
   * Return an associative array mapping symbol names to annotations.
   */
  private static function annotationMap(array $symbols) {
    $map = array();
    foreach ($symbols as $symbol) {
      $map[(string) $symbol] = $symbol->annotate();
    }
    return $map;
  }

  /**
   * Return a single-line description of the arguments for the function,
   * method, or constructor at the end of $line.
   */
  public function getHint($line, $evaluate, $scope) {
    $info = $this->parser->getDocInfo($line);
    if(!$info) return null;
    $refl = $this->getReflectionObject($info, $evaluate, $scope);
    if(!$refl) return null;
    try {
      return $this->formatSignature($refl, $info->arg);
    } catch(\ReflectionException $e) {
      return null;
    }
  }

  /**
   * Return a multi-line string for the class, method, or function at the end of $line.
   */
  public function getDocumentation($line, $evaluate, $scope) {
    $info = $this->parser->getDocInfo($line);
    if(!$info) return null;
    $refl = $this->getReflectionObject($info, $evaluate, $scope);
    if(!$refl) return null;
    try {
      return $refl->__toString();
    } catch(\ReflectionException $e) {
      return null;
    }
  }

  /**
   * Return a single-line help string
   */
  public function getShortDocumentation($line, $evaluate, $scope) {
    $doc = $this->getDocumentation($line, $evaluate, $scope);
    if ($doc) {
      if (preg_match('|/[*][*]\s*\n\s*[*]\s*(.*)$|m', $doc, $matches)) {
        return $matches[1];
      }
    }
    return $this->getHint($line, $evaluate, $scope);
  }

  /**
   * Return function/method location
   */
  public function getLocation($line, $evaluate, $scope) {
    $info = $this->parser->getDocInfo($line);
    if(!$info) return null;
    $refl = $this->getReflectionObject($info, $evaluate, $scope);
    if(!$refl) return null;
    try {
      return array(
        'file' => $refl->getFileName(),
        'line' => $refl->getStartLine()
      );
    } catch(\ReflectionException $e) {
      return null;
    }
  }

  /**
   * Handle an annoying special case with absolutely qualified names
   */
  private function stripInitialSlash(&$info) {
    if(strlen($info->symbol) && $info->symbol[0] == '\\') {
      $info->start += 1;
      $info->symbol = substr($info->symbol, 1);
    }
  }

  /**
   * Given context information from
   * Parser::getCompletionInfo or ::getDocInfo, return
   * either a bare name or a live object for passing to the
   * reflection methods.
   */
  private function getLiveContext($info, $evaluate, $scope) {
    if($info->is_bare) {
      return $info->text;
    } else {
      /* Avoid evaluating nonsense in source buffers */
      if (!$evaluate) return null;
      $input = 'return ' . $info->text . ';';
      list($status, $result) = @$this->evalWorker->forkAndEval($input, $scope);
      Debug::log(__FUNCTION__, compact('input', 'scope', 'status', 'result'));
      if ($status !== SocketComm::STATUS_OK) return null;
      return $result;
    }
  }

  /**********************************************************************
   *
   * Private methods for hint & documentation lookup
   *
   **********************************************************************/

  /**
   * Return a ReflectionFunction or ReflectionMethod object describing
   * the function, method call, or constructor at the end of $line
   */
  private function getReflectionObject($info, $evaluate, $scope) {
    if(!$info) return null;
    try {
      switch($info->how) {
      case Parser::FUNCTION_INFO:
        return new \ReflectionFunction($info->name);
        break;

      case Parser::CLASS_INFO:
        try {
          return new \ReflectionMethod($info->name, '__construct');
        } catch (\ReflectionException $e) {
          return new \ReflectionMethod($info->name, $info->name);
        }
        break;

      case Parser::METHOD_INFO:
        $context = $this->getLiveContext($info->context, $evaluate, $scope);
        return new \ReflectionMethod($context, $info->name);
        break;

      default:
        throw new \RuntimeException(
          sprintf("Unexpected code %s from Parser::getDocInfo",
                  $info[0]));
      }
    } catch (\ReflectionException $e) {
      return null;
    }
  }
}
