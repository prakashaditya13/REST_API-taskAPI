<?php

class Response{
    private $_Success;
    private $_httpstatuscode;
    private $_messages = array();
    private $_data;
    private $_tocache = false;
    private $_responseData = array();

    public function setSuccess($success){
        $this->_success = $success;
    }

    public function sethttpstatuscode($httpstatuscode){
        $this->_httpstatuscode = $httpstatuscode;
    }

    public function addMessage($message){
        $this->_messages[] = $message;
    }

    public function setData($data){
        $this->_data = $data;
    }

    public function settoCache($tocache){
        $this->_tocache = $tocache;
    }
    
    public function send(){
        header('Content-type: application/json;charset:utf-8');
        if($this->_tocache == true){
            header('Cache-control: max-age=60');
        }else{
            header('Cache-control: no cache, no store');
        }
        if(($this->_success !== false && $this->_success !== true) || !is_numeric($this->_httpstatuscode)){
            http_response_code(500);

            $this->_responseData['statusCode'] = 500;
            $this->_responseData['success'] = false;
            $this->addMessage('Response Creation Error');
            $this->addMessage('Test Message');
            $this->_responseData['message'] = $this->_messages;
        }else{
            http_response_code($this->_httpstatuscode);
            $this->_responseData['statusCode'] = $this->_httpstatuscode;
            $this->_responseData['success'] = $this->_success;
            $this->_responseData['message'] = $this->_messages;
            $this->_responseData['data'] = $this->_data;
        }
        echo json_encode($this->_responseData);
    }
}


