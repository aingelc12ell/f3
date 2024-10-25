<?php

namespace F3\CLI;

//! RFC6455 remote socket

class Agent {

    protected
        $server,
        $id,
        $socket,
        $flag,
        $verb,
        $uri,
        $headers;

    /**
     *	Return server instance
     *	@return WS
     **/
    function server() {
        return $this->server;
    }

    /**
     *	Return socket ID
     *	@return string
     **/
    function id() {
        return $this->id;
    }

    /**
     *	Return socket
     *	@return resource
     **/
    function socket() {
        return $this->socket;
    }

    /**
     *	Return request method
     *	@return string
     **/
    function verb() {
        return $this->verb;
    }

    /**
     *	Return request URI
     *	@return string
     **/
    function uri() {
        return $this->uri;
    }

    /**
     *	Return socket headers
     *	@return array
     **/
    function headers() {
        return $this->headers;
    }

    /**
     *	Frame and transmit payload
     *	@return string|FALSE
     *	@param $op int
     *	@param $data string
     **/
    function send($op,$data='') {
        $server=$this->server;
        $mask=WS::Finale | $op & WS::OpCode;
        $len=strlen($data);
        $buf='';
        if ($len>0xffff) {
            $buf = pack('CCNN', $mask, 0x7f, $len);
        }
        elseif ($len>0x7d) {
            $buf = pack('CCn', $mask, 0x7e, $len);
        }
        else {
            $buf = pack('CC', $mask, $len);
        }
        $buf.=$data;
        if (is_bool($server->write($this->socket,$buf))) {
            return FALSE;
        }
        $events = $this->server->events();
        if (!in_array($op,[WS::Pong,WS::Close])
            && isset($events['send'])
            && is_callable($func = $events['send'])
        ) {
            $func($this, $op, $data);
        }
        return $data;
    }

    /**
     *	Retrieve and unmask payload
     *	@return bool|NULL
     **/
    function fetch() {
        // Unmask payload
        $server=$this->server;
        $buf=$server->read($this->socket);
        if (is_bool($buf)) {
            return FALSE;
        }
        while($buf) {
            $op=ord($buf[0]) & WS::OpCode;
            $len=ord($buf[1]) & WS::Length;
            $pos=2;
            if ($len==0x7e) {
                $len=ord($buf[2])*256+ord($buf[3]);
                $pos+=2;
            }
            else {
                if ($len == 0x7f) {
                    for ($i = 0, $len = 0; $i < 8; ++$i)
                        $len = $len * 256 + ord($buf[$i + 2]);
                    $pos += 8;
                }
            }
            for ($i=0,$mask=[];$i<4;++$i) {
                $mask[$i] = ord($buf[$pos + $i]);
            }
            $pos+=4;
            if (strlen($buf)<$len+$pos) {
                return FALSE;
            }
            for ($i=0,$data='';$i<$len;++$i) {
                $data .= chr(ord($buf[$pos + $i]) ^ $mask[$i % 4]);
            }
            // Dispatch
            switch ($op & WS::OpCode) {
                case WS::Ping:
                    $this->send(WS::Pong);
                    break;
                case WS::Close:
                    $server->close($this->socket);
                    break;
                case WS::Text:
                    $data=trim($data);
                // no break
                case WS::Binary:
                    $events = $this->server->events();
                    if (isset($events['receive']) &&
                        is_callable($func = $events['receive']))
                        $func($this,$op,$data);
                    break;
            }
            $buf = substr($buf, $len+$pos);
        }
    }

    /**
     *	Destroy object
     **/
    function __destruct() {
        $events = $this->server->events();
        if (isset($events['disconnect'])
            && is_callable($func=$events['disconnect'])
        ) {
            $func($this);
        }
    }

    /**
     *	@param $server WS
     *	@param $socket resource
     *	@param $verb string
     *	@param $uri string
     *	@param $hdrs array
     **/
    function __construct($server,$socket,$verb,$uri,array $hdrs) {
        $this->server=$server;
        $this->id=stream_socket_get_name($socket,TRUE);
        $this->socket=$socket;
        $this->verb=$verb;
        $this->uri=$uri;
        $this->headers=$hdrs;

        $events = $server->events();
        if (isset($events['connect'])
            && is_callable($func=$events['connect'])
        ) {
            $func($this);
        }
    }

}
