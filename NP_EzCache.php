<?php

class NP_EzCache extends NucleusPlugin {

	var $makecache = FALSE;
	var $usecache = FALSE;
	var $contents;
	
	function getName() { return 'Easy Cache'; }
	function getAuthor()  { return 'Andy'; }
	function getURL() { return 'http://www.matsubarafamily.com/lab/'; }
	function getVersion() { return '0.2'; }
	
	function getDescription() { 
		return 'Cache contents';
	}

	function supportsFeature($what) {
		switch($what){
			case 'SqlTablePrefix':
				return 1;
			default:
				return 0;
		}
	}

	function init() {
	}

	function versioncheck()
	{
		return (getNucleusVersion() >= 322);
	}

	function getEventList() { return array('InitSkinParse','PreSkinParse', 'PostSkinParse', 'PostUpdateItem', 'PostDeleteItem', 'PostAddItem', 'PostAddComment', 'PreUpdateComment', 'PostDeleteComment', 'PostAddCategory', 'PostMoveCategory', 'PostDeleteCategory', 'PostPluginOptionsUpdate', 'EzCacheClear'); }	

	function install() {
		$this->createOption('cache_urls', 'Cache URLs', 'textarea', '');
		$this->createOption('clear_cache', 'Clear Cache', 'yesno', 'no');
		$this->createOption('test_mode', 'Test Mode', 'select','0', '|0|1hour|1|4hours|2|12hours|3|1day|4');
		$this->createOption('test_limit', 'test mode limit', 'text', '', 'access=hidden');
		$query =  'CREATE TABLE IF NOT EXISTS '. sql_table('plug_ezcache'). ' ('
				. 'url VARCHAR(255) NOT NULL, '
				. 'contents text, '
				. 'initialtime double, '
				. 'cachedtime double, '
				. 'cachedcount int(11), '
				. 'PRIMARY KEY (url))';
		sql_query($query);
		$query =  'CREATE TABLE IF NOT EXISTS '. sql_table('plug_ezcache_test'). ' ('
				. 'url VARCHAR(255) NOT NULL, '
				. 'count int(11) DEFAULT 1, '
				. 'PRIMARY KEY (url))';
		sql_query($query);
	}

	function unInstall() { 
		sql_query('DROP TABLE ' .sql_table('plug_ezcache'));
		sql_query('DROP TABLE ' .sql_table('plug_ezcache_test'));
	}
	
	function getmicrotime() {
		list($usec, $sec) = explode(" ", microtime());
		return ((float)$usec + (float)$sec);
	}
	
	function event_InitSkinParse(&$data) {
		global $member;

		if ($member->isloggedin()) return;
		$urllist = $this->getOption('cache_urls');
		$this->url = $_SERVER['REQUEST_URI'];
		if ($this->getOption('test_mode')) {  //enter test mode
			$limit = $this->getOption('test_limit');
			if ($limit < time()) {
				$query = 'SELECT url FROM '.sql_table('plug_ezcache_test')
						. ' ORDER BY count DESC LIMIT 10';
				$result = sql_query($query);
				if (!preg_match('/\s$/', $urlist)) {
					$urllist .= "\r\n";
				}
				while ($data = mysql_fetch_assoc($result)) {
					if ($data['url']) $urllist .= $data['url']. "\r\n";
				}
				$this->setOption('cache_urls', $urllist);
				$this->setOption('test_mode', 0);
				$query = 'DELETE FROM '.sql_table('plug_ezcache_test');
				sql_query($query);
				return;
			}
			$query = 'UPDATE '.sql_table('plug_ezcache_test')
					.sprintf(' SET count=count+1 WHERE url="%s"', addslashes($this->url));
			$result = sql_query($query);
			if (!mysql_affected_rows()) {
				$query = 'INSERT '.sql_table('plug_ezcache_test')
						. sprintf(' SET url="%s"', addslashes($this->url));
				sql_query($query);
			}
		}
		$urlarray = preg_split('|[\s]+|', $urllist);
		foreach ($urlarray as $url) {
			if (preg_match('|^'.$url.'$|', $this->url)) {
				$query = 'SELECT contents, cachedtime, cachedcount FROM '
						.sql_table('plug_ezcache').' WHERE url="'.addslashes($this->url).'"';
				$result = sql_query($query);
				$this->row = mysql_fetch_assoc($result);
				if (!$this->row || !($this->row['contents'])) {
					$this->makecache = TRUE;
					return;
				}
				$this->usecache = TRUE;
				return;
			}
		}
	}

