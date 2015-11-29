PHP WebSocketServer
==============

使用php写的websocket server。

包括：

1. php版 WebSocketServer；
2. 两个demo server：EchoWebServer和ChatWebServer；
3. 两个测试客户端：ChatClient.html和ChatClient.html；


-----------------------------------
使用方式：

如果需要假设server端，需要自行实现webserver，引用WebSocketServer.class.php和WebSocketConnection.class.php，
使你的server类继承自WebSocketServer类，并实现其中的纯虚函数：

    abstract protected function process($user, $message); // Called immediately when the data is recieved. 
    abstract protected function connected($user); 		  // Called after the handshake response is sent to the client.
    abstract protected function closed($user); 			  // Called after the connection is closed.
同时也可以按需继承WebSocketConnection， 增加你自己的业务处理。
 

这里以EchoWebServer为例，运行： 

	php /path/EchoWebServer.php  (linux)
或

	php.exe /path/EchoWebServer.php  (windows)
即启动了server端。此时可以通过测试程序进行测试验证。
