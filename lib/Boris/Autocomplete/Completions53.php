<?php

/* vim: set shiftwidth=2 expandtab softtabstop=2: */

namespace Boris\Autocomplete\Completions;

/**
 * traits to reproduce
 *  + Apropos
 *  + CaseInsensitive
 *  + CaseSensitive
 *
 *  + SymbolSource
 *
 *  + ReflectionObject
 *  + ReflectionObjectMembers
 *  + ReflectionObjectProperties
 *
 *  + PublicVisibility
 *  + StaticVisibility
 *
 *  + ForAllClasses
 *
 *  + AnnotateBasic
 *  + AnnotateLocation
 *  + AnnotateDocstring
 *  + AnnotateDeclaringClass
 *  + AnnotateSignature
 *  + AnnotateClass
 *
 *  + PlainSymbol
 *  + DollarSymbol
 *  + SimpleSymbol
 *  + ContextSymbol
 *
 *  + AnnotateMethod
 *  + AnnotateProperty
 *
 * classes to stub
 *  + Traits
 *  + TraitName
 *
 * mods
 *  + remove trait references, 'trait', '__TRAIT__'
 *  + remove Completor's use of Completions trait support
 */


/**
 * A completion source defines how to look up a kind of PHP symbol.
 *
 * Concrete implementations of this interface exist for globals,
 * variables, functions, classes, object members, etc.
 *
 * Completion sources return Symbols, which can be converted to
 * strings or queried for further information using annotate()
 */
interface Source {
  public function symbols();
  public function completions($prefix);
  public function apropos($query);
}

interface Symbol {
  public function kind();
  public function annotate();
}

/*************************************************************
 *
 * Merged completion sources for particular contexts
 *
 *************************************************************/

/**
 * Merge several sources together
 */
class MergeSources implements Source {
  private $sources;

  public function __construct($sources) {
    foreach ($sources as $source) {
      assert ($source instanceof Source);
    }
    $this->sources = $sources;
  }

  public function symbols() {
    return $this->merge(
        function ($source) {
          return $source->symbols();
        }
    );
  }

  public function completions($prefix) {
    return $this->merge(
        function ($source) use ($prefix) {
          return $source->completions($prefix);
        }
    );
  }

  public function apropos($query) {
    return $this->merge(
      function ($source) use ($query) {
        return $source->apropos($query);
      }
    );
  }

  private function merge($function) {
    return call_user_func_array(
      'array_merge',
      array_map($function, $this->sources)
    );
  }
}

/**
 * Completion source for all bare symbols
 */
class BareNames extends MergeSources {
  public function __construct() {
    parent::__construct(array(
      new Keywords(),
      new Constants(),
      new Functions(),
      new ClassNames(),
      new Interfaces(),
    ));
  }
}

/**
 * Completion and info source for object members accessible after "->"
 */
class Members extends MergeSources {
  public function __construct($context) {
    parent::__construct(array(
      new Methods($context),
      new Properties($context),
    ));
  }
}

/**
 * Completion source for class/object members accessible after "::".
 */
class StaticMembers extends MergeSources {
  public function __construct($context) {
    parent::__construct(array(
      new StaticMethods($context),
      new StaticProperties($context),
      new ClassConstants($context),
    ));
  }
}

/************************************************************
 *
 * Case-sensitive and -insensitive completion
 *
 ************************************************************/


/**
 * Apropos
 */
function apropos($filter, $candidates) {
  if (empty($filter)) {
    return $candidates;
  }

  if (is_string($filter)) {
    $preg = '/' . $filter . '/i';
    $predicate = function ($symbol) use ($preg) {
      return preg_match($preg, (string) $symbol);
    };
  } elseif (is_array($filter)) {
    $regexps = array_map(function ($word) {
      return '/' . $word . '/i';
    }, $filter);
    $predicate = function ($symbol) use ($regexps) {
      $string = (string) $symbol;
      foreach ($regexps as $regexp) {
        if (!preg_match($regexp, $string)) return false;
      }
      return true;
    };
  } else {
    user_error('Apropos filter should be string or array', E_USER_WARNING);
    return array();
  }

  return array_filter($candidates, $predicate);
}

