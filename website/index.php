<?php

require_once ("common.php");

pstart ();

$body .= "<div>hello " . strftime ("%Y-%m-%d %H:%M:%S")  . "</div>";

$body .= sprintf ("session counter = %s\n", h ($_SESSION['foo']));

$_SESSION['foo'] = 1 + intval (@$_SESSION['foo']);

pfinish ();
