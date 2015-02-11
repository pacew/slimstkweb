<?php

require_once ("/var/slimstk/slimstk.php");

echo ("hello7 " . strftime ("%Y-%m-%d %H:%M:%S"));

slimstk_session ();

var_dump ($_SESSION);

$_SESSION['foo'] = 1 + @$_SESSION['foo'];

