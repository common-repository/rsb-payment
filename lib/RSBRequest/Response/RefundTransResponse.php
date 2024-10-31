<?php

namespace Ipol\RSBRequest\Response;

use \Ipol\RSBRequest\Response\AbstractResponse;

class RefundTransResponse extends AbstractResponse {

    public function __construct($raw_data)
    {
        parent::__construct($raw_data);
    }

    /**
     * @return string
     */
    public function getCurrentStatus():string
    {
        if (!isset($this->response_arr['RESULT_PS'])) return '';
        return $this->response_arr['RESULT_PS'];
    }

    /**
     * @return string|null
     */
    public function getRefundTransID():?string
    {
        if (!isset($this->response_arr['REFUND_TRANS_ID'])) return null;
        return $this->response_arr['REFUND_TRANS_ID'];
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