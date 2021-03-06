<?php
	require 'inc/functions.php';
	
	if (!$config['search']['enable']) {
		die(_("Post search is disabled"));
	}

	$queries_per_minutes = $config['search']['queries_per_minutes'];
	$queries_per_minutes_all = $config['search']['queries_per_minutes_all'];
	$search_limit = $config['search']['search_limit'];
	
	if (isset($config['search']['boards'])) {
		$boards = $config['search']['boards'];
	} else {
		$boards = listBoards(TRUE, TRUE);
	}

	$results_count = 0;

	if(isset($_GET['search']) && !empty($_GET['search']) && isset($_GET['board']) && in_array($_GET['board'], $boards)) {		
		$phrase = $_GET['search'];
		$_body = '';
		$identity = Session::getIdentity();
		
		$query = prepare("SELECT COUNT(*) FROM ``search_queries`` WHERE `ip` = :ip AND `time` > :time");
		$query->bindValue(':ip', $identity);
		$query->bindValue(':time', time() - ($queries_per_minutes[1] * 60));
		$query->execute() or error(db_error($query));
		if($query->fetchColumn() > $queries_per_minutes[0])
			error(_('Wait a while before searching again, please.'));
		
		$query = prepare("SELECT COUNT(*) FROM ``search_queries`` WHERE `time` > :time");
		$query->bindValue(':time', time() - ($queries_per_minutes_all[1] * 60));
		$query->execute() or error(db_error($query));
		if($query->fetchColumn() > $queries_per_minutes_all[0])
			error(_('Wait a while before searching again, please.'));
			
		
		$query = prepare("INSERT INTO ``search_queries`` VALUES (:ip, :time, :query)");
		$query->bindValue(':ip', $identity);
		$query->bindValue(':time', time());
		$query->bindValue(':query', $phrase);
		$query->execute() or error(db_error($query));
		
		_syslog(LOG_NOTICE, 'Searched /' . $_GET['board'] . '/ for "' . $phrase . '"');

		// Cleanup search queries table
		$query = prepare("DELETE FROM ``search_queries`` WHERE `time` <= :time");
		$query->bindValue(':time', time() - ($queries_per_minutes_all[1] * 60));
                $query->execute() or error(db_error($query));
		
		openBoard($_GET['board']);
		
		$filters = Array();
		
		function search_filters($m) {
			global $filters;
			$name = $m[2];
			$value = isset($m[4]) ? $m[4] : $m[3];
			
			if(!in_array($name, array('id', 'thread', 'subject', 'name'))) {
				// unknown filter
				return $m[0];
			}
			
			$filters[$name] = $value;
			
			return $m[1];
		}
		
		$phrase = trim(preg_replace_callback('/(^|\s)(\w+):("(.*)?"|[^\s]*)/', 'search_filters', $phrase));
		
		if(!preg_match('/[^*^\s]/', $phrase) && empty($filters)) {
			_syslog(LOG_WARNING, 'Query too broad.');
			$body .= '<p class="unimportant" style="text-align:center">(Query too broad.)</p>';
			echo Element('page.html', Array(
				'config'=>$config,
				'title'=>'Search',
				'body'=>$body,
			));
			exit;
		}
		
		// Escape escape character
		$phrase = str_replace('!', '!!', $phrase);
		
		// Remove SQL wildcard
		$phrase = str_replace('%', '!%', $phrase);
		
		// Use asterisk as wildcard to suit convention
		$phrase = str_replace('*', '%', $phrase);
		
		// Remove `, it's used by table prefix magic
		$phrase = str_replace('`', '!`', $phrase);

		$like = '';
		$match = Array();
		
		// Find exact phrases
		if(preg_match_all('/"(.+?)"/', $phrase, $m)) {
			foreach($m[1] as &$quote) {
				$phrase = str_replace("\"{$quote}\"", '', $phrase);
				$match[] = $pdo->quote($quote);
			}
		}
		
		$words = explode(' ', $phrase);
		foreach($words as &$word) {
			if(empty($word))
				continue;
			$match[] = $pdo->quote($word);
		}
		
		$like = '';
		foreach($match as &$phrase) {
			if(!empty($like))
				$like .= ' AND ';
			$phrase = preg_replace('/^\'(.+)\'$/', '\'%$1%\'', $phrase);
			$like .= '`body` LIKE ' . $phrase . ' ESCAPE \'!\'';
		}
		
		foreach($filters as $name => $value) {
			if(!empty($like))
				$like .= ' AND ';
			$like .= '`' . $name . '` = '. $pdo->quote($value);
		}
		
		$like = str_replace('%', '%%', $like);
			
		$query = prepare(sprintf("SELECT * FROM ``posts_%s`` WHERE " . $like . " ORDER BY `time` DESC LIMIT :limit", $board['uri']));
		$query->bindValue(':limit', $search_limit, PDO::PARAM_INT);
		$query->execute() or error(db_error($query));
		
		$temp = '';
		$results_count = 0;

		while($post = $query->fetch()) {
			if(!$post['thread']) {
				$po = new Thread($post);
			} else {
				$po = new Post($post);
			}
			$temp .= $po->build(true);// . '<hr/>';
		}

		if($query->rowCount() > 0 &&!empty($temp)){
			$body .= $temp;
			$results_count = $query->rowCount();
		}
		else
			$body .= '<p style="text-align:center" class="l_search_noresults"></p>';

		
	}
		
	echo Element('search_page.html', Array(
		'config'=>$config,
		'boards' => $boards,
		'b' => isset($_GET['board']) ? $_GET['board'] : false, 
		'search' => isset($_GET['search']) ? str_replace('"', '&quot;', utf8tohtml($_GET['search'])) : false,
		'body'=> $body,
		'results_count' => $results_count,
		'board' => $board,


	));
