<?php
include 'WebSocketServer.class.php';
require_once('WebSocketConnection.class.php');

class ChatWebServer extends WebSocketServer
{
    protected function process($conn, $message)
    {
        foreach ($this->connections as $conn)
        {
            $this->send($conn, $message);
        }
    }
    
    protected function connected($conn)
    {
        // Do nothing: This is just an echo server, there's no need to track the conn.
        // However, if we did care about the conns, we would probably have a cookie to
        // parse at this step, would be looking them up in permanent storage, etc.
    }
    
    protected function closed($conn)
    {
        // Do nothing: This is where cleanup would go, in case the conn had any sort of
        // open files or other objects associated with them.  This runs after the socket 
        // has been closed, so there is no need to clean up the socket itself here.
    }
}


$echo = new ChatWebServer("0.0.0.0", "8888");

try
{
    $echo->run();
}
catch (Exception $e)
{
    $echo->stdout($e->getMessage());
}
