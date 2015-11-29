<?php
include 'WebSocketServer.class.php';
require_once('WebSocketConnection.class.php');

class AsrWsConn extends WebSocketConnection
{
  public $auksocket;      // 与auk的连接socket
  public $aukurl;         // 接收客户端发来的url  
}

// 原来php接收的是url + body，现在不会再送url了，所以要先自行构造url
// FIXME：这里是模拟的原filetest.php实现，其实是有优化空间的，甚至可以直连decoder
class AsrServer extends WebSocketServer
{   
    protected $WsConnClass = 'AsrWsConn';    // 必须设置，php的反射超牛啊，C++很难做到！
        
    private $AsrUri = '/asr';
    private $AukIp = "192.168.14.7";
    private $AukPort = 18100;
    
    protected function checkUri($uri)
    {
        if($uri != $this->AsrUri)
        {
            $this->logError("checkUri $uri != $this->AsrUri");
            return false;
        }
        return true;
    }
    
    protected function process($conn, $message)
    {
        //$this->logInfo(strpos($message, "http"));
        $pos = strpos($message, "http");
        if($pos !== false)
        {
            $this->logInfo("recv url: $message");
            $conn->aukurl = $message;
       }    
        else // FIXME 这里模仿原filetest.php与AudioKeeper的交互,要求客户端先发url再发语音流
        {
            $this->logInfo("voice......");
            $cmd = pack("iii", 0, strlen($conn->aukurl), strlen($message));
            
            $conn->auksocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Could not create socket LZ\n"); 
            $result = socket_connect($conn->auksocket, $this->AukIp, $this->AukPort) or die("Could not connect to socket LZ\n"); 
            socket_write($conn->auksocket, $cmd);
            socket_write($conn->auksocket, $conn->aukurl, strlen($conn->aukurl));
            socket_write($conn->auksocket, $message, strlen($message));
            $du_msg = socket_read($conn->auksocket, 6144, PHP_BINARY_READ) or die("Could not read input LZ\n");
            $du_msg = trim($du_msg);
    
            socket_close($conn->auksocket);
            
            $this->send($conn, $du_msg, 'binary');  // 识别结果里有中文，因此设置成二进制传输
        }
    }
      
    protected function connected ($conn)
    {
    // Do nothing: This is just an echo server, there's no need to track the user.
    // However, if we did care about the users, we would probably have a cookie to
    // parse at this step, would be looking them up in permanent storage, etc.
    }
  
    protected function closed ($conn)
    {
    // Do nothing: This is where cleanup would go, in case the user had any sort of
    // open files or other objects associated with them.  This runs after the socket 
    // has been closed, so there is no need to clean up the socket itself here.
    }
}

$echo = new AsrServer("0.0.0.0", "9000");

try
{
    $echo->run();
}
catch (Exception $e)
{
    $echo->stdout($e->getMessage());
}
