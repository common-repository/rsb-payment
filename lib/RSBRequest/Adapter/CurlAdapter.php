<?php
namespace Ipol\RSBRequest\Adapter;

use Ipol\RSBRequest\ApiLevelException;
use Ipol\RSBRequest\BadResponseException;
use Ipol\RSBRequest\lbCurl\Curl;

/**
 * Class CurlAdapter
 * @package Ipol\RSBRequest\Adapter
 */
class CurlAdapter extends AbstractAdapter
{
    /**
     * @var Curl
     */
    protected $curl;
    /**
     * @var string
     */
    protected $url;
    /**
     * @var string
     */
    protected $requestType;
    /**
     * @var array
     */
    protected $headers = [];
    /**
     * @var string
     */
    protected $contentType = 'Content-Type: application/json; charset=utf-8';
    /**
     * @var string
     */
    protected $method = 'unconfigured_request';

    /**
     * CurlAdapter constructor.
     * @param int $timeout
     * @throws Exception if curl not installed
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param array $dataPost
     * @param string $urlImplement
     * @param array $dataGet
     * @return mixed
     * @throws ApiLevelException
     * @throws BadResponseException
     */
    public function form(array $dataPost = [], string $urlImplement = "", array $dataGet = [])
    {
        $this->curl->setOpt(CURLOPT_RETURNTRANSFER, TRUE);
        $getStr = (!empty($dataGet))? "?" . http_build_query($dataGet) : "";
        $this->curl->setUrl($this->getUrl() . $urlImplement . $getStr);
        $this->log->debug('', [
            'method' => $this->method,
            'process' => 'REQUEST',
            'content' => [
                'URL' => $this->curl->getUrl(),
                'DATA' => $dataPost,
                'FORM' => http_build_query($dataPost, JSON_UNESCAPED_UNICODE)
            ],
        ]);
        $this->applyHeaders()->curl->post(http_build_query($dataPost));
        $this->log->debug('', [
            'method' => $this->method,
            'process' => 'RESPONSE',
            'content' => [
                'CODE' => $this->curl->getCode(),
                'BODY' => $this->curl->getAnswer()
            ],
        ]);
        $this->afterCheck($dataPost);
        return $this->curl->getAnswer();
    }

    /**
     * @param array $dataPost
     * @param string $urlImplement
     * @param array $dataGet
     * @return mixed
     * @throws ApiLevelException
     * @throws BadResponseException
     */
    public function post(array $dataPost = [])
    {
        $wpcurl = new \WP_Http_Curl;
        $response = $wpcurl->request($this->url,[
            'method'=>'POST',
            'timeout'=>35,
            'body'=>http_build_query($dataPost),
        ]);
        if (strlen($this->logfilepath)>5) {
            file_put_contents(
                $this->logfilepath,
                Date('Y-m-d H:i:s')."\r\nRequest:\r\n".http_build_query($dataPost)."\r\n\r\nResponse:\r\n".$response['response']['code'].": ".$response['response']['message']."\r\n\r\n".$response['body']."\r\n\r\n______________\r\n\r\n",
                FILE_APPEND
            );
        }
        return $response['body'];
    }

    /**
     * @return string
     */
    public function getUrl(): ?string
    {
        return $this->url;
    }

    /**
     * @param string $url
     * @return CurlAdapter
     */
    public function setUrl(string $url): CurlAdapter
    {
        $this->url = $url;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getRequestType(): ?string
    {
        return $this->requestType;
    }

    /**
     * @param string $requestType
     * @return CurlAdapter
     */
    public function setRequestType(string $requestType): CurlAdapter
    {
        $this->requestType = $requestType;
        return $this;
    }

    /**
     * @param string $contentType
     * @return $this
     */
    public function setContentType(string $contentType): CurlAdapter
    {
        $this->contentType = $contentType;
        return $this;
    }

    /**
     * @param string $method
     * @return CurlAdapter
     */
    public function setMethod(string $method): CurlAdapter
    {
        $this->method = $method;
        return $this;
    }



}