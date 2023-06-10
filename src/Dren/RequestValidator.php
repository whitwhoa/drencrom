<?php

namespace Dren;

use stdClass;

abstract class RequestValidator
{
    abstract protected function setRules() : void;
    protected Request $request;

    protected string $failureResponseType = 'redirect'; // or json

    protected array $rules = [];

    private array $requestData = [];

    protected array $messages = [];

    private ValidationErrorContainer $errors;

    private array $expandedFields = [];

    private array $methodChains = [];

    public function __construct(Request $request)
    {
        $this->request = $request;

        $this->requestData = array_merge(
            ($request->getGetData() ? (array)$request->getGetData() : []),
            ($request->getPostData() ? (array)$request->getPostData() : [])
        );

        $this->errors = new ValidationErrorContainer();
    }

    public function getErrors() : ValidationErrorContainer
    {
        return $this->errors;
    }

    public function getFailureResponseType() : string
    {
        return $this->failureResponseType;
    }

    public function validate() : bool
    {
        $this->setRules();

        $this->_expandFields();

        foreach($this->expandedFields as $ef)
        {
            $methodChain = $this->methodChains[$ef[2]];
            if(is_string($methodChain))
                $methodChain = explode('|', $methodChain);

            foreach($methodChain as $methodChainDetails)
            {
                if(is_string($methodChainDetails))
                {
                    $fenceUp = false;
                    if(str_starts_with($methodChainDetails, "#"))
                    {
                        $fenceUp = true;
                        $methodChainDetails = substr($methodChainDetails, 1);
                    }

                    $methodChainDetails = explode(':', $methodChainDetails);
                    $method = $methodChainDetails[0];
                    $params = [];
                    if(count($methodChainDetails) > 1)
                        $params = explode(',', $methodChainDetails[1]);

                    $preMethodCallErrorsCount = $this->errors->count();

                    $this->$method(array_merge([$ef[0], $ef[1]], $params));

                    if($fenceUp && ($this->errors->count() > $preMethodCallErrorsCount))
                        break 2;
                    else
                        continue;
                }

                $methodChainDetails($this->requestData, $this->errors);
            }
        }

        return !($this->errors->count() > 0);
    }

    private function _expandFields() : void
    {
        // expand fields such that when array syntax provided .*.etc.*....
        // each individual element is expanded into its own "field"

        // [the.*.field.*.name, requestData, methodChain]

        foreach($this->rules as $field => $methodChain)
        {
            $this->methodChains[] = $methodChain;

            $keys = explode('.', $field);
            $index = 0;
            $this->_expandFieldsRecursiveLoop($this->requestData, $keys, $index, []);
        }
    }

    private function _expandFieldsRecursiveLoop($data, &$keys, &$index, $path) : void
    {
        if ($index >= count($keys))
            return;

        $currentKey = $keys[$index];
        $path[] = $currentKey;

        if ($currentKey === '*')
        {
            $itemIndex = 0;
            foreach ($data as $item)
            {
                $path[count($path) - 1] = $itemIndex++;
                $this->_expandFieldsProcessItem($item, $keys, $index, $path);
                $path[count($path) - 1] = '*';
            }
        }
        else if (isset($data[$currentKey]))
        {
            $this->_expandFieldsProcessItem($data[$currentKey], $keys, $index, $path);
        }

        array_pop($path);
    }

    function _expandFieldsProcessItem($item, &$keys, &$index, $path) : void
    {
        if ($index < count($keys) - 1)
        {
            $index++;
            $this->_expandFieldsRecursiveLoop($item, $keys, $index, $path);
            $index--;
        }
        else
        {
            $this->expandedFields[] = [implode('.', $path), $item, (count($this->methodChains) - 1)];
        }
    }

    private function _setErrorMessage($method, $field, $defaultMsg) : void
    {
        $explodedField = explode('.', $field);

        if(count($explodedField) > 1)
        {
            $key = '';
            foreach($explodedField as $v)
            {
                if(is_numeric($v))
                    $key .= '*';
                else
                    $key .= $v;

                $key .= '.';
            }
            $key .= $method;
        }
        else
        {
            $key = $field . '.' . $method;
        }

        $msgToUse = $defaultMsg;
        if(array_key_exists($key, $this->messages))
            $msgToUse = $this->messages[$key];

        $this->errors->add($field, $msgToUse);
    }

    /******************************************************************************
     * Add various validation methods below this line:
     * We allow underscores in function names here due to how they are called from
     * lists of strings (the underscores make for better readability in child classes)
     ******************************************************************************/


    /******************************************************************************
     * Method signatures are all an array where the following is true:
     * $input[0 => $fieldName, 1 => $value, 2 => (optional...), 3 => (...)]
     ******************************************************************************/

    private function required(array $params) : void
    {
        if($params[1])
            return;

        $this->_setErrorMessage('required', $params[0], $params[0] . ' is required');
    }

    private function min_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) > $params[2])
            return;

        $this->_setErrorMessage('min_char', $params[0], $params[0] . ' must be at least ' . $params[2] . ' characters');
    }

    private function max_char(array $params) : void
    {
        $valString = (string)$params[1];
        if(strlen($valString) <= $params[2])
            return;

        $this->_setErrorMessage('max_char', $params[0], $params[0] . ' must be less than or equal to ' . $params[2] . ' characters');
    }

    private function email(array $params) : void
    {
        if(filter_var($params[1], FILTER_VALIDATE_EMAIL) !== false)
            return;

        $this->_setErrorMessage('email', $params[0], $params[0] . ' must be an email address');
    }

    private function same(array $params) : void
    {
        if(key_exists($params[2], $this->requestData) && $this->requestData[$params[2]] == $params[1])
            return;

        $this->_setErrorMessage('same', $params[0], $params[0] . ' must match ' . $params[2]);
    }

    //!!!!! NOTE !!!!!!!
    // Some of these queries might look like sql injection vulnerabilities at first glance, however, note where user input
    // is handled, no user input is ever concatenated with the query string, only values provided by the application code
    // itself, user input is still always parameterized
    private function unique(array $params) : void
    {
        if(!App::get()->getDb()->query("SELECT * FROM " . $params[2] . " WHERE " . $params[3] . " = ?", [$params[1]])->singleAsObj()->exec())
            return;

        $this->_setErrorMessage('unique', $params[0], $params[0] . ' must be unique');
    }

    private function is_array(array $params) : void
    {
        if(\is_array($params[1]))
            return;

        $this->_setErrorMessage('is_array', $params[0], $params[0] . ' must be an array');
    }

    private function min_array_elements(array $params) : void
    {
        if(count($params[1]) >= $params[2])
            return;

        $this->_setErrorMessage('min_array_elements', $params[0], $params[0] . ' must contain at least ' . $params[2] . ' elements');
    }


}