<?php

/*

	Copyright (c) 2009-2019 F3::Factory/Bong Cosca, All rights reserved.

	This file is part of the Fat-Free Framework (http://fatfreeframework.com).

	This is free software: you can redistribute it and/or modify it under the
	terms of the GNU General Public License as published by the Free Software
	Foundation, either version 3 of the License, or later.

	Fat-Free Framework is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
	General Public License for more details.

	You should have received a copy of the GNU General Public License along
	with Fat-Free Framework.  If not, see <http://www.gnu.org/licenses/>.

*/

namespace F3\CLI;


//! RFC6455 WebSocket server
use JetBrains\PhpStorm\NoReturn;

class WS {

	const
		//! UUID magic string
		Magic='258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
		//! Max packet size
		Packet=65536;

	//@{ Mask bits for first byte of header
	const
		Text=0x01,
		Binary=0x02,
		Close=0x08,
		Ping=0x09,
		Pong=0x0a,
		OpCode=0x0f,
		Finale=0x80;
	//@}

	//@{ Mask bits for second byte of header
	const
		Length=0x7f;
	//@}

	protected
		$addr,
		$ctx,
		$wait,
		$sockets,
		$protocol,
		$agents=[],
		$events=[];

	/**
	*	Allocate stream socket
	*	@return NULL
	*	@param $socket resource
	**/
	function alloc($socket) {
        $buf = $this->read($socket);
		if (is_bool($buf)) {
            return;
        }
		// Get WebSocket headers
		$hdrs=[];
		$EOL="\r\n";
		$verb=NULL;
		$uri=NULL;
		foreach (explode($EOL,trim($buf)) as $line)
			if (preg_match('/^(\w+)\s(.+)\sHTTP\/[\d.]{1,3}$/',
				trim($line),$match)) {
				$verb=$match[1];
				$uri=$match[2];
			}
			else
			if (preg_match('/^(.+): (.+)/',trim($line),$match))
				// Standardize header
				$hdrs[
					strtr(
						ucwords(
							strtolower(
								strtr($match[1],'-',' ')
							)
						),' ','-'
					)
				]=$match[2];
			else {
				$this->close($socket);
				return;
			}
		if (empty($hdrs['Upgrade']) &&
			empty($hdrs['Sec-Websocket-Key'])) {
			// Not a WebSocket request
			if ($verb && $uri)
				$this->write(
					$socket,
					'HTTP/1.1 400 Bad Request'.$EOL.
					'Connection: close'.$EOL.$EOL
				);
			$this->close($socket);
			return;
		}
		// Handshake
		$buf='HTTP/1.1 101 Switching Protocols'.$EOL.
			'Upgrade: websocket'.$EOL.
			'Connection: Upgrade'.$EOL;
		if (isset($hdrs['Sec-Websocket-Protocol']))
			$buf.='Sec-WebSocket-Protocol: '.
				$hdrs['Sec-Websocket-Protocol'].$EOL;
		$buf.='Sec-WebSocket-Accept: '.
			base64_encode(
				sha1($hdrs['Sec-Websocket-Key'].WS::Magic,TRUE)
			).$EOL.$EOL;
		if ($this->write($socket,$buf)) {
			// Connect agent to server
			$this->sockets[(int)$socket]=$socket;
			$this->agents[(int)$socket]=
				new Agent($this,$socket,$verb,$uri,$hdrs);
		}
	}

	/**
	*	Close stream socket
	*	@return NULL
	*	@param $socket resource
	**/
	function close($socket) {
		if (isset($this->agents[(int)$socket]))
			unset($this->sockets[(int)$socket],$this->agents[(int)$socket]);
		stream_socket_shutdown($socket,STREAM_SHUT_WR);
		@fclose($socket);
	}

	/**
	*	Read from stream socket
	*	@return string|FALSE
	*	@param $socket resource
	*	@param $len int
	**/
	function read($socket,$len=0) {
		if (!$len)
			$len=WS::Packet;
		if (is_string($buf=@fread($socket,$len)) &&
			strlen($buf) && strlen($buf)<$len)
			return $buf;
		if (isset($this->events['error']) &&
			is_callable($func=$this->events['error']))
			$func($this);
		$this->close($socket);
		return FALSE;
	}

