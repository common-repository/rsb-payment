<?php

namespace Ipol\RSBRequest\Response;

use \Ipol\RSBRequest\Response\AbstractResponse;

class RenewTransResponse extends AbstractResponse {

    public function __construct($raw_data)
    {
        parent::__construct($raw_data);
    }

    /**
     * @param int $current_type
     * @return int
     */
    public function getNewType(int $current_type=-1):int
    {
        if ($current_type<0) return $current_type;
        if (!$this->getSuccess()) return $current_type;
        if (count($this->response_arr)==0) return $current_type;
        if (!$this->isOk()) return $current_type;
        if (
            ($current_type==20||$current_type==21||$current_type==22)
            &&
            (( $this->response_arr['RESULT_PS']=='ACTIVE'||$this->response_arr['RESULT_PS']=='FINISHED' ) && $this->response_arr['RESULT']=='OK' )
        ) {
            switch ($current_type) {
                case 20:
                    return 0; //SMS
                case 21:
                    return 1; //DMS ?? 1 = DMS Authorization, 3 = DMS Finished
                case 22:
                    return 2; //Recurrent
            }
        }

        if ( ($current_type==21 || $current_type==1) && $this->response_arr['RESULT_PS']=='FINISHED' && $this->response_arr['RESULT']=='OK' ) return 3; //DMS Finished
        if ( ($current_type==0 || $current_type==1) && (
                ($this->response_arr['RESULT_PS']=='CANCELLED'||$this->response_arr['RESULT_PS']=='RETURNED') && $this->response_arr['RESULT']=='REVERSED' ) ) {
            return 4;
        }

        return $current_type;
    }

    /**
     * @return string
     */
    public function getRawString():string
    {
        return $this->raw_data;
    }

    /**
     * @return string|null
     */
    public function getCurrentStatus():?string
    {
        if (!isset($this->response_arr['RESULT_PS'])) return null;
        return $this->response_arr['RESULT_PS'];
    }

    /**
     * @return string|null
     */
    public function getCurrentCode():?string
    {
        if (!isset($this->response_arr['RESULT_CODE'])) return null;
        return $this->response_arr['RESULT_CODE'];
    }

    /**
     * @return string
     */
    public function getResponseRawData():string
    {
        return serialize($this->response_arr);
    }

}