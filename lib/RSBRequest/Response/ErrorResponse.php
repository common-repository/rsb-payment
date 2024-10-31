<?php


namespace Ipol\RSBRequest\Response;


use Ipol\RSBRequest\ApiLevelException;

/**
 * Class ErrorResponse
 * @package Ipol\RSBRequest\Response
 */
class ErrorResponse extends AbstractResponse
{
    /**
     * @var null|string
     */
    protected $timestamp;
    /**
     * @var null|int
     */
    protected $status;
    /**
     * @var null|string
     */
    protected $error;
    /**
     * @var null|string
     */
    protected $message;
    /**
     * @var null|string
     */
    protected $path;

    public function __construct(ApiLevelException $error)
    {
        parent::__construct($error->getAnswer());
        $this->message = $error->getAnswer();
        $this->ErrorCode = $error->getCode();
    }


    /**
     * @return string|null
     */
    public function getTimestamp(): ?string
    {
        return $this->timestamp;
    }

    /**
     * @param string|null $timestamp
     * @return ErrorResponse
     */
    public function setTimestamp(?string $timestamp): ErrorResponse
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getStatus(): ?int
    {
        return $this->status;
    }

    /**
     * @param int|null $status
     * @return ErrorResponse
     */
    public function setStatus(?int $status): ErrorResponse
    {
        $this->status = $status;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * @param string|null $error
     * @return ErrorResponse
     */
    public function setError(?string $error): ErrorResponse
    {
        $this->error = $error;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /**
     * @param string|null $message
     * @return ErrorResponse
     */
    public function setMessage(?string $message): ErrorResponse
    {
        $this->message = $message;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * @param string|null $path
     * @return ErrorResponse
     */
    public function setPath(?string $path): ErrorResponse
    {
        $this->path = $path;
        return $this;
    }

}