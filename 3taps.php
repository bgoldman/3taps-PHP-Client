<?php
class threeTapsClient {
	public static $clients = array();

	public $agentId;
	public $authId;
	public $host = '3taps.net';
	public $response;
	
	private static function json_decode($json) {
		$comment = false;
		$out = '$x=';
	  
		for ($i=0; $i<strlen($json); $i++)
		{
			if (!$comment)
			{
				if (($json[$i] == '{') || ($json[$i] == '['))       $out .= ' array(';
				else if (($json[$i] == '}') || ($json[$i] == ']'))   $out .= ')';
				else if ($json[$i] == ':')    $out .= '=>';
				else                         $out .= $json[$i];          
			}
			else $out .= $json[$i];
			if ($json[$i] == '"' && $json[($i-1)]!="\\")    $comment = !$comment;
		}
		
		@eval($out . ';');
		return isset($x) ? $x : null;
	}
	
	public static function register($type, $client) {
		self::$clients[$type] = $client;
	}

	public function __construct($authId, $agentId) {
		$this->authId = $authId;
		$this->agentId = $agentId;
		
		foreach (self::$clients as $type => $client) {
			$this->$type = new $client($this);
		}
	}
	
	public function request($path, $method, $getParams = array(), $postParams = array()) {
		$url = $path . $method;

		if (!empty($getParams)) {
			$url .= '?' . http_build_query($getParams);
		}
		
		if (!empty($postParams)) {
			$post = http_build_query($postParams);
		} else {
			$post = null;
		}

		$socket = fsockopen($this->host, 80);
		
		if (!$socket) {
			return false;
		}

		$write = 'POST ' . $url . ' HTTP/1.1' . "\r\n";
		$write .= 'Host: ' . $this->host . "\r\n";
		$write .= 'Content-Type: application/x-www-form-urlencoded' . "\r\n";
		if (!empty($post)) $write .= 'Content-Length: ' . strlen($post) . "\r\n";
		$write .= 'Connection: close' . "\r\n\r\n";
		if (!empty($post)) $write .= $post . "\r\n\r\n";

		fwrite($socket, $write);
		$chunkedResponseString = '';
		
		while (!feof($socket)) {
			$string = fread($socket, 1024);
			$chunkedResponseString .= $string;
		}

		$chunkedResponseString = substr($chunkedResponseString, strpos($chunkedResponseString, "\r\n\r\n") + 4);
		$responseString = '';
		$chars = 0;
		
		while ($chars < strlen($chunkedResponseString)) {
			$pos = strpos(substr($chunkedResponseString, $chars), "\r\n");
			
			if ($pos > -1) {
				$rawnum = substr($chunkedResponseString, $chars, $pos + 2);
				$num = hexdec(trim($rawnum));
				$chars += strlen($rawnum);
				$chunk = substr($chunkedResponseString, $chars, $num);
			} else {
				$chunk = $chunkedResponseString;
			}
			
			$responseString .= $chunk;
			$chars += strlen($chunk);
		}

		if (strpos($responseString, '503 Service Temporarily Unavailable') > 0) {
			return false;
		}

		$json = $responseString;
		
		if (!empty($options['strip_whitespace'])) {
			$json = str_replace(array("\r", "\n"), '', $json);
		}
		
		$json = trim($json);
		$this->response = json_decode($json, true);
		
		if (empty($this->response)) {
			$this->response = self::json_decode($json);
		}
		
		return $this->response;
	}
}

class threeTapsGeocoderClient {
	public $auth = true;
	public $client;
	public $path = '/geocoder/';
	
	public function __construct($authId, $agentId = null) {
		if (is_a($authId, 'threeTapsClient')) {
			$this->client = $authId;
		} else {
			$this->client = new threeTapsClient($authId, $agentId);
		}
	}
	
	public function geocode($data) {
		return $this->client->request($this->path, 'geocode', null, array(
			'agentID' => $this->client->agentId,
			'authID' => $this->client->authId,
			'data' => $data,
		));
	}
}

