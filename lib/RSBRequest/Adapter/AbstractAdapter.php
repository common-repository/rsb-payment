<?php

namespace Ipol\RSBRequest\Adapter;

use Ipol\RSBRequest\Logger\Psr\Log\LoggerInterface;
use Ipol\RSBRequest\Logger\Psr\Log\NullLogger;
use Ipol\RSBRequest\Logger\Psr\Log\InvalidArgumentException;

class AbstractAdapter
{
    /**
     * @var LoggerInterface
     */
    protected $log;
    /**
     * @var logfilepath
     */
    protected $logfilepath;

    /**
     * AbstractAdapter constructor.
     */
    public function __construct()
    {
        $this->log = new NullLogger();
    }

    /**
     * @return LoggerInterface
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * @param LoggerInterface $log
     * @return $this
     */
    public function setLog($log)
    {
        if(!is_a($log, LoggerInterface::class)) {
            throw new InvalidArgumentException();
        }

        $this->log = $log;
        return $this;
    }

    public function setLogFile($logfile) {
        $this->logfilepath=$logfile;
    }

}