/**
 * Case insensitive
 */
function insensitiveCompletions($prefix, $symbols) {
  if (strlen($prefix) == 0) {
    return $symbols;
  } else {
    $prefix_lower = strtolower($prefix);
    return array_filter($symbols, function ($symbol) use ($prefix_lower) {
      return (strpos(strtolower($symbol), $prefix_lower) === 0);
    });
  }
}

/**
 * Case sensitive
 */
function sensitiveCompletions($prefix, $symbols) {
  if (strlen($prefix) == 0) {
    return $symbols;
  } else {
    return array_filter($symbols, function ($symbol) use ($prefix) {
      return (strpos($symbol, $prefix) === 0);
    });
  }
}

/**
 * Trait: return a specified type of Symbol for each name
 */
abstract class SymbolSource {
  abstract protected function names();
  abstract protected function symbol($name);

  public function symbols() {
    return array_map(array($this, 'symbol'), $this->names());
  }

  public function apropos($prefix) {
    return apropos($prefix, $this->completions($prefix));
  }
}

/************************************************************
 *
 * Globally-defined completions
 *
 ************************************************************/

/**
 * Dummy source with no completions
 */
class None implements Source {
  public function symbols() {
    return array();
  }

  public function completions($prefix) {
    return array();
  }

  public function apropos($query) {
    return array();
  }
}

/**
 * Variables.
 */
class Variables extends SymbolSource implements Source {
  private $scope;
  public function __construct(array $scope) {
    $this->scope = $scope;
  }

  protected function names() {
    return array_merge(
      array_keys($this->scope),
      array_keys($GLOBALS)
    );
  }

