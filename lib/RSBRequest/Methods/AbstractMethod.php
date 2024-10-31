<?php

namespace Ipol\RSBRequest\Methods;

use Ipol\RSBRequest\Adapter\CurlAdapter;
use Ipol\RSBRequest\ApiLevelException;
use Ipol\RSBRequest\BadResponseException;
use Ipol\RSBRequest\Entity\AbstractEntity;

/**
 * Class AbstractMethod
 * @package Ipol\RSBRequest\Methods
 */
abstract class AbstractMethod
{
    /**
     * @var CurlAdapter
     */
    protected $adapter;
    /**
     * @var string
     */
    protected $method;
    /**
     * @var array
     */
    protected $dataPost = [];
    /**
     * @var array
     */
    protected $dataGet = [];
    /**
     * @var string
     */
    protected $urlImplement = "";
    /**
     * @var AbstractResponse|mixed|null
     */
    protected $response;

    /**
     * AbstractGroup constructor.
     * @param CurlAdapter $adapter
     */
    public function __construct(CurlAdapter $adapter)
    {
        if (empty($this->method)) { //you can specify your unique method name as child class's property, or it will be set by default as lcfirst( of child class's name)
            $this->method = ($pos = strrpos(get_class($this), '\\')) ?
                substr(get_class($this), $pos + 1) :
                get_class($this);
            $this->method = lcfirst($this->method);
        }

        $this->adapter = $adapter;
    }

    /**
     * @return mixed
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param mixed $response
     * @return $this
     */
    public function setResponse($response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * @return mixed
     * @throws ApiLevelException
     * @throws BadResponseException
     */
    public function request()
    {
        /*switch($this->adapter->getRequestType())
        {
            case 'GET':
                return $this->adapter->get($this->getUrlImplement(), $this->getDataGet());
            case 'DELETE':
                return $this->adapter->delete($this->getUrlImplement());
            case 'FORM':
                $this->adapter->setContentType('Content-Type: application/x-www-form-urlencoded');
                return $this->adapter->form($this->getDataPost(), $this->getUrlImplement(), $this->getDataGet());
            case 'PUT':
                return $this->adapter->put($this->getDataPost(), $this->getUrlImplement(), $this->getDataGet());
            default:
                return $this->adapter->post($this->getDataPost(), $this->getUrlImplement(), $this->getDataGet());
        }*/
        return $this->adapter->post($this->getDataPost(), $this->getUrlImplement(), $this->getDataGet());
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @param array $data
     */
    public function setData($data)
    {
        switch($this->adapter->getRequestType())
        {
            case 'PUT':
            case 'POST':
                $this->setDataPost($data);
                break;
            case 'GET':
                $this->setDataGet($data);
                break;
            default:
                throw new \Exception('For custom type request post and get data should be set manually');
        }
    }

    public function getDataPost()
    {
        return $this->dataPost;
    }

    public function setDataPost($dataPost)
    {
        $this->dataPost = $dataPost;
    }

    /**
     * @return array
     */
    public function getDataGet()
    {
        return $this->dataGet;
    }

    /**
     * @param array $dataGet
     */
    public function setDataGet($dataGet)
    {
        $this->dataGet = $dataGet;
    }

    /**
     * @return string
     */
    public function getUrlImplement(): string
    {
        return $this->urlImplement;
    }

    /**
     * @param string $urlImplement
     * @return AbstractMethod
     */
    public function setUrlImplement(string $urlImplement)
    {
        $this->urlImplement = $urlImplement;
        return $this;
    }

    /**
     * @param AbstractEntity|mixed $entity
     * @return mixed
     */
    public function getEntityFields($entity)
    {
        if($entity) {
            return $entity->getFields();
        }
        return false;
    }

    public function setFields()
    {
        /**@var AbstractEntity $response*/
        $response = $this->getResponse();
        if($response)
        {
            $response->setFields($response->getDecoded());
        }
    }

    protected function setObjectFields(&$object,$fields)
    {
        if($fields){
            foreach ($fields as $field => $value)
            {
                if(!is_object($value))
                {
                    $field = explode('_', $field);
                    $field = implode(array_map('ucfirst', $field));
                    $method = 'set'.$field;
                    if(method_exists($object, $method))
                    {
                        $object->$method($value);
                    }
                }
            }
        }
    }

}