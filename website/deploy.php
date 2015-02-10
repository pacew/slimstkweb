<?php

require_once ("/var/pstacks/conflib.php");

$stacks_vars = json_decode (file_get_contents ("/var/pstacks/stacks_vars.json"),
			    true);

$stkname = $stacks_vars['STACKS_STACK_NAME'];
$stkinfo = $stacks_conf['stacks'][$stkname];


function make_rows ($obj) {
	$rows = "";

	foreach ($obj as $key => $val) {
		$rows .= sprintf ("<tr><th>%s</th><td>%s</td></tr>\n",
				  htmlentities ($key),
				  htmlentities (json_encode ($val)));
	}
	return ($rows);
}

$identity_path = "/dynamic/instance-identity/document";

$val = get_aws_param ($identity_path);
$inst = json_decode ($val, true);

$zone_letter = substr ($inst['availabilityZone'], -1);

$server_shortname = sprintf ("%s%s", $stkname, $zone_letter);
$server_fullname = sprintf ("%s.%s",
			    $server_shortname,
			    $stkinfo['server_domain']);

$public_hostname = get_aws_param ("/meta-data/public-hostname");

$ret = "";

$ret .= "<textarea rows='10' cols='80' readonly='readonly'>\n";
$ret .= sprintf ("ssh ec2-user@%s\n", htmlentities ($public_hostname));
$ret .= "\n";
$ret .= sprintf ("ssh %s\n", $server_shortname);
$ret .= "\n";
$ret .= sprintf ("cd %s\n", htmlentities ($_SERVER['siteid']));
$ret .= "git pull\n";
$ret .= "./install-site\n";
$ret .= "</textarea>\n";



$ret .= sprintf ("<div>server %s</div>\n", htmlentities ($server_fullname));
$ret .= sprintf ("<div>hostname %s</div>\n", htmlentities ($public_hostname));

$ret .= "<table>\n";
$ret .= sprintf ("<tr><th></th><td>%s</td></tr>\n",
		 htmlentities ($identity_path));
$ret .= make_rows ($inst);

$ret .= "<tr><th></th><td>stacks_vars.json</td></tr>\n";
$ret .= make_rows ($stacks_vars);

$ret .= "<tr><th></th><td>stkinfo</td></tr>\n";
$ret .= make_rows ($stkinfo);

$ret .= "</table>\n";

echo ($ret);