  protected function symbol($name) {
    return new Variable($name);
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Functions.
 */
class Functions extends SymbolSource implements Source {

  protected function names() {
    $funcs = get_defined_functions();
    return array_merge($funcs['internal'], $funcs['user']);
  }

  protected function symbol($name) {
    return new FunctionName($name);
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Global constants.
 */
class Constants extends SymbolSource implements Source {

  function names() {
    return array_keys(get_defined_constants());
  }

  function symbol($name) {
    return new Constant($name);
  }

  public function completions($prefix) {
    return sensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Built-in keywords
 *
 * List taken from http://php.net/manual/en/reserved.keywords.php
 */
class Keywords extends SymbolSource implements Source {

  static $keywords = array(
    '__halt_compiler',
    'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
    'class', 'clone', 'const', 'continue', 'declare', 'default', 'die',
    'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach',
    'endif', 'endswitch', 'endwhile', 'eval', 'exit', 'extends',
    'final', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
    'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset',
    'list', 'namespace', 'new', 'or', 'print', 'private', 'protected', 'public',
    'require', 'require_once', 'return', 'static', 'switch', 'throw',
    'try', 'unset', 'use', 'var', 'while', 'xor',
    '__CLASS__', '__DIR__', '__FILE__', '__FUNCTION__', '__LINE__', '__METHOD__',
    '__NAMESPACE__'
  );

  protected function names() {
    return self::$keywords;
  }

  protected function symbol($name) {
    return new Keyword($name);
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Class names used as bare symbols
 */
class ClassNames extends SymbolSource implements Source {

  function names() {
    return get_declared_classes();
  }

  function symbol($name) {
    return new ClassName($name);
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Class names used as constructors.
 */
class ClassConstructors extends ClassNames {
  function symbol($name) {
    return new ClassConstructor($name);
  }
}

/**
 * Interfaces
 */
class Interfaces extends SymbolSource implements Source {

  protected function names() {
    return get_declared_interfaces();
  }

  protected function symbol($name) {
    return new InterfaceName($name);
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Traits
 */
class Traits extends SymbolSource implements Source {

  protected function names() {
    return array();
  }

  protected function symbol($name) {
    return new TraitName($name);
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}


/************************************************************
 *
 * Completion of object/class members.
 *
 ************************************************************/

/**
 * Trait: use reflection on object or class members
 */
class ReflectObjectMembers extends SymbolSource {
  protected $refl;
  public function __construct($context) {
    try {
      if (is_object($context)) {
        $this->refl = new \ReflectionObject($context);
      } elseif (is_string($context)) {
        $this->refl = new \ReflectionClass($context);
      }
    } catch(\ReflectionException $e) {
      $this->refl = null;
    }
    if (!$this->refl) {
      // Use an empty dummy object
      $this->refl = new \ReflectionObject(new \stdClass);
    }
  }
  protected function modifier() {}
  protected function members() {}
  protected function symbol($name) {}
  public function completions($prefix) {}
  public function symbols() {
    return array_map(array($this, 'symbol'), $this->names());
  }

  protected function names() {
    return array_map(function (\Reflector $member) {
      return $member->name;
    }, $this->members());
  }
}

/**
 * Trait: look up methods
 */
class ReflectObjectMethods extends ReflectObjectMembers {
  protected function members() {
    return $this->refl->getMethods($this->modifier());
  }
  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
  public function apropos($prefix) {
    return apropos($prefix, $this->completions($prefix));
  }
}

/**
 * Trait: look up properties
 */
class ReflectObjectProperties extends ReflectObjectMembers{
  protected function members() {
    return $this->refl->getProperties($this->modifier());
  }
  public function completions($prefix) {
    return sensitiveCompletions($prefix, $this->symbols());
  }
  public function apropos($prefix) {
    return apropos($prefix, $this->completions($prefix));
  }
}

/**
 * Instance methods.
 */
class Methods extends ReflectObjectMethods implements Source {
  protected function modifier() { return \ReflectionMethod::IS_PUBLIC; }
  protected function symbol($name) {
    return new MethodName($this->refl, $name);
  }
  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Static methods.
 */
class StaticMethods extends ReflectObjectMethods implements Source {
  protected function modifier() { return \ReflectionMethod::IS_STATIC; }
  protected function symbol($name) {
    return new StaticMethodName($this->refl, $name);
  }
  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

/**
 * Instance properties.
 */
class Properties extends ReflectObjectProperties implements Source {

  protected function modifier() { return \ReflectionMethod::IS_PUBLIC; }
  /**
   * Filter out static properties, which have a different syntax.
   */
  public function members() {
    return array_filter(parent::members(), function ($member) {
      return !$member->isStatic();
    });
  }

  protected function symbol($name) {
    return new PropertyName($this->refl, $name);
  }
}

/**
 * Static properties.
 */
class StaticProperties extends ReflectObjectProperties implements Source {
  protected function modifier() { return \ReflectionMethod::IS_STATIC; }
  protected function symbol($name) {
    return new StaticPropertyName($this->refl, $name);
  }
}

/**
 * Completion and info source for class constants.
 */
class ClassConstants extends ReflectObjectMembers implements Source {
  protected function names() {
    return array_keys($this->refl->getConstants());
  }

  protected function symbol($name) {
    return new ClassConstant($this->refl, $name);
  }

  public function completions($prefix) {
    return sensitiveCompletions($prefix, $this->symbols());
  }
}

/***********************************************************************
 *
 * Global completion sources across all classes/interfaces/traits.
 *
 * These are mostly useful for "apropos" functionality, rather than
 * tab-completion.
 *
 ***********************************************************************/

/**
 * Trait for combining across all defined classes/interfaces/traits
 */
function symbolsForAllClasses($sourceClass, $groupBy = null) {
  $classlikes = new MergeSources(array(
    new ClassNames, new Interfaces
  ));
  $groups = array();
  foreach ($classlikes->symbols() as $class) {
    $source = new $sourceClass((string) $class);
    if ($source) {
      foreach ($source->symbols() as $symbol) {
        if ($groupBy === null) {
          $groups[(string) $symbol][] = $symbol;
        } else {
          $groups[call_user_func($groupBy, $symbol)][] = $symbol;
        }
      }
    }
  }
  $values = array();
  foreach ($groups as $hash => $group) {
    if (count($group) === 1) {
      $values[] = $group[0];
    } else {
      $values[] = new MultiSymbol($group);
    }
  }
  return $values;
}

/**
 * Represent a number of symbols of the same name and kind grouped
 * together for apropos purposes.
 *
 * For example, all zero-argument constructors, __construct(), or all
 * instance properties called '$name'.
 */
class MultiSymbol implements Symbol {
  private $symbols;
  public function __construct(array $symbols) {
    assert (count($symbols));
    foreach($symbols as $symbol) {
      assert ($symbol instanceof Symbol);
    }
    $this->symbols = $symbols;
  }

  public function kind() {
    $this->symbols[0]->kind();
  }

  public function __toString() {
    return (string) $this->symbols[0];
  }

  public function annotate() {
    $info = $this->symbols[0]->annotate();
    unset($info['file']);
    unset($info['line']);
    unset($info['defined_in']);
    foreach ($this->symbols as $symbol) {
      $info['definitions'][] = $symbol->annotate();
    }
    return $info;
  }
}

class AllMethods extends SymbolSource implements Source {
  protected function names() {}
  protected function symbol($name) {}
  public function symbols() {
    return symbolsForAllClasses('Methods', array($this, 'groupBy'));
  }
  public function groupBy(Symbol $symbol) {
    $info = $symbol->annotate();
    return $info['name'] . $info['arguments'];
  }

  public function completions($prefix) {
    return insensitiveCompletions($prefix, $this->symbols());
  }
}

class AllProperties extends SymbolSource implements Source {
  protected function names() {}
  protected function symbol($name) {}
  public function symbols() {
    return symbolsForAllClasses('Properties');
  }
  public function completions($prefix) {
    return sensitiveCompletions($prefix, $this->symbols());
  }
}

class AllStaticProperties extends SymbolSource implements Source {
  protected function names() {}
  protected function source($name) {
    return new StaticProperties((string) $name);
  }
  protected function symbol($name) {}
  public function symbols() {
    return symbolsForAllClasses('StaticProperties');
  }
  public function completions($prefix) {
    return sensitiveCompletions($prefix, $this->symbols());
  }
}

class AllClassConstants extends SymbolSource implements Source {
  protected function names() {}
  protected function source($name) {
    return new ClassConstants((string) $name);
  }
  protected function symbol($name) {}
  public function symbols() {
    return symbolsForAllClasses('ClassConstants');
  }
  public function completions($prefix) {
    return sensitiveCompletions($prefix, $this->symbols());
  }
}

class AllMembers extends MergeSources {
  public function __construct() {
    parent::__construct(array(
      new AllMethods,
      new AllProperties,
      new AllStaticProperties,
      new AllClassConstants,
    ));
  }
}

class AllSymbols extends MergeSources {
  public function __construct($scope = array()) {
    parent::__construct(array(
      new AllMembers,
      new Variables($scope),
      new Functions,
      new Constants,
      new Keywords,
      new ClassNames,
      new Interfaces,
    ));
  }
}


/************************************************************
 *
 * Symbol types
 *
 ************************************************************/

/**
 * Annotation traits
 */

/**
 * Trait for symbol types with no annotation information
 */
function annotateBasic($symbol) {
  return array(
    'name' => (string) $symbol,
    'kind' => $symbol->kind(),
  );
}


function annotateLocation(\Reflector $refl) {
  return array(
    'file' => $refl->getFileName(),
    'line' => $refl->getStartLine(),
  );
}

function annotateDocstring(\Reflector $refl) {
  return array(
    'description' => getDocstring($refl),
  );
}

function getDocstring(\Reflector $refl) {
  $doc_comment = $refl->getDocComment();
  if ($doc_comment) {
    if (preg_match('|/[*][*]\s*\n\s*[*]\s*(.*)$|m', $doc_comment, $matches)) {
      return $matches[1];
    }
  }
}

function annotateParent(\Reflector $refl) {
  $parent = $refl->getParentClass();
  if ($parent) {
    return array(
      'parent' => $parent->name,
    );
  } else {
    return array();
  }
}


/**
 * Annotate property/method with declaring class
 */
function annotateDeclaringClass(\Reflector $refl) {
  return array(
    'defined_in' => $refl->class,
  );
}

/**
 * Trait for annotating function/method signatures using reflection.
 */
function annotateSignature(\Reflector $refl) {
  return array(
    'arguments' => formatSignature($refl),
  );
}

function formatSignature(\ReflectionFunctionAbstract $refl, $arg = -1) {
  $params   = $refl->getParameters();
  $n        = $refl->getNumberOfRequiredParameters();
  $required = array_slice($params, 0, $n);
  $optional = array_slice($params, $n);

  if (count($optional) && count($required)) {
    return sprintf('%s[, %s]',
      formatParams($required, $arg),
      formatParams($optional, $arg));
  } else if(count($required)) {
    return formatParams($required, $arg);
  } else if (count($optional)) {
    return sprintf('[%s]', formatParams($optional, $arg));
  } else {
    return '';
  }
}

function formatParams(array $params, $index) {
  $args = array_map(function (\ReflectionParameter $param = NULL) use ($index) {
    try {
      $refl_class = $param->getClass();
      if($refl_class)
        $class = $refl_class->getName() . ' ';
      else
        $class = '';
    } catch (\ReflectionException $e) {
      $class = '';
    }
    $reference   = $param->isPassedByReference() ? '&' : '';
    $var         = $reference . $param->name;
    $highlighted = ($param->getPosition() == $index) ? strtoupper($var) : $var;
    $arg         = "{$class}\${$highlighted}";

    if($param->isDefaultValueAvailable()) {
      $default = var_export($param->getDefaultValue(), TRUE);
      $default = preg_replace('/\s+/', ' ', $default);
      return "{$arg} = {$default}";
    } else {
      return $arg;
    }
  }, $params);
  return implode(', ', $args);
}

/**
 * Annotate class, interface or trait name by reflection
 */
class AnnotateClass {
  public function annotate() {
    $refl = new \ReflectionClass($this->name);
    return annotateBasic($this)
      + annotateLocation($refl)
      + annotateDocstring($refl)
      + annotateParent($refl);
  }

  /**
   * Return true if this class implements the given interface.
   */
  public function implementsInterface($interface) {
    assert (is_string($interface));
    $refl = new \ReflectionClass($this->name);
    return self::anyNameMatches($refl->getInterfaceNames(), $interface);
  }

  /**
   * Return true if this class uses the given trait.
   */
  public function usesTrait($trait) {
    return false;
  }

  /**
   * Return true if this class extends the given superclass.
   *
   * This comparison is performed transitively: that is, if z extends
   * y and y extends x, then z is considered to extend x.
   */
  public function extendsClass($super) {
    assert (is_string($super));
    $refl = new \ReflectionClass($this->name);
    return self::anyNameMatches(self::ancestors($refl), $super);
  }

  /**
   * Return all ancestors of the class represented by $refl.
   */
  private static function ancestors($refl) {
    if (!$refl) return array();
    assert ($refl instanceof \ReflectionClass);
    $parent = $refl->getParentClass();
    return array_merge(array($refl->name), self::ancestors($parent));
  }

  /**
   * True if a qualified name in $subjects matches $suffix.
   *
   * The comparison is performed case-insensitively.  To match, the
   * element of $subjects must either be equal to $suffix or must end
   * in a backslash followed by $suffix.
   */
  static function anyNameMatches(array $subjects, $suffix) {
    assert (is_string($suffix));
    $lower = strtolower($suffix);
    $regexp = '/' . preg_quote('\\' . $suffix, '/') . '\\Z/i';
    return count(
      array_filter($subjects, function ($subject) use ($lower, $regexp) {
        return (strtolower($subject) == $lower)
          || preg_match($regexp, $subject);
      })) > 0;
  }
}

/************************************************************
 *
 * Symbols without an object/class context
 *
 ************************************************************/

class SimpleSymbol {
  protected $name;
  public function __construct($name) {
    $this->name = $name;
  }
}

// Variable
class Variable extends SimpleSymbol implements Symbol {
  function kind() { return 'variable'; }
  public function __toString() {
    return '$' . $this->name;
  }
  public function annotate() {
    return annotateBasic($this);
  }
}

// Constant
class Constant extends SimpleSymbol implements Symbol {
  function kind() { return 'constant'; }
  public function __toString() {
    return $this->name;
  }
  public function annotate() {
    return annotateBasic($this);
  }
}

// Keyword
class Keyword extends SimpleSymbol implements Symbol {
  function kind() { return 'keyword'; }
  public function __toString() {
    return $this->name;
  }
  public function annotate() {
    return annotateBasic($this);
  }
}

// Function name
class FunctionName extends SimpleSymbol implements Symbol {
  function kind() { return 'function'; }
  function annotate() {
    $refl = new \ReflectionFunction($this->name);
    return annotateBasic($this)
      + annotateLocation($refl)
      + annotateDocstring($refl)
      + annotateSignature($refl);
  }
  public function __toString() {
    return $this->name . '(';
  }
}

// Plain class name
class ClassName extends AnnotateClass implements Symbol {
  protected $name;
  public function __construct($name) {
    $this->name = $name;
  }
  function kind() { return 'class'; }
  public function __toString() {
    return $this->name;
  }
}

// Interface name
class InterfaceName extends AnnotateClass implements Symbol {
  protected $name;
  public function __construct($name) {
    $this->name = $name;
  }
  function kind() { return 'interface'; }
  public function __toString() {
    return $this->name;
  }
}

// Trait name
class TraitName extends AnnotateClass implements Symbol {
  protected $name;
  public function __construct($name) {
    $this->name = $name;
  }
  function kind() { return 'class'; }
  public function __toString() {
    return $this->name;
  }
}

// Class name used as constructor
class ClassConstructor implements Symbol {
  protected $name;
  public function __construct($name) {
    $this->name = $name;
  }
  public function __toString() {
    return $this->name . '(';
  }
  public function kind() { return 'class'; }
  public function annotate() {
    $refl = new \ReflectionClass($this->name);
    $info = annotateBasic($this)
      + annotateLocation($refl)
      + annotateDocstring($refl);

    $constructor = $refl->getConstructor();
    if ($constructor) {
      $info += annotateSignature($constructor);

      if (empty($info['description'])) {
        $info += annotateDocstring($constructor);
      }
    }
    return $info;
  }
}

/************************************************************
 *
 * Symbols with an object/class context
 *
************************************************************/
class AnnotateMethod {
  function annotate() {
    $refl = $this->context->getMethod($this->name);
    return annotateBasic($this)
      + annotateDocstring($refl)
      + annotateLocation($refl)
      + annotateSignature($refl)
      + annotateDeclaringClass($refl);
  }
}

class AnnotateProperty {
  function annotate() {
    $property = $this->context->getProperty($this->name);
    $class = $property->getDeclaringClass();
    return annotateBasic($this)
      + annotateDocstring($property)
      + annotateLocation($class)
      + annotateDeclaringClass($property);
  }
}

class MethodName extends AnnotateMethod implements Symbol {
  protected $context;
  protected $name;
  function __construct($context, $name) {
    $this->context = $context;
    $this->name = $name;
  }
  public function __toString() {
    return $this->name . '(';
  }
  function kind() { return 'method'; }
}

class StaticMethodName extends AnnotateMethod implements Symbol {
  protected $context;
  protected $name;
  function __construct($context, $name) {
    $this->context = $context;
    $this->name = $name;
  }
  public function __toString() {
    return $this->name . '(';
  }
  function kind() { return 'static method'; }
}

class PropertyName extends AnnotateProperty implements Symbol {
  protected $context;
  protected $name;
  function __construct($context, $name) {
    $this->context = $context;
    $this->name = $name;
  }
  public function __toString() {
    return $this->name;
  }
  function kind() { return 'property'; }
}

class StaticPropertyName extends AnnotateProperty implements Symbol {
  protected $context;
  protected $name;
  function __construct($context, $name) {
    $this->context = $context;
    $this->name = $name;
  }
  public function __toString() {
    return '$' . $this->name;
  }
  function kind() { return 'static property'; }
}

class ClassConstant implements Symbol {
  protected $context;
  protected $name;
  function __construct($context, $name) {
    $this->context = $context;
    $this->name = $name;
  }
  public function __toString() {
    return $this->name;
  }
  function kind() { return 'class constant'; }
  function annotate() {
    $info = annotateBasic($this);
    unset($info['description']);
    return $info;
  }
}
