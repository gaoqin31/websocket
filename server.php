<?php
/**
 * @author gaoqin31@163.com
 * @date 2017-08-08
 */

error_reporting(E_ALL ^ E_NOTICE);
date_default_timezone_set("Asia/shanghai");

class Server{
	
	const HOST = '10.0.2.15';
	
	const PORT = '1027';
	
	private static $_instance = null;
	
	private $sock = null;
	
	private $aClients = array();
	
	private $client = null;
	
	private $aClient = array();
	
	private $num = 0;
	
	private $curNum = 0;
	
	//历史留言内容
	private $allMsg = array('cmd'=>'allmsg', 'msg'=>array());
	
	private function __construct() {
		$this->run();
	}
	
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
			$this->sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			//一般来说，一个端口释放后会等待两分钟之后才能再被使用，SO_REUSEADDR是让端口释放后立即就可以被再次使用
			socket_set_option($this->sock, SOL_SOCKET, SO_REUSEADDR, 1);
			socket_bind($this->sock, self::HOST, self::PORT);
			socket_listen($this->sock);
		}catch(Exception $e){
			die($e->getMessage() . socket_strerror(socket_last_error()));
		}
	}
	
	private function accept(){
		$this->aClient[$this->num]['resource'] = $this->sock;
		while(true){
			$selectSock = array_column($this->aClient, 'resource');
			socket_select($selectSock, $write, $except, null);
			foreach($selectSock as $sock){
				if($sock == $this->sock){
					$this->client = socket_accept($this->sock);
					if(!$this->client){
						continue;
					}
					socket_getpeername($this->client, $ip, $port);
					echo "client from ip {$ip} port : {$port}  joining room...", PHP_EOL;
					$this->num++;
					$this->aClient[$this->num]['resource'] = $this->client;
				}else{
					$this->client = $sock;
					$this->curNum = $this->getCurClientKey();
					$bytes = socket_recv($this->client, $data, 1024, 0);
					if(!isset($this->aClient[$this->curNum]['ishand'])){//握手
						$this->aClient[$this->curNum]['ishand'] = $this->handshake($data) ? true : false;
						if($this->aClient[$this->curNum]['ishand'] && $this->allMsg['msg']){//握手成功
							$allmsg = $this->encode($this->allMsg);
							if(!socket_write($this->client, $allmsg, strlen($allmsg))){
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
						//opcode 0x80为客户端发的关闭幀,客户端发送的数据非掩码
						if($data[0] == 0x8 || $data[1] != 0x1){
							$this->closeconnect();
							//更新客户端在线人数
							$this->broadcast(array('cmd'=>'getCnt', 'num'=>$this->getCnt()));
						}
						if($data[2] && $data[2]['cmd'] == 'msg'){
							$data[2]['time'] = date('Y-m-d H:i:s');
							//广播给所有客户端
							$this->broadcast($data[2]);
							//添加到历史留言内容
							unset($data[2]['cmd']);
							array_unshift($this->allMsg['msg'], $data[2]);
						}
					}
				}
			}
		}
	}
	
	private function getheader($header, $name){
		if(preg_match("/{$name}:.*\r\n/", $header, $matches)){
			$arr = explode(':', $matches[0]);
			if(isset($arr[1])){
				return trim($arr[1]);
			}
		}
		return '';
	}
	
	private function handshake($data){
		if($sec_key = $this->getHeader($data, 'Sec-WebSocket-Key')){
			$header = "HTTP/1.1 101 Switching Protocols\r\n";
			$header .= "Upgrade: websocket\r\n";
			$header .= "Connection:Upgrade\r\n";
			$header .= "Sec-WebSocket-Accept:";
			$header .= base64_encode(sha1($sec_key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
			$header .= "\r\n\r\n";
			if(!socket_write($this->client, $header, strlen($header))){
				echo 'socket write to cilent error: ' . socket_strerror(socket_last_error());
			}
			return true;
		}
		return false;
	}
	
	private function decode($data){
		if(!$data) return array();
		//第一个字节和00001111按位与运算取得的后4位数据就是opcode
		$opcode = ord(substr($data, 0, 1)) & 0x0f;
		//第二个字节和10000000按位与运算,保留第一位的值,然后右移7位取得的就是ismask
		$ismask = (ord(substr($data, 1, 1)) & 0x80) >> 7;
		//第二个字节和01000000按位与运算取得后7位的值就是playloadlen
		$playloadlen = ord(substr($data, 1, 1)) & 0x7f;
		$cdata = $maskkey = $decode = '';
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
				$decode{$i} = $cdata{$i} ^ $maskkey[$i % 4];
			}
			$decode = join('', $decode);
			$decode = json_decode($decode, true);
		}
		return array($opcode, $ismask, $decode);
	}
	
	private function encode($data){
		$data = json_encode($data);
		$len = strlen($data);
		$encode = '';
		if($len < 126){
			$encode = chr(0x81) . chr($len) . $data;
		}else if($len  >= 126 && $len < 0xffff){
			$low = $len & 0x00FF;
			$high = ($len & 0xFF00) >> 8;
			$encode = chr(0x81) . chr(0x7E) . chr($high) . chr($low) . $data;
		}
		return $encode;
	}
	
	private function broadcast($msg){
		$aSock = array_column($this->aClient, 'resource');
		if($aSock){
			$msg = $this->encode($msg);
			foreach ($aSock as $sock){
				if($sock != $this->sock){
					if(!socket_write($sock, $msg, strlen($msg))){
						echo socket_strerror(socket_last_error()), PHP_EOL;
					}
				}
			}
		}
	}
	
	private function getCurClientKey(){
		foreach($this->aClient as $key=>$client){
			if($client['resource'] == $this->client){
				return $key;
				break;
			}
		}
		return null;
	}
	
	private function getCnt(){
		return count($this->aClient) - 1;
	}
	
	private function closeconnect(){
		socket_getpeername($this->client, $ip, $port);
		echo "client from ip {$ip} port : {$port}  leaving room...", PHP_EOL;
		unset($this->aClient[$this->curNum]);
	}
}

Server::getInstance()->run();
