<?php


namespace Ipol\RSBRequest;

use Exception;


class ApiLevelException extends Exception
{
    /**
     * @var string
     */
    protected $url;
    protected $request;
    protected $answer;

    /**
     * ApiLevelException constructor.
     * @param $message
     * @param $code
     * @param $url
     * @param $request
     * @param $answer
     */
    public function __construct($message = false, $code = false, $url = "", $request = false, $answer = false)
    {
        parent::__construct($message, $code);
        $this->url = $url;
        $this->request = $request;
        $this->answer = $answer;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return mixed
     */
    public function getRequest()
    {
        return $this->request;
    }


    /**
     * @return mixed
     */
    public function getAnswer()
    {
        return $this->answer;
    }

}