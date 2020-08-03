<?php
/**
 * @author gaoqin31@163.com
 * @date 2017-08-08
 */

error_reporting(E_ALL ^ E_NOTICE);
date_default_timezone_set("Asia/shanghai");

class Server{
	
	const HOST = '0.0.0.0';
	
	const PORT = '1027';
	
	private static $_instance = null;

    /**
     * 当前服务端监听socket资源
     * @var null
     */
	private $serverSock = null;

    /**
     * 当前客户端连接
     * @var null
     */
	private $clientSock = null;

    /**
     * 所有的客户端连接
     * @var array
     */
	private $aClient = array();
	
	private $num = 0;
	
	private $curNum = 0;
	
	//历史留言内容
	private $allMsg = array(
	    'cmd'=>'allmsg',
        'msg'=>array()
    );
	
	private function __construct() {}
	
	public static function getInstance(){
		if(self::$_instance == null){
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	public function run(){
		echo "webSocket Server is running..." . PHP_EOL;
		$this->listen();
		$this->accept();
	}
	
	private function listen(){
		try{
			$this->serverSock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			//一般来说，一个端口释放后会等待两分钟之后才能再被使用，SO_REUSEADDR是让端口释放后立即就可以被再次使用
			socket_set_option($this->serverSock, SOL_SOCKET, SO_REUSEADDR, 1);
			socket_bind($this->serverSock, self::HOST, self::PORT);
			socket_listen($this->serverSock);
		}catch(Exception $e){
			die($e->getMessage() . socket_strerror(socket_last_error()));
		}
	}
	
	private function accept(){
		$this->aClient[$this->num]['resource'] = $this->serverSock;
		while(true){
			$selectSock = array_column($this->aClient, 'resource');

			socket_select($selectSock, $write, $except, null);

			foreach($selectSock as $sock){
				if($sock == $this->serverSock){//新的客户端连接进来
					$this->clientSock = socket_accept($this->serverSock);
					if(!$this->clientSock){
						continue;
					}
					socket_getpeername($this->clientSock, $ip, $port);
					echo "client from ip {$ip} port : {$port}  joining room...", PHP_EOL;
					$this->num++;
					$this->aClient[$this->num]['resource'] = $this->clientSock;
				}else{
					$this->clientSock = $sock;
					$this->curNum = $this->getCurClientKey();
					$bytes = socket_recv($this->clientSock, $data, 1024, 0);
					if(!isset($this->aClient[$this->curNum]['ishand'])){//握手

					    $this->aClient[$this->curNum]['ishand'] = $this->handshake($data) ? true : false;

						if($this->aClient[$this->curNum]['ishand'] && $this->allMsg['msg']){//握手成功
							$allmsg = $this->encode($this->allMsg);
							if(!socket_write($this->clientSock, $allmsg, strlen($allmsg))){
								echo 'socket write to cilent error: ' . socket_strerror(socket_last_error());
							}
							//更新客户端在线人数
							$this->broadcast(array('cmd'=>'getCnt', 'num'=>$this->getCnt()));
						}
					}else{
						$data = $this->decode($data);
						if(!$data){
							continue;
						}
						list($opcode, $ismask, $msg) = $data;
						//opcode 0x80为客户端发的关闭幀, 客户端发送的数据非掩码
						if($opcode == 0x8 || $ismask != 0x1){
							$this->closeconnect();
							//更新客户端在线人数
							$this->broadcast(array('cmd'=>'getCnt', 'num'=>$this->getCnt()));
						}
						if($msg && $msg['cmd'] == 'msg'){
                            $msg['time'] = date('Y-m-d H:i:s');
							//广播给所有客户端
							$this->broadcast($msg);

							//添加到历史留言内容
							unset($msg['cmd']);
							array_unshift($this->allMsg['msg'], $msg);
						}
					}
				}
			}
		}
	}

    /**
     * 获取http信息
     * @param $header
     * @param $name
     * @return string
     */
	private function getheader($header, $name){
		if(preg_match("/{$name}:.*\r\n/", $header, $matches)){
			$arr = explode(':', $matches[0]);
			if(isset($arr[1])){
				return trim($arr[1]);
			}
		}
		return '';
	}

    /**
     * 返回握手数据
     * @param $data
     * @return bool
     */
	private function handshake($data){
		if($sec_key = $this->getHeader($data, 'Sec-WebSocket-Key')){
			$header = "HTTP/1.1 101 Switching Protocols\r\n";
			$header .= "Upgrade: websocket\r\n";
			$header .= "Connection:Upgrade\r\n";
			$header .= "Sec-WebSocket-Accept:";
			$header .= base64_encode(sha1($sec_key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
			$header .= "\r\n\r\n";
			if(!socket_write($this->clientSock, $header, strlen($header))){
				echo 'socket write to cilent error: ' . socket_strerror(socket_last_error());
			}
			return true;
		}
		return false;
	}

    /**
     * 解析websocket 帧
     * @param $data
     * @return array
     */
	private function decode($data){
		if(!$data) return array();
		//第一个字节和00001111按位与运算取得的后4位数据就是opcode
		$opcode = ord(substr($data, 0, 1)) & 0x0f;
		//第二个字节和10000000按位与运算,保留第一位的值,然后右移7位取得的就是ismask
		$ismask = (ord(substr($data, 1, 1)) & 0x80) >> 7;
		//第二个字节和01000000按位与运算取得后7位的值就是playloadlen
		$playloadlen = ord(substr($data, 1, 1)) & 0x7f;
		$cdata = $maskkey = '';
        $decode = [];
		if($playloadlen < 126){
			$maskkey = substr($data, 2, 4);
			$cdata = substr($data, 6);
		}else if($playloadlen == 126){
			$maskkey = substr($data, 4, 4);
			$cdata = substr($data, 8);
		}else if($playloadlen == 127){
			$maskkey = substr($data, 10, 4);
			$cdata = substr($data, 14);
		}
		if($cdata && $maskkey){
			for($i = 0; $i < strlen($cdata); $i++){
				$decode[$i] = $cdata[$i] ^ $maskkey[$i % 4];
			}
			$decode = join('', $decode);
			$decode = json_decode($decode, true);
		}
		//返回 opcode，ismask, 消息数据
		return array($opcode, $ismask, $decode);
	}

    /**
     * 创建一个websocket帧
     * @param $data
     * @return string
     */
	private function encode($data){
		$data = json_encode($data);
		$len = strlen($data);
		$encode = '';
		if($len < 126){
			$encode = chr(0x81) . chr($len) . $data;
		}else if($len  >= 126 && $len <= 0xffff){
			$low = $len & 0x00FF;
			$high = ($len & 0xFF00) >> 8;
			$encode = chr(0x81) . chr(0x7E) . chr($high) . chr($low) . $data;
		}
		return $encode;
	}

    /**
     * 广播
     * @param $msg
     */
	private function broadcast($msg){
		$aSock = array_column($this->aClient, 'resource');
		if($aSock){
			$msg = $this->encode($msg);
			foreach ($aSock as $sock){
				if($sock != $this->serverSock){
					if(!socket_write($sock, $msg, strlen($msg))){
						echo socket_strerror(socket_last_error()), PHP_EOL;
					}
				}
			}
		}
	}

    /**
     * 获取当前客户端连接下标
     * @return int|string|null
     */
	private function getCurClientKey(){
		foreach($this->aClient as $key=>$client){
			if($client['resource'] == $this->clientSock){
				return $key;
				break;
			}
		}
		return null;
	}

    /**
     * 获取已建立连接的客户端总数
     * @return int
     */
	private function getCnt(){
		return count($this->aClient) - 1;
	}

    /**
     * 关闭连接
     */
	private function closeconnect(){
		socket_getpeername($this->clientSock, $ip, $port);
		echo "client from ip {$ip} port : {$port}  leaving room...", PHP_EOL;
		unset($this->aClient[$this->curNum]);
	}
}

Server::getInstance()->run();
