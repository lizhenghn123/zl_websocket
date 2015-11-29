#!/usr/bin/env python
# coding=utf-8
# author : lizhenghn@gmail.com
import socket
import struct
import hashlib
import threading,random
import sys
#reload(sys)
#sys.setdefaultencoding('utf-8')

# 还有问题
connectionlist = {}

class WsUtil:
    @staticmethod
    def generateAcceptKey(key):
        import base64
        nkey=key+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11'
        nkey=base64.b64encode(hashlib.sha1(nkey).digest())
        return nkey

    @staticmethod
    def encodeFrame(raw_str):
        emcodedstr = []

        emcodedstr.append('\x81')
        data_length = len(raw_str)

        if data_length < 125:
            emcodedstr.append(chr(data_length))
        else:
            emcodedstr.append(chr(126))
            emcodedstr.append(chr(data_length >> 8))
            emcodedstr.append(chr(data_length & 0xFF))

        emcodedstr = "".join(emcodedstr) + raw_str    
        return emcodedstr

def deleteConnection(item):
    global connectionlist
    del connectionlist['connection'+item]

def sendData(message):
    global connectionlist
    for connection in connectionlist.values():
        #print connection
        bstr = WsUtil.encodeFrame(message)
        b = connection.send(bstr)

#接收客户端发送过来的消息,并且解包
def recvData(nNum,client):
    try:
        pData = client.recv(nNum)
        if not len(pData):
            return False
    except:
        return False
    else:
        code_length = ord(pData[1]) & 127
        if code_length == 126:
            masks = pData[4:8]
            data = pData[8:]
        elif code_length == 127:
            masks = pData[10:14]
            data = pData[14:]
        else:
            masks = pData[2:6]
            data = pData[6:]
        
        raw_str = ""
        i = 0
        for d in data:
            raw_str += chr(ord(d) ^ ord(masks[i%4]))
            i += 1            
        return raw_str   

class WebSocket(threading.Thread):
    def __init__(self, conn, index, name, remote, path="/"):
        threading.Thread.__init__(self)
        self.conn = conn
        self.index = index
        self.name = name
        self.remote = remote
        self.path = path
        self.buffer = ""

    def run(self):
        try:
            self.runLoop()
        except Exception, e:
            print e
            
    def runLoop(self):
        print 'Socket%s Start!' % self.index
        headers = {}
        self.handshaken = False

        while True:
            if self.handshaken == False:
                print 'Socket%s Start Handshaken with %s!' % (self.index,self.remote)
                self.buffer += self.conn.recv(1024)
                if self.buffer.find('\r\n\r\n') != -1:
                    header, data = self.buffer.split('\r\n\r\n', 1)
                    for line in header.split("\r\n")[1:]:
                        key, value = line.split(": ", 1)
                        headers[key] = value
                    print 'header:-->'+header
                    headers["Location"] = "ws://%s%s" %(headers["Host"], self.path)
                    self.buffer = data[8:]
                    key = headers["Sec-WebSocket-Key"]
                    token = WsUtil.generateAcceptKey(key)
                    handshake = '\
HTTP/1.1 101 Web Socket Protocol Handshake\r\n\
Upgrade: webSocket\r\n\
Connection: Upgrade\r\n\
Sec-WebSocket-Accept:%s\r\n\
Sec-WebSocket-Origin: %s\r\n\
Sec-WebSocket-Location: %s\r\n\r\n\
' %(token,headers['Origin'], headers['Location'])

                    print handshake
                    num = self.conn.send(handshake)
                    print str(num)
                    self.handshaken = True
                    print 'Socket%s Handshaken with %s success!' % (self.index,self.remote)
                    bstr = WsUtil.encodeFrame("Welcome")
                    print bstr
                    self.conn.send(bstr)
            else:
                self.buffer = recvData(8196, self.conn)
                if self.buffer:
                    print 'rec:'+self.buffer
                if self.buffer:
                    s = self.buffer
                    if s=='quit':
                        print 'Socket%s Logout!' % (self.index)
                        sendData(self.name+' Logout')
                        deleteConnection(str(self.index))
                        self.conn.close()
                        break
                    else:
                        print 'Socket%s Got msg:%s from %s!' % (self.index, s, self.remote)
                        sendData(self.name+':'+s)
                    self.buffer = ""

class WebSocketServer(object):
    def __init__(self, ip, port):
        self.socket = None
        self.ip = ip
        self.port = port
        
    def run(self):
        print "websocketserver listen on [%s:%d]..." % (self.ip, self.port)

        self.socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self.socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.socket.bind((self.ip, self.port))        
        self.socket.listen(1024)
        
        global connectionlist

        i=0
        try:
            while True:
                connection, address = self.socket.accept()
                username=address[0]
                newSocket = WebSocket(connection, i, username, address)
                newSocket.start()
                connectionlist['connection'+str(i)]=connection
                i = i + 1
        except Exception, e:
            print '!!!!!!!'

if __name__ == "__main__":
    print "====================="
    server = WebSocketServer("0.0.0.0", 8888)
    server.run()
