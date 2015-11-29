<?php
// reference : https://github.com/ghedipunk/PHP-Websockets
date_default_timezone_set("Asia/Shanghai");  // "PRC"

require_once('WebSocketConnection.class.php');

abstract class WebSocketServer
{
	// internal, see rfc
	const WS_FIN =  128;
	const WS_MASK = 128;

	const WS_OPCODE_CONTINUATION             = 0;
	const WS_OPCODE_TEXT                     = 1;
	const WS_OPCODE_BINARY                   = 2;
	const WS_OPCODE_CLOSE                    = 8;
	const WS_OPCODE_PING                     = 9;
	const WS_OPCODE_PONG                     = 10;

	const WS_PAYLOAD_LENGTH_16               = 126;
	const WS_PAYLOAD_LENGTH_63               = 127;

	const WS_READY_STATE_CONNECTING          = 0;
	const WS_READY_STATE_OPEN                = 1;
	const WS_READY_STATE_CLOSING             = 2;
	const WS_READY_STATE_CLOSED              = 3;

	const WS_STATUS_NORMAL_CLOSE             = 1000;
	const WS_STATUS_GONE_AWAY                = 1001;
	const WS_STATUS_PROTOCOL_ERROR           = 1002;
	const WS_STATUS_UNSUPPORTED_MESSAGE_TYPE = 1003;
	const WS_STATUS_MESSAGE_TOO_BIG          = 1004;

	const WS_STATUS_TIMEOUT                  = 3000;

    protected $WsConnClass = 'WebSocketConnection'; // 如果继承了WebSocketConnection类，请重定义该值
    protected $maxBufferSize;
    protected $master;
    protected $sockets = array();
    protected $connections = array();
    protected $heldMessages = array();
	protected $logLevel    = 1;   	// 0:disable log; 1:debug; 2:info; 3:warn; 4:error; 5:fatal
    protected $headerOriginRequired = false;
    protected $headerSecWebSocketProtocolRequired = false;
    protected $headerSecWebSocketExtensionsRequired = false;
	// client连上之后的超时时间，超过该时间但仍没有数据或者ping请求过来时就断开连接，设置为小于等于0时不做检测
	protected $WS_TIMEOUT_RECV = 10;
	// 如果server主动发出ping时client端回复的超时时间，超时没收到client的pong回复时就端口连接，，设置为小于等于0时server不主动发ping请求
	protected $WS_TIMEOUT_PONG = 5;

