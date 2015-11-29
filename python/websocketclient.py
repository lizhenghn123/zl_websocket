#!/usr/bin/env python
# coding=utf-8
# author : lizhenghn@gmail.com
import thread
import time

# You should install websocket-client, such as : pip install websocket-client
# or see git clone https://github.com/liris/websocket-client and python setup.py install
import websocket

def test_websocket_basic():
    print "start wc client"
    try:
        websocket.enableTrace(True)
        ws = websocket.create_connection("ws://192.168.14.7:8888/echo")
        print "create ws client success"
        #time.sleep(1)
        loop = 5
        while loop > 0:
            print "Sending 'Hello,World'..."
            ws.send("Hello,World_%d" % loop)
            print "Sented"
            print "Receiving..."
            result =  ws.recv()
            print "Received '%s'" % result
            loop = loop -1
        ws.close()	
    except Exception, e:
        print e


def on_message(ws, message):
    print "on_message : [%s]" % message
def on_error(ws, error):
    print "on_error : [%s]" % error
def on_close(ws):
    print "on_close"
def on_open(ws):
    def run(*args):
        for i in range(3):
            time.sleep(1)
            ws.send("Hello_%d" % i)
        time.sleep(1)
        ws.close()
        print "thread terminating..."
    thread.start_new_thread(run, ())
def test_websocket_advance():
    websocket.enableTrace(True)
    ws = websocket.WebSocketApp("ws://192.168.14.7:8888/echo",
                              on_message = on_message,
                              on_error = on_error,
                              on_close = on_close)
    ws.on_open = on_open
    ws.run_forever()                                          
    
if __name__ == '__main__':
    test_websocket_basic()
    print "\r\n##################################\r\n"
    test_websocket_advance()
