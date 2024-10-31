<?php
namespace Ipol\RSBRequest\Response;

use Ipol\RSBRequest\BadResponseException;

/**
 * Class AbstractResponse
 * @package Ipol\RSBRequest\Response
 */
abstract class AbstractResponse
{
    /**
     * @var string
     */
    protected $raw_data;
    /**
     * @var bool - internal Api-layer flag for checking successful request
     */
    protected $requestSuccess = false;
    /**
     * @var bool - api-response field, get content directly from server
     */
    protected $Success;
    /**
     * @var int
     */
    protected $ErrorCode;
    /**
     * @var string
     */
    protected $ErrorMsg;

    /**
     * @var array
     */
    protected $response_arr = [];

    /**
     * AbstractResponse constructor.
     * @param $json
     * @throws BadResponseException
     */
    function __construct($raw_data)
    {
        $this->raw_data = $raw_data;
        if (empty($raw_data)) {
            throw new BadResponseException('Empty server answer ' . __CLASS__);
        }
    }

    /**
     * @return bool
     */
    public function isRequestSuccess(): bool
    {
        return $this->requestSuccess;
    }

    /**
     * @param bool $requestSuccess
     * @return AbstractResponse
     */
    public function setRequestSuccess(bool $requestSuccess): AbstractResponse
    {
        $this->requestSuccess = $requestSuccess;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getSuccess()
    {
        return $this->Success;
    }

    /**
     * @param mixed $Success
     * @return $this
     */
    public function setSuccess($Success)
    {
        if ($Success === 'false')
            $Success = false;
        $this->Success = $Success;
        $this->proceedRawAnswer();
        return $this;
    }

    /**
     * @return mixed
     */
    public function getErrorCode()
    {
        return $this->ErrorCode;
    }

    /**
     * @param mixed $ErrorCode
     * @return $this
     */
    public function setErrorCode($ErrorCode)
    {
        $this->ErrorCode = $ErrorCode;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getErrorMsg()
    {
        return $this->ErrorMsg;
    }

    /**
     * @param mixed $ErrorMsg
     * @return $this
     */
    public function setErrorMsg($ErrorMsg)
    {
        $this->ErrorMsg = $ErrorMsg;
        return $this;
    }

    /**
     * @return void
     */
    private function proceedRawAnswer(): void
    {
        $res = [];
        $strar = explode("\n", $this->raw_data);
        foreach ($strar as $str) {
            if (strpos($str, 'HTTP/1.1') !== false) continue;
            if (strpos($str, 'Content-Type:') !== false) continue;
            if (strpos($str, 'Content-Length:') !== false) continue;
            if ($str == "\r") continue;
            if ($str == "\n") continue;
            if ($str == "\r\n") continue;
            if ($str == '') continue;
            //$curlres_str.=$str."\n";
            list($s1, $s2) = explode(": ", $str);
            $res[$s1] = $s2;
        }
        $this->response_arr = $res;
    }

    /**
     * @return int
     */
    public function getNewType()
    {
        return -1; //NoType
    }

    /**
     * @return bool
     */
    public function isOk(): bool
    {
        if (!$this->getSuccess()) return false;
        if ($this->response_arr['RESULT']=='OK' && ($this->response_arr['RESULT_CODE']=='000' || $this->response_arr['RESULT_CODE']=='400')) {
            return true;
        }
        return false;
    }

}