<?php
class Server{
	
	private static $instance;
	
	private $server;
	
	//历史留言内容
	private $allMsg = array('cmd'=>'allmsg', 'msg'=>array());
	
	private function __construct() {}
	private function __clone() {}
	
	public static function getInstance(){
		if(self::$instance == null){
			self::$instance = new self();
		}
		return self::$instance;
	}
	
	public function run(){
		$this->server = new swoole_websocket_server("0.0.0.0", 1027);
        $this->server->on('open', function(swoole_websocket_server $server, $request){
			$this->open($server, $request);
		});
        $this->server->on('message', function(swoole_websocket_server $server, $frame){
			$this->message($server, $frame);
		});
        $this->server->on('close', function($server, $fd){
			$this->close($server, $fd);
		});
        $this->server->start();
	}
	
	//握手
	private function open(swoole_websocket_server $server, $request) {
		$this->broadcast($this->allMsg);
		//更新客户端在线人数
		$this->broadcast(array('cmd'=>'getCnt', 'num'=>$this->getCnt()));
	}
	//客户端信息
	private function message(swoole_websocket_server $server, $frame) {
		if($frame->data){
			$msg = json_decode($frame->data, true);
			$msg['time'] = date('Y-m-d H:i:s');
			array_unshift($this->allMsg['msg'], $msg);
			if($msg['cmd'] == 'msg'){
				$this->broadcast($msg);
			}
		}
	}
	//客户端关闭连接
	private function close($ser, $fd) {
		//更新客户端在线人数
		$this->broadcast(array('cmd'=>'getCnt', 'num'=>$this->getCnt()));
	}
	
	//广播
	private function broadcast(array $msg){
		foreach ($this->server->connections as $fd) {
			$this->server->push($fd, json_encode($msg));
		}
	}
	
	private function getCnt(){
		return count($this->server->connections);
	}
}
Server::getInstance()->run();