	function event_PreSkinParse(&$data) {
		if ($this->makecache) {
			ob_start(array(&$this, 'ob_DoNothing'));
			$this->start = $this->getmicrotime();
		} elseif ($this->usecache) {
			$data['contents'] = $this->row['contents'];
			$this->start = $this->getmicrotime();
		}
	}
	
	function event_PostSkinParse(&$data) {
		if ($this->makecache) {
			$contents = ob_get_contents();
			ob_end_flush();
			$time = $this->getmicrotime() - $this->start;
			$query = 'REPLACE INTO '.sql_table('plug_ezcache');
			$query .= sprintf(' SET url="%s", contents="%s", initialtime=%f ', addslashes($this->url), addslashes($contents), $time);
			sql_query($query);
		} elseif ($this->usecache) {
			$time = $this->getmicrotime() - $this->start;
			$averagetime = ($this->row['cachedtime'] * $this->row['cachedcount'] + $time) / ++$this->row['cachedcount'];
			$query = 'UPDATE '.sql_table('plug_ezcache');
			$query .= sprintf(' SET cachedtime=%f, cachedcount=%d WHERE url="%s"', $averagetime, $this->row['cachedcount'], addslashes($this->url));
			sql_query($query);
		}
	}

	function event_PostPluginOptionsUpdate(&$data)
	{
		if ($data['plugid'] == $this->getID() && $data['context'] == 'global') {
			if ($this->getOption('clear_cache') == 'yes') {
				$this->clearCache();
				$this->setOption('clear_cache', 'no');
			}
			if ($this->getOption('test_mode')) {
				switch ($this->getOption('test_mode')) {
					case '1' : //1hour
						$limit = time() + 60*60;
						break;
					case '2' : //4hours
						$limit = time() + 60*60*4;
						break;
					case '3' : //12hours
						$limit = time() + 60*60*12;
						break;
					case '4' : //1day
						$limit = time() + 60*60*24;
						break;
				}
				$this->setOption('test_limit', $limit);
			}
		}
	}
	function clearCache() {
		$query = 'DELETE FROM '.sql_table('plug_ezcache');
		sql_query($query);
	}
	
	function event_EzCacheClear(&$data) {
		$this->clearCache();
	}

	function event_PostUpdateItem(&$data) {
		$this->clearCache();
	}
	
	function event_PostDeleteItem(&$data) {
		$this->clearCache();
	}
	
	function event_PostAddItem(&$data) {
		$this->clearCache();
	}


	function event_PostAddComment(&$data) {
		$this->clearCache();
	}

	function event_PreUpdateComment(&$data) {
		$this->clearCache();
	}

	function event_PostDeleteComment(&$data) {
		$this->clearCache();
	}

	function event_PostAddCategory(&$data) {
		$this->clearCache();
	}

	function event_PostMoveCategory(&$data) {
		$this->clearCache();
	}

	function event_PostDeleteCategory(&$data) {
		$this->clearCache();
	}


	function ob_DoNothing($data) {
		return $data;
	}
	

	function doSkinVar($skinType, $param = '') {
		global $member, $CONF;
		if ($member->isloggedin() && $member->isAdmin()) {
			switch($param) {
				case 'show' :
					$this->url = $_SERVER['REQUEST_URI'];
					$url = $CONF['ActionURL'].'?action=plugin&name=EzCache&type=display&url='.urlencode($this->url);
					printf ('<a href="javascript:void(0)" onclick="window.open(\'%s\', ', $url);
					echo "'ezcache', 'scrollbars=no,width=150,height=50,left=200,top=50,status=no,resizable=yes');\">";
					echo "Show Cache</a>";
					break;
				case 'clear' :
					$url = $CONF['ActionURL'].'?action=plugin&name=EzCache&type=clearcache';
					printf ('<a href="%s">Clear Cache</a>', $url);
					break;
			}
		}
	}

	function doAction($type) {
		global $member;
		if ($member->isloggedin() && $member->isAdmin()) {
			switch ($type) {
				case 'display' :
					$url = addslashes(requestVar('url'));
					$query = 'SELECT initialtime, cachedtime, cachedcount FROM '.sql_table('plug_ezcache');
					$query .= sprintf(' WHERE url="%s"', $url);
					$result = sql_query($query);
					$data = mysql_fetch_assoc($result);
					if ($data) {
						printf('initial time : %01.4f<br />', $data['initialtime']);
						printf('cached time  : %01.4f<br />', $data['cachedtime']);
						printf('cached count : %01d', $data['cachedcount']);
					}
					break;
				case 'clearcache' :
					$this->clearCache();
					echo '<script>history.back();</script>';
					break;
			}
		}
	}

}
?>