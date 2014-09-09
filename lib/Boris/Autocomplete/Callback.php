<?php

namespace Boris\Autocomplete;

use Hoa\Console\Readline\Autocompleter;

class Callback implements Autocompleter {

    protected $callback;

    /**
     * Constructor.
     *
     * @access  public
     * @param   array  $words    Words.
     * @return  void
     */
    public function __construct($callback)
	{
		$this->callback = $callback;
    }

    /**
     * Complete a word via callback.
     *
     * @access  public
     * @param   string  &$prefix    Prefix to autocomplete.
     * @return  mixed
     */
    public function complete(&$prefix)
	{
		$fn = $this->callback;
		return $fn($prefix);
    }

    /**
     * Get definition of a word.
     *
     * @access  public
     * @return  string
     */
    public function getWordDefinition()
	{
		return '\s*([^\s]+)\s*?';
    }
}
