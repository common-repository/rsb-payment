<?php


namespace Ipol\RSBRequest\Methods;

use Ipol\RSBRequest\Adapter\CurlAdapter;
use Ipol\RSBRequest\ApiLevelException;
use Ipol\RSBRequest\BadResponseException;
use Ipol\RSBRequest\Response\ErrorResponse;

/**
 * Class GeneralMethod
 * @package Ipol\RSBRequest
 * @subpackage Methods
 * @method AbstractResponse|mixed|ErrorResponse getResponse
 */
class GeneralMethod extends AbstractMethod
{
    /**
     * GetOrderHistory constructor.
     * @param AbstractRequest|mixed|null $data
     * @param CurlAdapter $adapter
     * @param string $responseClass
     * @param EncoderInterface|null $encoder
     * @throws BadResponseException
     */
    public function __construct(
        $data,
        CurlAdapter $adapter,
        string $responseClass
    ) {
        parent::__construct($adapter);

        if (!is_null($data)) {
            $this->setData($data);
        }

        try {
            /**@var $response AbstractResponse*/
            $response = new $responseClass($this->request());
            $response->setSuccess(true);
        } catch (ApiLevelException $e) {
            $response = new ErrorResponse($e);
            $response->setSuccess(false);
        }

        $this->setResponse($response);
    }

}