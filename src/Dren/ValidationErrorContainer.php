<?php

namespace Dren;

class ValidationErrorContainer
{
    public function __construct(array $errors = [])
    {
        $this->import($errors);
    }

    private array $errors = [];

    // have to have import and export methods so we can get and set raw arrays of errors
    // for serialization purposes (storing errors in flash data)
    public function import(array $e) : void
    {
        $this->errors = $e;
    }

    public function export() : array
    {
        return $this->errors;
    }

    public function add(string $key, string $message) : void
    {
        $this->errors[$key][] = $message;
    }

    public function count() : int
    {
        $c = 0;
        foreach($this->errors as $arrayElement)
            $c += count($arrayElement);

        //return count($this->errors);
        return $c;
    }

    public function first(string $key) : string
    {
        $errorArray = $this->get($key);
        if(count($errorArray) == 0)
            return '';

        return $this->get($key)[0];
    }

    public function get(string $key) : array
    {
        if(!array_key_exists($key, $this->errors))
            return [];

        if(!str_contains($key, '[*]'))
            return $this->errors[$key];

        $matches = [];
        foreach($this->errors as $k => $v)
            if($this->_isFieldArrayPattern($key, $k))
                $matches[] = $this->errors[$k];

        return $matches;
    }

    public function all() : array
    {
        $returnArray = [];
        foreach($this->errors as $k => $v)
            $returnArray = array_merge($returnArray, $v);

        return $returnArray;
    }

    public function has(string $key) : bool
    {
        return (count($this->get($key)) > 0);
    }
    private function _isFieldArrayPattern($pattern, $input) : bool
    {
        // Escape the pattern, then replace '[*]' with regex to match any number value enclosed by '[]'
        $pattern = preg_replace('/\\\[\*\\\]/', '(\d+)', preg_quote($pattern, '/'));

        // Create the regex pattern by adding start and end delimiters
        $pattern = '/^' . $pattern . '$/';

        return preg_match($pattern, $input);
    }

}