	/**
	*	Write to stream socket
	*	@return int|FALSE
	*	@param $socket resource
	*	@param $buf string
	**/
	function write($socket,$buf) {
		for ($i=0,$bytes=0;$i<strlen($buf);$i+=$bytes) {
			if (($bytes=@fwrite($socket,substr($buf,$i))) &&
				@fflush($socket))
				continue;
			if (isset($this->events['error']) &&
				is_callable($func=$this->events['error']))
				$func($this);
			$this->close($socket);
			return FALSE;
		}
		return $bytes;
	}

	/**
	*	Return socket agents
	*	@return array
	*	@param $uri string
	***/
	function agents($uri=NULL) {
		return array_filter(
			$this->agents,
			/**
			 * @var $val Agent
			 * @return bool
			 */
			function($val) use($uri) {
                # return $uri?($val->uri()==$uri):TRUE;
				return !$uri || $val->uri() == $uri;
			}
		);
	}

	/**
	*	Return event handlers
	*	@return array
	**/
	function events() {
		return $this->events;
	}

	/**
	*	Bind function to event handler
	*	@return object
	*	@param $event string
	*	@param $func callable
	**/
	function on($event,$func) {
		$this->events[$event]=$func;
		return $this;
	}

	/**
	*	Terminate server
	**/
	#[NoReturn] function kill() {
		die;
	}

	/**
	*	Execute the server process
	**/
	#[NoReturn] function run() {
		// Assign signal handlers
		declare(ticks=1);
		pcntl_signal(SIGINT,[$this,'kill']);
		pcntl_signal(SIGTERM,[$this,'kill']);
		gc_enable();
		// Activate WebSocket listener
		$listen=stream_socket_server(
			$this->addr,$errno,$errstr,
			STREAM_SERVER_BIND|STREAM_SERVER_LISTEN,
			$this->ctx
		);
		$socket=socket_import_stream($listen);
		register_shutdown_function(function() use($listen) {
			foreach ($this->sockets as $socket) {
                if ($socket != $listen) {
                    $this->close($socket);
                }
            }
			$this->close($listen);
			if (isset($this->events['stop'])
                && is_callable($func=$this->events['stop'])
            ) {
                $func($this);
            }
		});
		if ($errstr) {
            user_error($errstr, E_USER_ERROR);
        }
		if (isset($this->events['start'])
            && is_callable($func=$this->events['start'])
        ) {
            $func($this);
        }
		$this->sockets=[(int)$listen=>$listen];
		$empty=[];
		$wait=$this->wait;
		while (TRUE) {
			$active=$this->sockets;
			$mark=microtime(TRUE);
			$count=@stream_select(
				$active,$empty,$empty,(int)$wait,round(1e6*($wait-(int)$wait))
			);
			if (is_bool($count) && $wait) {
				if (isset($this->events['error'])
                    && is_callable($func=$this->events['error'])
                ) {
                    $func($this);
                }
				die;
			}
			if ($count) {
				// Process active connections
				foreach ($active as $socket) {
					if (!is_resource($socket)) {
                        continue;
                    }
					if ($socket==$listen) {
						if ($socket=@stream_socket_accept($listen,0)) {
                            $this->alloc($socket);
                        }
						else {
                            if (isset($this->events['error'])
                                && is_callable($func = $this->events['error'])
                            ) {
                                $func($this);
                            }
                        }
					}
					else {
						$id=(int)$socket;
						if (isset($this->agents[$id])){
							$this->agents[$id]->fetch();
                        }
					}
				}
				$wait-=microtime(TRUE)-$mark;
				while ($wait<1e-6) {
					$wait+=$this->wait;
					$count=0;
				}
			}
			if (!$count) {
				$mark=microtime(TRUE);
				foreach ($this->sockets as $id=>$socket) {
					if (!is_resource($socket)) {
                        continue;
                    }
					if ($socket!=$listen
                        && isset($this->agents[$id])
                        && isset($this->events['idle'])
                        && is_callable($func=$this->events['idle'])
                    ) {
                        $func($this->agents[$id]);
                    }
				}
				$wait=$this->wait-microtime(TRUE)+$mark;
			}
			gc_collect_cycles();
		}
	}

	/**
	*	@param $addr string
	*	@param $ctx resource
	*	@param $wait int
	**/
	function __construct($addr,$ctx=NULL,$wait=60) {
		$this->addr=$addr;
		$this->ctx=$ctx?:stream_context_create();
		$this->wait=$wait;
		$this->events=[];
	}

}