threeTapsClient::register('geocoder', 'threeTapsGeocoderClient');

class threeTapsReferenceClient {
	public $auth = false;
	public $client;
	public $path = '/reference/';
	
	public function __construct($authId, $agentId = null) {
		if (is_a($authId, 'threeTapsClient')) {
			$this->client = $authId;
		} else {
			$this->client = new threeTapsClient($authId, $agentId);
		}
	}
	
	public function category($category_id = null) {
		$method = 'category';
		if (!empty($category_id)) $method .= '/' . $category_id;
		$response = $this->client->request($this->path, $method, null, null);
		
		if (!empty($category_id) && !empty($response)) {
			return $response[0];
		}
		
		return $response;
	}
	
	public function location() {
		return $this->client->request($this->path, 'location', null, null);
	}
	
	public function source() {
		return $this->client->request($this->path, 'source/get', null, null);
	}
}

threeTapsClient::register('reference', 'threeTapsReferenceClient');

class threeTapsPostingClient {
	public $auth = false;
	public $client;
	public $path = '/posting/';
	
	public function __construct($authId, $agentId = null) {
		if (is_a($authId, 'threeTapsClient')) {
			$this->client = $authId;
		} else {
			$this->client = new threeTapsClient($authId, $agentId);
		}
	}
	
	public function create($data) {
		return $this->client->request($this->path, 'create', null, array(
			'posts' => $data,
		));
	}
	
	public function delete($data) {
		return $this->client->request($this->path, 'delete', null, array(
			'agentID' => $this->client->agentId,
			'authID' => $this->client->authId,
			'data' => $data,
		));
	}
	
	public function error($postKey) {
		return $this->client->request($this->path, 'error/' . $postKey, null, null);
	}
	
	public function exists($ids) {
		return $this->client->request($this->path, 'exists', null, array(
			'ids' => $ids,
		));
	}
	
	public function get($postKey) {
		return $this->client->request($this->path, 'get/' . $postKey, null, null);
	}
	
	public function update($data) {
		return $this->client->request($this->path, 'update', null, array(
			'agentID' => $this->client->agentId,
			'authID' => $this->client->authId,
			'data' => $data,
		));
	}
}

threeTapsClient::register('posting', 'threeTapsPostingClient');

class threeTapsSearchClient {
	public $auth = false;
	public $client;
	public $path = '/search/';
	
	public function __construct($authId, $agentId = null) {
		if (is_a($authId, 'threeTapsClient')) {
			$this->client = $authId;
		} else {
			$this->client = new threeTapsClient($authId, $agentId);
		}
	}
	
	public function bestMatch($params) {
		return $this->client->request($this->path, 'best-match', $params, null);
	}
	
	public function count($params) {
		return $this->client->request($this->path, 'count', $params, null);
	}
	
	public function range($params) {
		return $this->client->request($this->path, 'range', $params, null);
	}
	
	public function search($params) {
		return $this->client->request($this->path, 'search', $params, null);
	}
	
	public function summary($params) {
		return $this->client->request($this->path, 'summary', $params, null);
	}
}

threeTapsClient::register('search', 'threeTapsSearchClient');

class threeTapsStatusClient {
	public $auth = false;
	public $client;
	public $path = '/status/';
	
	public function __construct($authId, $agentId = null) {
		if (is_a($authId, 'threeTapsClient')) {
			$this->client = $authId;
		} else {
			$this->client = new threeTapsClient($authId, $agentId);
		}
	}
	
	public function get($params) {
		return $this->client->request($this->path, 'get', null, $params);
	}
	
	public function system() {
		return $this->client->request($this->path, 'system', null, null);
	}
	
	public function update($params) {
		return $this->client->request($this->path, 'update', null, $params);
	}
}

threeTapsClient::register('status', 'threeTapsStatusClient');
