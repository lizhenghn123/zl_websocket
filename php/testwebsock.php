<?php
include 'websockets.php';
require_once('users.php');

class echoServer extends WebSocketServer {
  //protected $maxBufferSize = 1048576; //1MB... overkill for an echo server, but potentially plausible for other applications.
  
  protected function process ($user, $message) {
    $this->send($user,$message);
  }
  
  protected function connected ($user) {
    // Do nothing: This is just an echo server, there's no need to track the user.
    // However, if we did care about the users, we would probably have a cookie to
    // parse at this step, would be looking them up in permanent storage, etc.
  }
  
  protected function closed ($user) {
    // Do nothing: This is where cleanup would go, in case the user had any sort of
    // open files or other objects associated with them.  This runs after the socket 
    // has been closed, so there is no need to clean up the socket itself here.
  }
}

// 原来php接收的是url + body，现在不会再送url了，所以要先自行构造url
///**
class AsrServer extends WebSocketServer
{   
    protected function process ($user, $message)
    {
        $auk_address = "192.168.14.7";
        $auk_port = 18100;
        //$this->stdout(strpos($message, "http"));
        //$this->stdout(strpos($message, "hdfgdfgfg"));
        $pos = strpos($message, "http");
        if($pos !== false)
        {
            $this->stdout("url......");
            $this->stdout("recv . " . $message);    
            $user->url = $message;
            $user->auksocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Could not create socket LZ\n"); 
            $result = socket_connect($user->auksocket, $auk_address, $auk_port) or die("Could not connect to socket LZ\n"); 
        }    
        else
        {
            $this->stdout("voice......");
            $cmd = pack("iii", 0, strlen($user->url), strlen($message));
            socket_write($user->auksocket, $cmd);
            socket_write($user->auksocket, $user->url, strlen($user->url));
            socket_write($user->auksocket, $message, strlen($message));
            $du_msg = socket_read($user->auksocket, 6144, PHP_BINARY_READ) or die("Could not read input LZ\n");
            $du_msg = trim($du_msg);
    
            socket_close($user->auksocket);
            
            $this->send($user, $du_msg, 'binary');  // 识别结果里有中文，因此设置成二进制传输
        }
    }
      
    protected function connected ($user)
    {
    // Do nothing: This is just an echo server, there's no need to track the user.
    // However, if we did care about the users, we would probably have a cookie to
    // parse at this step, would be looking them up in permanent storage, etc.
    }
  
    protected function closed ($user)
    {
    // Do nothing: This is where cleanup would go, in case the user had any sort of
    // open files or other objects associated with them.  This runs after the socket 
    // has been closed, so there is no need to clean up the socket itself here.
    }
}
//**/
//$echo = new echoServer("0.0.0.0","9000");
$echo = new AsrServer("0.0.0.0","9000");

try {
  $echo->run();
}
catch (Exception $e) {
  $echo->stdout($e->getMessage());
}

/**
    $service_port = 18100;
    $address = "192.168.14.7";
    $url_full  = "http://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']."?".$_SERVER["QUERY_STRING"];
    
function do_upload($url_full , $address , $service_port ) 
{
    $du_msg = "OK";
    $temp_name = $_FILES['upvoice']['tmp_name'];
    
    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Could not create socket LZ\n");  

#    socket_set_option($socket,SOL_SOCKET,SO_SNDTIMEO,array("sec"=>3, "usec"=>0 ) ); 
#    socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,array("sec"=>3, "usec"=>0 ) );

    $result = socket_connect($socket, $address, $service_port) or die("Could not connect to socket LZ\n");  
    
    $data = file_get_contents($temp_name);    
    if($data){
        $cmd = pack("iii", 0, strlen($url_full), strlen($data));
        socket_write($socket, $cmd);
        if(strlen($url_full) > 0)  socket_write($socket, $url_full, strlen($url_full));
        if(strlen($data) > 0)      socket_write($socket, $data, strlen($data));
    }
    
    #$du_msg = socket_read($socket,6144, PHP_NORMAL_READ) or die("Could not read input LZ\n");
    $du_msg = socket_read($socket,6144, PHP_BINARY_READ) or die("Could not read input LZ\n");
    $du_msg = trim($du_msg);
    
    socket_close($socket);
    unlink($temp_name);
    
    return $du_msg;
} 
**/