    function __construct($addr, $port, $bufferLength = 2048)
    {
        $this->maxBufferSize = $bufferLength;
        $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)  or die("Failed: socket_create()");
        socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1) or die("Failed: socket_option()");
        socket_bind($this->master, $addr, $port) 					  or die("Failed: socket_bind()");
        socket_listen($this->master, 1024) 							  or die("Failed: socket_listen()");
        $this->sockets['m'] = $this->master;
        $this->logInfo("\nServer started\nListening on: $addr:$port\nMaster socket: " . $this->master);
    }
    
    abstract protected function connected($conn); 		  // Called after the handshake response is sent to the client.    
    abstract protected function process($conn, $message); // Called immediately when the data is recieved. 
    abstract protected function closed($conn); 			  // Called after the connection is closed.
    
    protected function connecting($conn)
    {
        // Override to handle a connecting conn, after the instance of the conn is created, but before
        // the handshake has completed.
    }
      
    protected function send($conn, $message, $messageType = 'text')
    {
        if ($conn->handshake)
        {
            $message = $this->frame($message, $conn, $messageType);   // 发回给client的数据先打包            
            $result = @socket_write($conn->socket, $message, strlen($message));
        }
        else	// 如果只是建立连接但client尚未发起握手请求
        {
            $holdingMessage = array(
                'conn' => $conn,
                'message' => $message
            );
            $this->heldMessages[] = $holdingMessage;
        }
    }
    
    protected function tick()
    {
        // Override this for any process that should happen periodically.  Will happen at least once
        // per second, but possibly more often.
    }
    
    protected function _tick()
    {
        // Core maintenance processes, such as retrying failed messages.
        foreach ($this->heldMessages as $key => $hm)
        {
            $found = false;
            foreach ($this->connections as $currConnnection)
            {
                if ($hm['conn']->socket == $currConnnection->socket)
                {
                    $found = true;
                    if ($currConnnection->handshake)
                    {
                        unset($this->heldMessages[$key]);
                        $this->send($currConnnection, $hm['message']);
                    }
                }
            }
            if (!$found)
            {
                // If they're no longer in the list of connected connections, drop the message.
                unset($this->heldMessages[$key]);
            }
        }
    }
    
    /// Main processing loop, use select poller
    public function run()
    {
        while (true)
        {
            if (empty($this->sockets))
            {
                $this->sockets['m'] = $this->master;
            }
            $read  = $this->sockets;
            $write = $except = null;
            $this->_tick();
            $this->tick();
            $num_sockets = @socket_select($read, $write, $except, NULL);
			//$this->logInfo("socket_select $num_sockets");
            foreach ($read as $socket)
            {
                if ($socket == $this->master)
                {
                    $client = socket_accept($socket);
                    if ($client < 0)
                    {
                        $this->logError("Failed: socket_accept()");
                        continue;
                    }
                    else
                    {
                        $this->connect($client);
                        $this->logInfo("Client connected. " . $client);
                    }
                }
                else
                {
                    $numBytes = @socket_recv($socket, $buffer, $this->maxBufferSize, 0);
                    if ($numBytes === false)
                    {
                        $sockErrNo = socket_last_error($socket);
                        switch ($sockErrNo)
                        {
                            case 102: // ENETRESET    -- Network dropped connection because of reset
                            case 103: // ECONNABORTED -- Software caused connection abort
                            case 104: // ECONNRESET   -- Connection reset by peer
                            case 108: // ESHUTDOWN    -- Cannot send after transport endpoint shutdown -- probably more of an error on our part, if we're trying to write after the socket is closed.  Probably not a critical error, though.
                            case 110: // ETIMEDOUT    -- Connection timed out
                            case 111: // ECONNREFUSED -- Connection refused -- We shouldn't see this one, since we're listening... Still not a critical error.
                            case 112: // EHOSTDOWN    -- Host is down -- Again, we shouldn't see this, and again, not critical because it's just one connection and we still want to listen to/for others.
                            case 113: // EHOSTUNREACH -- No route to host
                            case 121: // EREMOTEIO    -- Rempte I/O error -- Their hard drive just blew up.
                            case 125: // ECANCELED    -- Operation canceled
                                
                                $this->logError("Unusual disconnect on socket " . $socket);
                                $this->disconnect($socket, true, $sockErrNo); // disconnect before clearing error, in case someone with their own implementation wants to check for error conditions on the socket.
                                break;
                            default:
                                
                                $this->logError('Socket error: ' . socket_strerror($sockErrNo));
                        }
                        
                    }
                    elseif ($numBytes == 0)
                    {
                        $this->disconnect($socket);
                        $this->logWarn("Client disconnected. TCP connection lost: " . $socket);
                    }
                    else
                    {
                        $conn = $this->getConnBySocket($socket);
                        if (!$conn->handshake)
                        {
                            $tmp = str_replace("\r", '', $buffer);
                            if (strpos($tmp, "\n\n") === false)
                            {
                                continue; // 等client发完消息头后再处理
                            }
                            $this->doHandshake($conn, $buffer);
                        }
                        else
                        {
                            //split packet into frame and send it to deframe
                            $this->split_packet($numBytes, $buffer, $conn);
                        }
                    }
                }
            }
        }
    }
    
    protected function connect($socket)
    {
        $conn = new $this->WsConnClass(uniqid('u'), $socket);
        $this->connections[$conn->id] = $conn;
        $this->sockets[$conn->id] = $socket;
        $this->connecting($conn);
    }
    
    protected function disconnect($socket, $triggerClosed = true, $sockErrNo = null)
    {
        $disconnectedconn = $this->getConnBySocket($socket);
        
        if ($disconnectedconn !== null)
        {
            unset($this->connections[$disconnectedconn->id]);
            
            if (array_key_exists($disconnectedconn->id, $this->sockets))
            {
                unset($this->sockets[$disconnectedconn->id]);
            }
            
            if (!is_null($sockErrNo))
            {
                socket_clear_error($socket);
            }
            
            if ($triggerClosed)
            {
                $this->closed($disconnectedconn);
                socket_close($disconnectedconn->socket);
            }
            else
            {
                $message = $this->frame('', $disconnectedconn, 'close');
                @socket_write($disconnectedconn->socket, $message, strlen($message));
            }
        }
    }
    
    protected function doHandshake($conn, $buffer)
    {
        $this->logInfo("client handshake request[\n$buffer]");
        $magicGUID = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";
        $headers   = array();
        $lines     = explode("\n", $buffer);
        foreach ($lines as $line)
        {
            if (strpos($line, ":") !== false)
            {
                $header                                = explode(":", $line, 2);
                $headers[strtolower(trim($header[0]))] = trim($header[1]);
            }
            elseif (stripos($line, "get ") !== false)
            {
                preg_match("/GET (.*) HTTP/i", $buffer, $reqResource);
                $headers['get'] = trim($reqResource[1]);
                $conn->path = $headers['get'];  // websocket only use Http::GET
                $this->logInfo("client request uri = [$conn->path]");
            }
        }
        
        if (!isset($headers['get']) || !$this->checkUri($headers['get']))
        {          
            $handshakeResponse = "HTTP/1.1 405 Method Not Allowed\r\n\r\n";
        }
        if (!isset($headers['host']) || !$this->checkHost($headers['host']))
        {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['upgrade']) || strtolower($headers['upgrade']) != 'websocket')
        {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['connection']) || strpos(strtolower($headers['connection']), 'upgrade') === FALSE)
        {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['sec-websocket-key']))
        {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (!isset($headers['sec-websocket-version']) || strtolower($headers['sec-websocket-version']) != 13)
        {
            $handshakeResponse = "HTTP/1.1 426 Upgrade Required\r\nSec-WebSocketVersion: 13";
        }
        if (($this->headerOriginRequired && !isset($headers['origin'])) || ($this->headerOriginRequired && !$this->checkOrigin($headers['origin'])))
        {
            $handshakeResponse = "HTTP/1.1 403 Forbidden";
        }
        if (($this->headerSecWebSocketProtocolRequired && !isset($headers['sec-websocket-protocol'])) || ($this->headerSecWebSocketProtocolRequired && !$this->checkWebsocProtocol($headers['sec-websocket-protocol'])))
        {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }
        if (($this->headerSecWebSocketExtensionsRequired && !isset($headers['sec-websocket-extensions'])) || ($this->headerSecWebSocketExtensionsRequired && !$this->checkWebsocExtensions($headers['sec-websocket-extensions'])))
        {
            $handshakeResponse = "HTTP/1.1 400 Bad Request";
        }

        // 如果上面设置了$handshakeResponse说明握手失败，主动关闭
        if (isset($handshakeResponse))
        {
            socket_write($conn->socket, $handshakeResponse, strlen($handshakeResponse));
            $this->disconnect($conn->socket);
            return;
        }

        $conn->headers   = $headers;
        $conn->handshake = $buffer;
        
        $webSocketKeyHash = sha1($headers['sec-websocket-key'] . $magicGUID);
        
        $rawToken = "";
        for ($i = 0; $i < 20; $i++)
        {
            $rawToken .= chr(hexdec(substr($webSocketKeyHash, $i * 2, 2)));
        }
        $handshakeToken = base64_encode($rawToken) . "\r\n";
        
        $subProtocol = (isset($headers['sec-websocket-protocol'])) ? $this->processProtocol($headers['sec-websocket-protocol']) : "";
        $extensions  = (isset($headers['sec-websocket-extensions'])) ? $this->processExtensions($headers['sec-websocket-extensions']) : "";
        
        $handshakeResponse = "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $handshakeToken$subProtocol$extensions\r\n";
        $this->logInfo("client handshake response[\n$handshakeResponse]");
        socket_write($conn->socket, $handshakeResponse, strlen($handshakeResponse));
        $this->connected($conn);
    }
    
    protected function checkHost($hostName)
    {
        return true; // Override and return false if the host is not one that you would expect.
    }
    
    protected function checkUri($uri)
    {
        return true; // Override and return false if the uri is not one that you would expect.
    }
    
    protected function checkOrigin($origin)
    {
        return true; // Override and return false if the origin is not one that you would expect.
    }
    
    protected function checkWebsocProtocol($protocol)
    {
        return true; // Override and return false if a protocol is not found that you would expect.
    }
    
    protected function checkWebsocExtensions($extensions)
    {
        return true; // Override and return false if an extension is not found that you would expect.
    }
    
    protected function processProtocol($protocol)
    {
        return ""; // return either "Sec-WebSocket-Protocol: SelectedProtocolFromClientList\r\n" or return an empty string.  
        // The carriage return/newline combo must appear at the end of a non-empty string, and must not
        // appear at the beginning of the string nor in an otherwise empty string, or it will be considered part of 
        // the response body, which will trigger an error in the client as it will not be formatted correctly.
    }
    
    protected function processExtensions($extensions)
    {
        return ""; // return either "Sec-WebSocket-Extensions: SelectedExtensions\r\n" or return an empty string.
    }
    
    protected function getConnBySocket($socket)
    {
        foreach ($this->connections as $conn)
        {
            if ($conn->socket == $socket)
            {
                return $conn;
            }
        }
        return null;
    }
    
    protected function frame($message, $conn, $messageType = 'text', $messageContinues = false)
    {
        switch ($messageType)
        {
            case 'continuous':
                $b1 = 0;
                break;
            case 'text':
                $b1 = ($conn->sendingContinuous) ? 0 : 1;
                break;
            case 'binary':
                $b1 = ($conn->sendingContinuous) ? 0 : 2;
                break;
            case 'close':
                $b1 = 8;
                break;
            case 'ping':
                $b1 = 9;
                break;
            case 'pong':
                $b1 = 10;
                break;
        }
        if ($messageContinues)
        {
            $conn->sendingContinuous = true;
        }
        else
        {
            $b1 += 128;
            $conn->sendingContinuous = false;
        }
        
        $length      = strlen($message);
        $lengthField = "";
        if ($length < 126)
        {
            $b2 = $length;
        }
        elseif ($length <= 65536)
        {
            $b2        = 126;
            $hexLength = dechex($length);
            //$this->logInfo("Hex Length: $hexLength");
            if (strlen($hexLength) % 2 == 1)
            {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;
            
            for ($i = $n; $i >= 0; $i = $i - 2)
            {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 2)
            {
                $lengthField = chr(0) . $lengthField;
            }
        }
        else
        {
            $b2        = 127;
            $hexLength = dechex($length);
            if (strlen($hexLength) % 2 == 1)
            {
                $hexLength = '0' . $hexLength;
            }
            $n = strlen($hexLength) - 2;
            
            for ($i = $n; $i >= 0; $i = $i - 2)
            {
                $lengthField = chr(hexdec(substr($hexLength, $i, 2))) . $lengthField;
            }
            while (strlen($lengthField) < 8)
            {
                $lengthField = chr(0) . $lengthField;
            }
        }
        
        return chr($b1) . chr($b2) . $lengthField . $message;
    }
    
    //check packet if he have more than one frame and process each frame individually
    protected function split_packet($length, $packet, $conn)
    {
        //add PartialPacket and calculate the new $length
        if ($conn->handlingPartialPacket)
        {
            $packet                      = $conn->partialBuffer . $packet;
            $conn->handlingPartialPacket = false;
            $length                      = strlen($packet);
        }
        $fullpacket = $packet;
        $frame_pos  = 0;
        $frame_id   = 1;
        
        while ($frame_pos < $length)
        {
            $headers      = $this->extractHeaders($packet);
            $headers_size = $this->calcoffset($headers);
            $framesize    = $headers['length'] + $headers_size;
            
            //split frame from packet and process it
            $frame = substr($fullpacket, $frame_pos, $framesize);
            
            if (($message = $this->deframe($frame, $conn, $headers)) !== FALSE)
            {
                if ($conn->hasSentClose)
                {
                    $this->disconnect($conn->socket);
                }
                else
                {
                    //if (preg_match('//u', $message))   // 不再检测utf8，FIXME
                    {
                        //$this->logInfo("Is UTF-8\n".$message); 
						//$this->logInfo("message : $message");
                        $this->process($conn, $message);
                    }
					//else
					{
					//	$this->logError("not UTF-8\n");
                    }
                }
            }
            //get the new position also modify packet data
            $frame_pos += $framesize;
            $packet = substr($fullpacket, $frame_pos);
            $frame_id++;
        }
    }
    
    protected function calcoffset($headers)
    {
        $offset = 2;
        if ($headers['hasmask'])
        {
            $offset += 4;
        }
        if ($headers['length'] > 65535)
        {
            $offset += 8;
        }
        elseif ($headers['length'] > 125)
        {
            $offset += 2;
        }
        return $offset;
    }
    
    protected function deframe($message, &$conn)
    {
        //echo $this->strtohex($message);
        $headers   = $this->extractHeaders($message);
        $pongReply = false;
        $willClose = false;
        switch ($headers['opcode'])
        {
            case 0:
            case 1:
            case 2:
                break;
            case 8:
                // todo: close the connection
                $conn->hasSentClose = true;
                return "";
            case 9:
                $pongReply = true;
            case 10:
                break;
            default:
                //$this->disconnect($conn); // todo: fail connection
                $willClose = true;
                break;
        }
        
        /* Deal by split_packet() as now deframe() do only one frame at a time.
        if ($conn->handlingPartialPacket) {
        $message = $conn->partialBuffer . $message;
        $conn->handlingPartialPacket = false;
        return $this->deframe($message, $conn);
        }
        */
        
        if ($this->checkRSVBits($headers, $conn))
        {
            return false;
        }
        
        if ($willClose)
        {
            // todo: fail the connection
            return false;
        }
        
        $payload = $conn->partialMessage . $this->extractPayload($message, $headers);
        
        if ($pongReply)
        {
            $reply = $this->frame($payload, $conn, 'pong');
            socket_write($conn->socket, $reply, strlen($reply));
            return false;
        }
        if (extension_loaded('mbstring'))
        {
            if ($headers['length'] > mb_strlen($this->applyMask($headers, $payload)))
            {
                $conn->handlingPartialPacket = true;
                $conn->partialBuffer         = $message;
                return false;
            }
        }
        else
        {
            if ($headers['length'] > strlen($this->applyMask($headers, $payload)))
            {
                $conn->handlingPartialPacket = true;
                $conn->partialBuffer         = $message;
                return false;
            }
        }
        
        $payload = $this->applyMask($headers, $payload);
        
        if ($headers['fin'])
        {
            $conn->partialMessage = "";
            return $payload;
        }
        $conn->partialMessage = $payload;
        return false;
    }
    
    protected function extractHeaders($message)
    {
        $header           = array(
            'fin' => $message[0] & chr(128),
            'rsv1' => $message[0] & chr(64),
            'rsv2' => $message[0] & chr(32),
            'rsv3' => $message[0] & chr(16),
            'opcode' => ord($message[0]) & 15,
            'hasmask' => $message[1] & chr(128),
            'length' => 0,
            'mask' => ""
        );
        $header['length'] = (ord($message[1]) >= 128) ? ord($message[1]) - 128 : ord($message[1]);
        
        if ($header['length'] == 126)
        {
            if ($header['hasmask'])
            {
                $header['mask'] = $message[4] . $message[5] . $message[6] . $message[7];
            }
            $header['length'] = ord($message[2]) * 256 + ord($message[3]);
        }
        elseif ($header['length'] == 127)
        {
            if ($header['hasmask'])
            {
                $header['mask'] = $message[10] . $message[11] . $message[12] . $message[13];
            }
            $header['length'] = ord($message[2]) * 65536 * 65536 * 65536 * 256 + ord($message[3]) * 65536 * 65536 * 65536 + ord($message[4]) * 65536 * 65536 * 256 + ord($message[5]) * 65536 * 65536 + ord($message[6]) * 65536 * 256 + ord($message[7]) * 65536 + ord($message[8]) * 256 + ord($message[9]);
        }
        elseif ($header['hasmask'])
        {
            $header['mask'] = $message[2] . $message[3] . $message[4] . $message[5];
        }
        //echo $this->strtohex($message);
        //$this->printHeaders($header);
        return $header;
    }
    
    protected function extractPayload($message, $headers)
    {
        $offset = 2;
        if ($headers['hasmask'])
        {
            $offset += 4;
        }
        if ($headers['length'] > 65535)
        {
            $offset += 8;
        }
        elseif ($headers['length'] > 125)
        {
            $offset += 2;
        }
        return substr($message, $offset);
    }
    
    protected function applyMask($headers, $payload)
    {
        $effectiveMask = "";
        if ($headers['hasmask'])
        {
            $mask = $headers['mask'];
        }
        else
        {
            return $payload;
        }
        
        while (strlen($effectiveMask) < strlen($payload))
        {
            $effectiveMask .= $mask;
        }
        while (strlen($effectiveMask) > strlen($payload))
        {
            $effectiveMask = substr($effectiveMask, 0, -1);
        }
        return $effectiveMask ^ $payload;
    }
    protected function checkRSVBits($headers, $conn) // override this method if you are using an extension where the RSV bits are used.
    {
        if (ord($headers['rsv1']) + ord($headers['rsv2']) + ord($headers['rsv3']) > 0)
        {
            //$this->disconnect($conn); // todo: fail connection
            return true;
        }
        return false;
    }
    
    protected function strtohex($str)
    {
        $strout = "";
        for ($i = 0; $i < strlen($str); $i++)
        {
            $strout .= (ord($str[$i]) < 16) ? "0" . dechex(ord($str[$i])) : dechex(ord($str[$i]));
            $strout .= " ";
            if ($i % 32 == 7)
            {
                $strout .= ": ";
            }
            if ($i % 32 == 15)
            {
                $strout .= ": ";
            }
            if ($i % 32 == 23)
            {
                $strout .= ": ";
            }
            if ($i % 32 == 31)
            {
                $strout .= "\n";
            }
        }
        return $strout . "\n";
    }
    
    protected function printHeaders($headers)
    {
        echo "Array\n(\n";
        foreach ($headers as $key => $value)
        {
            if ($key == 'length' || $key == 'opcode')
            {
                echo "\t[$key] => $value\n\n";
            }
            else
            {
                echo "\t[$key] => " . $this->strtohex($value) . "\n";
                
            }
            
        }
        echo ")\n";
    }

	public function logDebug($message)
    {
        if ($this->logLevel != 0 && $this->logLevel <= 1)
        {
			$this->log('DEBUG', $message);
        }
    }
    
    public function logInfo($message)
    {
        if ($this->logLevel != 0 && $this->logLevel <= 2)
        {
			$this->log('INFO ', $message);
        }
    }
    
    public function logWarn($message)
    {
        if ($this->logLevel != 0 && $this->logLevel <= 3)
        {
			$this->log('WARN ', $message);
        }
    }
        
    public function logError($message)
    {
        if ($this->logLevel != 0 && $this->logLevel <= 4)
        {
			$this->log('ERROR', $message);
        }
    }
    
    public function logFatal($message)
    {
        if ($this->logLevel != 0 && $this->logLevel <= 5)
        {
			$this->log('FATAL', $message);
        }
    }
	
	protected function log($level = 'INFO', $msg)
	{
		//echo $msg;		
		echo date('Y-m-d H:i:s') . " [{$level}] {$msg}\n";
		//$msg = explode("\n", $msg);
		//foreach($msg as $line)
		//	echo date('Y-m-d H:i:s') . " {$type}: {$line}\n";			
	}
	
}
