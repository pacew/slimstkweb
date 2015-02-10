<?php

date_default_timezone_set ("UTC");

echo ("hello6 " . strftime ("%Y-%m-%d %H:%M:%S"));

function peb_backtrace () {
	global $devel_mode;

	$str = "";
	foreach (array_reverse (debug_backtrace ()) as $fp) {
		if (preg_match ('/^ckerr/', $fp['function']))
			break;

		$str .= sprintf ("%s:%d %s(",
				 @$fp['file'],
				 @$fp['line'],
				 $fp['function']);

		if ($devel_mode) {
			$comma = "";
			foreach ($fp['args'] as $arg) {
				$str .= $comma;
				$comma = ",";
				if (is_string ($arg)) {
					$str .= escapeshellarg (substr ($arg,
									0,
									300));
				} else if (is_scalar ($arg)) {
					$str .= sprintf ("%s", $arg);
				} else if (is_array ($arg)) {
					$str .= "[";
					$comma2 = "";
					foreach ($arg as $akey => $aval) {
						$str .= $comma2;
						$comma2 = ",";
						$str .= sprintf ("%s=>", $akey);
						if (is_string ($aval)) {
							$str .= escapeshellarg(
								substr($aval,
								       0,100));
						} else if (is_scalar ($aval)) {
							$str .= sprintf ("%s",
									 $aval);
						} else {
							$str .= "#"
								.gettype($aval);
						}
					}
					$str .= "]";
				} else {
					$str .= "#".gettype($arg);
				}
			}
		}
		$str .= ")\n";
	}
	return ($str);
}

function make_db_connection ($dbparams) {
	global $default_dbparams;

	if ($dbparams == NULL)
		$dbparams = $default_dbparams;

	$pdo = new PDO (sprintf ("mysql:host=%s;charset:utf8",
				 $dbparams['host']),
			$dbparams['user'], $dbparams['passwd'],
			array (PDO::MYSQL_ATTR_INIT_COMMAND
			       => "set names 'utf8'"));
	$pdo->exec ("set character set utf8");

	return ($pdo);
}

$db_connections = array ();
$default_db = NULL;

function get_db ($dbname = "", $dbparams = NULL) {
	global $db_connections, $default_db;

	if ($dbname == "")
		$dbname = $_SERVER['siteid'];

	if (($db = @$db_connections[$dbname]) != NULL)
		return ($db);

	$db = (object)NULL;
	$db->dbname = $dbname;
	$db->pdo = make_db_connection ($dbparams);
	if ($db->pdo->exec (sprintf ("use `%s`", $dbname)) === false)
		return (NULL);
	$db->in_transaction = 0;
	
	$db_connections[$dbname] = $db;

	if ($dbparams == NULL)
		$default_db = $db;

	return ($db);
}

function quote_for_db ($db, $str) {
	return ($db->pdo->quote ($str));
}

function ckerr_mysql ($q, $stmt = "") {
	global $body;

	$err = $q->q->errorInfo ();
	if ($err[0] == "00000")
		return;

	$msg1 = sprintf ("DBERR %s %s\n%s\n",
			 strftime ("%Y-%m-%d %H:%M:%S\n"),
			 @$err[2], $stmt);
	$msg2 = peb_backtrace ();

	$body .= "<pre>";
	$body .= h($msg1);
	$arr = explode ("\n", $msg2);
	foreach ($arr as $row)
		$body .= wordwrap ($row, 130) . "\n";
	$body .= "</pre>\n";

	echo ($body);
	$body = "";

	error ();
	exit ();
}

function query_db ($db, $stmt, $arr = NULL) {
	if (is_string ($db)) {
		echo ("wrong type argument query_db");
		exit ();
	}

	if ($db == NULL)
		$db = get_db ();

	preg_match ("/^[ \t\r\n(]*([a-zA-Z]*)/", $stmt, $parts);
	$op = strtolower (@$parts[1]);

	$q = (object)NULL;

	if ($op != "commit") {
		if ($db->in_transaction == 0) {
			$q->q = $db->pdo->query("start transaction");
			ckerr_mysql ($q);
			$db->in_transaction = 1;
		}
	}

	if ($arr === NULL) {
		$q->q = $db->pdo->prepare ($stmt);
		if (! $q->q->execute (NULL))
			ckerr_mysql ($q, $stmt);
	} else {
		if (! is_array ($arr))
			$arr = array ($arr);
		foreach ($arr as $key => $val) {
			if (is_string ($val) && $val == "")
				$arr[$key] = NULL;
		}
		$q->q = $db->pdo->prepare ($stmt);
		if (! $q->q->execute ($arr))
			ckerr_mysql ($q, $stmt);
	}

	if ($op == "commit")
		$db->in_transaction = 0;

	return ($q);
}

function query ($stmt, $arr = NULL) {
	global $default_db;
	return (query_db ($default_db, $stmt, $arr));
}


function fetch ($q) {
	return ($q->q->fetch (PDO::FETCH_OBJ));
}

function do_commits () {
	global $db_connections;
	foreach ($db_connections as $db) {
		if ($db->in_transaction)
			query_db ($db, "commit");
	}
}

