<?php
namespace Ipol\RSBRequest\Entity;

class AbstractEntity
{
    public function __construct()
    {
    }

    /**
     * execute all get|is methods for all object properties
     * @return array with object properties as keys and internal values of this properties as values
     * return keys only for set properties
     * there is also getFields() method in abstractCollection, that does basically the same but for Collections
     */
    public function getFields()
    {
        $vars = array_filter(get_object_vars($this), function($a){return ($a !== null);}); //excluding all null properties from return
        return $this->parseFields($vars);
    }

    /**
     * execute all get|is methods for all object properties
     * @return array with all object properties as keys and internal values of this properties as values
     * returns keys for not set properties as well
     */
    public function getAllFields()
    {
        $vars = get_object_vars($this);
        return $this->parseFields($vars);
    }

    public function parseFields($fields)
    {
        foreach($fields as $key => $val)
        {
            $name = explode('_', $key);
            $name = implode(array_map('ucfirst', $name));
            $getMethod = 'get'.$name;
            $isMethod = 'is'.$name;
            if(method_exists($this,$getMethod)){
                $fields[$key] = $this->parseField($this->$getMethod());
            }elseif(method_exists($this,$isMethod)){
                $fields[$key] = $this->parseField($this->$isMethod());
            } else {
                $fields[$key] = $this->parseField($val);
            }
        }
        return $fields;
    }

    protected function parseField($val)
    {
        if (is_array($val)) {
            return $this->parseFields($val);
        } elseif (is_object($val) && method_exists($val, 'getFields')) {
            return $val->getFields();
        } else {
            return $val;
        }
    }

    public function setFields($fields)
    {
        if(!empty($fields))
        {
            foreach ($fields as $field => $value)
            {
                if (is_object($value)) {
                    $value = (array)$value;
                }
                if (!is_object($value))
                {
                    $field = explode('_', $field);
                    $field = implode(array_map('ucfirst', $field));
                    $method = 'set'.$field;
                    if (method_exists($this, $method))
                    {
                        $this->$method($value);
                    }
                }
            }
        }

        return $this;
    }
}
