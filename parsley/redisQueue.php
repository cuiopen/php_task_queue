<?php
/**
 * redis 队列数据操作类
 * 模拟实现zset的 lpop rpop
 * redis有list结构，它也有zset有序集合应为source的存在，使zset有了无限可能（插队）, 虽然对于此场景list结构的pop功能很好用，但还是使用灵活性更高的zset
 * 
 */
include_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'../config.php');
global $conf;
$conf = $config;

class redisQueue{
	const POSITION_FIRST = 0;
	const POSITION_LAST = -1;
	private $redis;
	
	public function __construct(){
		$this->redisConn();
	}
	
	private function redisConn(){
		global $conf;
		$r = $conf['redis'];
		$this->redis = new Redis();
		$this->redis->connect($r['host'],$r['port'],$r['db']);
	}
	
	/**
	 * 获取队列头部元素，并删除
	 * @param string $zset
	 */
	public function zlPop($zset){
		return $this->zsetPopCheck($zset, self::POSITION_FIRST);
	}

	/**
	 * 获取队列头部元素，并删除
	 * @param string $zset
	 */
	public function zPop($zset){
		return $this->zsetPop($zset, self::POSITION_FIRST);
	}
	
	/**
	 * 获取队列尾部元素，并删除
	 * @param string $zset
	 */
	public function zRevPop($zset){
		return $this->zsetPop($zset, self::POSITION_LAST);
	}

	/**
	 * redis incr
	 * @param string $key
	 */
	public function incr($key){
		try {
			return $this->redis->incr($key);
		}catch (Exception $e){
			$this->redisConn();
			return $this->redis->incr($key);
		}
	}
	
	/**
	 *  redis del
	 * @param string $key
	 */
	public function del($key){
		try {
			return $this->redis->del($key);
		}catch (Exception $e){
			$this->redisConn();
			return $this->redis->del($key);
		}
	}
	
	/**
	 *   redis zAdd
	 * @param string $key
	 * @param int $source
	 * @param string $value
	 */
	public function zadd($key,$source,$value){
		try {
			$this->redis->zadd($key,$source,$value);
		}catch (Exception $e){
			$this->redisConn();
			$this->redis->zadd($key,$source,$value);
		}
	}
	
	/**
	 *  redis zRange
	 * @param int $position
	 * @param int $limit
	 * @param string $value
	 */
	public function zRange($zset, $position, $limit, $WITHSCORES=''){
		try {
			$element = $this->redis->zRange($zset, $position, $limit);
		}catch (Exception $e){
			$this->redisConn();
			$element = $this->redis->zRange($zset, $position, $limit);
		}
		if (!isset($element[0])) {
			return null;
		}
		return $element;
	}
	
	/**
	 * 方法1：使用watch监控key，获取元素 (轮询大大增加了时间消耗)
	 * @param string $zset
	 * @param int $position
	 * @return string|json
	 */
	private function zsetPop($zset, $position){
		try {
			$this->redis->ping();
		}catch (Exception $e){
			$this->redisConn();
		}
	
		$redis = $this->redis;
		//乐观锁监控key是否变化
		$redis->watch($zset);
		$element = $redis->zRange($zset, $position, $position);
		if (!isset($element[0])) {
			return null;
		}
	
		//若无变化返回数据
		$redis->multi();
		$redis->zRem($zset, $element[0]);
		if($redis->exec()){
			return $element[0];
		}
		//key发生变化，重新获取(轮询大大增加了时间消耗)
		return $this->zsetPop($zset, $position);
	}
	
	/**
	 * 方法2：使用写入标记key，获取可用元素 (轮询大大增加了时间消耗)
	 * @param string $zset
	 * @param int $position
	 * @return string|json
	 */
	private function zsetPopCheck($zset, $position){
		try {
			$element = $this->redis->zRange($zset, $position, $position);
		}catch (Exception $e){
			$this->redisConn();
			$element = $this->redis->zRange($zset, $position, $position);
		}
		$redis = $this->redis;
		if (!isset($element[0])) {
			return null;
		}
		
		$myCheckKey = (microtime(true)*10000).rand(1000,9999);//唯一key(可使用更严谨的生成规则，比如:redis的incr)
		$k = $element[0].'_check';
		$checkKey = $redis->get($k);
		
		if (empty($checkKey) || $myCheckKey == $checkKey) {
			$redis->setex($k, 10, $myCheckKey);//写入key并且设置过期时间
			$redis->watch($k);//监控锁
			$redis->multi();
			$redis->zRem($zset, $element[0]);
			if($redis->exec()){
				return $element[0];
			}
			//return false;
		}
		//重新获取（期待queue top1已消费,获取新的top1）
		return $this->zsetPopCheck($zset,$position);//$position = 2
	}
}
