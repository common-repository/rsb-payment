<?php

namespace Ipol\RSBRequest;

use Ipol\RSBRequest\Adapter\CurlAdapter;
use Ipol\RSBRequest\Methods\GeneralMethod;

class MainController
{
    private $adapter;

    public function __construct($request_url='',$logFile='')
    {
        if (!$request_url) throw new \Exception('You must specify URL');

        $this->adapter = new CurlAdapter();
        $this->adapter->setUrl($request_url);
        $this->adapter->setRequestType('POST');
        $this->adapter->setLogFile($logFile);
    }

    /**
     * @param array $data
     * @return Methods\AbstractResponse|Response\ErrorResponse|mixed
     * @throws BadResponseException
     */
    public function newTransaction(array $data) {
        $response = new GeneralMethod($data,$this->adapter,'Ipol\RSBRequest\Response\NewTransResponse');
        return $response->getResponse();
    }

    /**
     * @param array $data
     * @return Methods\AbstractResponse|Response\ErrorResponse|mixed
     * @throws BadResponseException
     */
    public function updateTransaction(array $data) {
        $response = new GeneralMethod($data,$this->adapter,'Ipol\RSBRequest\Response\RenewTransResponse');
        return $response->getResponse();
    }

    /**
     * @param array $data
     * @return Methods\AbstractResponse|Response\ErrorResponse|mixed
     * @throws BadResponseException
     */
    public function refundTransaction(array $data) {
        $response = new GeneralMethod($data,$this->adapter,'Ipol\RSBRequest\Response\RefundTransResponse');
        return $response->getResponse();
    }

}