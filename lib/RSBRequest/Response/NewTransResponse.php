<?php
namespace Ipol\RSBRequest\Response;

/**
 * Class NewTransResponse
 * @package Ipol\RSBRequest\Response
 */
class NewTransResponse extends AbstractResponse {

    public function __construct($raw_data)
    {
        parent::__construct($raw_data);
    }

    /**
     * @return string|null
     */
    public function getTransID():?string
    {
        if (!isset($this->response_arr['TRANSACTION_ID'])) return null;
        return $this->response_arr['TRANSACTION_ID'];
    }

    /**
     * @return string
     */
    public function getTransStatus():string
    {
        if (!isset($this->response_arr['RESULT_PS'])) return '';
        return $this->response_arr['RESULT_PS'];
    }

    /**
     * @return string
     */
    public function getRawString():string
    {
        return $this->raw_data;
    }

    /**
     * @return string
     */
    public function getResponseRawData():string
    {
        return serialize($this->response_arr);
    }

}