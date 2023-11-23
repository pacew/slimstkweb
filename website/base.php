<?php

require_once ("/var/slimstk/slimstk.php");
slimstk_init ();

slimstk_session ();

$devel_mode = intval ($_SERVER['devel_mode']);
$siteid = $_SERVER['siteid'];
$conf_key = $_SERVER['conf_key'];
$site_root = $_SERVER['DOCUMENT_ROOT'];
$site_url = $_SERVER['site_url'];
$site_port = intval ($_SERVER['site_port']);
$ssl_url = @$_SERVER['ssl_url'];
$ssl_port = intval (@$_SERVER['ssl_port']);

$start_microtime = microtime (true);

error_reporting (error_reporting () | E_NOTICE);

function make_stylesheet_link () {
	$ret = "";
	$url = sprintf ("/style.css?s=%s", get_cache_defeater ());
	$ret.=sprintf("<link rel='stylesheet' href='%s' type='text/css' />\n",
		      fix_target ($url));
	return ($ret);
}

$extra_inline_javascript = "";

$prev_flash = @$_SESSION['flash'];
$_SESSION['flash'] = "";

$dbg_file = NULL;
function dbg ($str) {
	global $dbg_file;

	if ($dbg_file == NULL) {
		$filename = sprintf ("/tmp/log-%s-%d", $_SERVER['siteid'],
				     strftime ("%w"));
		$mode = "a";
		if (file_exists ($filename)
		    && filemtime () < time () - 2 * 86400) {
			$mode = "w";
		}
		$dbg_file = fopen ($filename, $mode);
		if ($dbg_file == NULL)
			return;
	}

	if ($str == NULL) {
		fputs ($dbg_file, "\n");
	} else {
		$arr = gettimeofday ();

		$secs = $arr['sec'];
		$millisecs = floor ($arr['usec'] / 1000);

		global $remote_addr;
		fputs ($dbg_file,
		       sprintf ("%s.%03d %s %s\n",
				strftime ("%H:%M:%S", $secs),
				$millisecs,
				$remote_addr,
				trim ($str)));
	}
	fflush ($dbg_file);
}

function getsess ($name) {
	$key = sprintf ("svar%d_%s", $_SERVER['site_port'], $name);
	if (isset ($_SESSION[$key]))
		return ($_SESSION[$key]);
	return (NULL);
}

function putsess ($name, $val) {
	$key = sprintf ("svar%d_%s", $_SERVER['site_port'], $name);
	$_SESSION[$key] = $val;
}

function clrsess () {
	$prefix = sprintf ("svar%d_", $_SERVER['site_port']);
	$prefix_len = strlen ($prefix);
	$del_keys = array ();
	foreach ($_SESSION as $key => $val) {
		if (strncmp ($key, $prefix, $prefix_len) == 0)
			$del_keys[] = $key;
	}
	foreach ($del_keys as $key) {
		unset ($_SESSION[$key]);
	}
}

function getseq () {
	$q = query ("select lastval"
		    ." from seq"
		    ." limit 1");
	if (($r = fetch ($q)) == NULL) {
		$newval = 100;
		query ("insert into seq (lastval) values (?)",
		       $newval);
	} else {
		$newval = 1 + intval ($r->lastval);
		query ("update seq set lastval = ?",
		       $newval);
	}
	return ($newval);
}

$urandom_chars = "0123456789abcdefghijklmnopqrstuvwxyz";
$urandom_chars_len = strlen ($urandom_chars);

function generate_urandom_string ($len) {
	global $urandom_chars, $urandom_chars_len;
	$ret = "";

	$f = fopen ("/dev/urandom", "r");

	for ($i = 0; $i < $len; $i++) {
		$c = ord (fread ($f, 1)) % $urandom_chars_len;
		$ret .= $urandom_chars[$c];
	}
	fclose ($f);
	return ($ret);
}

$cache_defeater = array ();
function get_cache_defeater ($filename = "") {
	global $cache_defeater, $devel_mode;

        if (($val = @$cache_defeater[$filename]) == "") {
		if ($filename) {
			$val = filemtime ($filename);
		} else if (! $devel_mode
			   && ($f = @fopen ("commit", "r")) != NULL) {
			$val = fgets ($f);
			fclose ($f);
			$val = substr ($val, 7, 8);
		} else {
			$val = generate_urandom_string (8);
		}
		$cache_defeater[$filename] = $val;
	}

        return ($val);
}

function flash ($str) {
	if (session_id ())
		$_SESSION['flash'] .= $str;
}

function make_absolute ($rel) {
	global $ssl_url, $site_url;

	if (preg_match (':^http:', $rel))
		return ($rel);

	if (@$_SERVER['HTTPS'] == "on") {
		$base_url = $ssl_url;
	} else {
		$base_url = $site_url;
	}

	$started_with_slash = 0;
	if (preg_match (':^/:', $rel))
		$started_with_slash = 1;

	/* chop off leading slash */
	$rel = preg_replace (":^/:", "", $rel);

	if ($started_with_slash)
		return ($base_url . $rel);

	$parts = parse_url (@$_SERVER['REQUEST_URI']);
	/* change /test/index.php to /test */
	$dir = preg_replace (':/*[^/]*$:', '', $parts['path']);

	/* change /test to test */
	$dir = preg_replace (":^/:", "", $dir);

	if ($dir == "") {
		$ret = $base_url . $rel;
	} else {
		$ret = $base_url . $dir . "/" . $rel;
	}

	return ($ret);
}

function make_relative ($rel) {
	global $site_url;

	$expr = sprintf ("~^%s~", $site_url);
	if (preg_match ($expr, $rel)) {
		$ret = "/" . substr (strrchr ($rel, "/"), 1);
	} else {
		return $rel;
	}

	return ($ret);
}

function redirect ($target) {
	$target = make_absolute ($target);

	if (session_id ())
		session_write_close ();
	do_commits ();
	if (ob_list_handlers ())
		ob_clean ();
	header ("Location: $target");
	exit ();
}

function redirect_permanent ($target) {
	$target = make_absolute ($target);

	if (session_id ())
		session_write_close ();
	do_commits ();
	if (ob_list_handlers ())
		ob_clean ();
	header ("HTTP/1.1 301 Moved Permanently");
	header ("Location: $target");
	exit ();
}

function fatal ($str = "error") {
	echo ("fatal: " . htmlentities ($str));
	exit();
}

function h($val) {
	return (htmlentities ($val, ENT_QUOTES, 'UTF-8'));
}

/* quoting appropriate for generating xml (like rss feeds) */
function xh($val) {
	return (htmlspecialchars ($val, ENT_QUOTES));
}

function fix_target ($path) {
	$path = preg_replace ('/\&/', "&amp;", $path);
	return ($path);
}

/*
 * use this to conditionally insert an attribute, for example,
 * if $class may contain a class name or an empty string, then do:
 * $body .= sprintf ("<div %s>", mkattr ("class", $class));
 *
 * it is safe to use more than once in the same expression:
 * $body .= sprintf( "<div %s %s>", mkattr("class",$c), mkattr("style",$s));
 */
function mkattr ($name, $val) {
	if (($val = trim ($val)) == "")
		return ("");
	return (sprintf ("%s='%s'",
			 htmlspecialchars ($name, ENT_QUOTES),
			 htmlspecialchars ($val, ENT_QUOTES)));
}

function mail_link ($email) {
	return (sprintf ("<a href='mailto:%s'>%s</a>",
			 fix_target ($email), h($email)));
}

function mklink ($text, $target) {
	if (trim ($text) == "")
		return ("");
	if (trim ($target) == "")
		return (h($text));
	return (sprintf ("<a href='%s'>%s</a>",
			 fix_target ($target), h($text)));
}

function mklink_class ($text, $target, $class) {
	if (trim ($text) == "")
		return ("");

	$attr_href = "";
	$attr_class = "";

	if (trim ($target) != "")
		$attr_href = sprintf ("href='%s'", fix_target ($target));

	if ($class != "")
		$attr_class = sprintf ("class='%s'", $class);

	return (sprintf ("<a %s %s>%s</a>",
			 $attr_href, $attr_class, h($text)));
}

function mklink_attr ($text, $args) {
	$attrs = "";
	foreach ($args as $name => $val) {
		switch ($name) {
		case "href":
			$attrs .= sprintf (" href='%s'", fix_target ($val));
			break;
		default:
			$attrs .= sprintf (" %s='%s'", $name, $val);
			break;
		}
	}

	if (! strstr ($text, "<"))
		$text = h($text);

	return (sprintf ("<a %s>%s</a>", $attrs, $text));

}

function mklink_nw ($text, $target) {
	if (trim ($text) == "")
		return ("");
	if (trim ($target) == "")
		return (h($text));
	return (sprintf ("<a href='%s' target='_blank'>%s</a>",
			 fix_target ($target), h($text)));
}

function mklink_nw_class ($text, $target, $class) {
	if (trim ($text) == "")
		return ("");
	if (trim ($target) == "")
		return (h($text));
	return (sprintf ("<a href='%s' class='%s' target='_blank' >%s</a>",
			 fix_target ($target), ($class), h($text)));
}

/*
 * add_extra_script
 *
 * Append an external script to the list of scripts to be included before
 * the closing content container tag. Alters global $extra_scripts.
 *
 * $script_path: path or url to script
 */

function add_extra_script ($script_path) {
	global $extra_scripts;

	if (!isset($extra_scripts)) $extra_scripts = '';
	$extra_scripts .= sprintf ("<script type='text/javascript' src='%s'>"
				   ."</script>\n",
				   fix_target ($script_path));
}

function parse_number ($str) {
	return (0 + preg_replace ('/[^-.0-9]/', '', $str));
}


function make_confirm ($question, $button, $args) {
	$req = parse_url ($_SERVER['REQUEST_URI']);
	$path = $req['path'];

	$ret = "";
	$ret .= sprintf ("<form action='%s' method='post'>\n", h($path));
	foreach ($args as $name => $val) {
		$ret .= sprintf ("<input type='hidden'"
				 ." name='%s' value='%s' />\n",
				 h($name), h ($val));
	}
	$ret .= h($question);
	$ret .= sprintf (" <input type='submit' value='%s' />\n", h($button));
	$ret .= "</form>\n";
	return ($ret);
}

function mktable ($hdr, $rows) {
	$ncols = count ($hdr);
	foreach ($rows as $row) {
		$c = count ($row);
		if ($c > $ncols)
			$ncols = $c;
	}

	if ($ncols == 0)
		return ("");

	$ret = "";
	$ret .= "<table class='boxed'>\n";
	$ret .= "<thead>\n";
	$ret .= "<tr class='boxed_pre_header'>";
	$ret .= sprintf ("<td colspan='%d'></td>\n", $ncols);
	$ret .= "</tr>\n";

	if ($hdr) {
		$ret .= "<tr class='boxed_header'>\n";

		$colidx = 0;
		if ($ncols == 1)
			$class = "lrth";
		else
			$class = "lth";
		foreach ($hdr as $heading) {
			$ret .= sprintf ("<th class='%s'>", $class);
			$ret .= $heading;
			$ret .= "</th>\n";

			$colidx++;
			$class = "mth";
			if ($colidx + 1 >= $ncols)
				$class = "rth";
		}
		$ret .= "</tr>\n";
	}
	$ret .= "</thead>\n";

	$ret .= "<tfoot>\n";
	$ret .= sprintf ("<tr class='boxed_footer'>"
			 ."<td colspan='%d'></td>"
			 ."</tr>\n",
			 $ncols);
	$ret .= "</tfoot>\n";

	$ret .= "<tbody>\n";

	$rownum = 0;
	foreach ($rows as $row) {
		$this_cols = count ($row);

		if ($this_cols == 0)
			continue;

		if (is_object ($row)) {
			switch ($row->type) {
			case 1:
				$c = "following_row ";
				$c .= $rownum & 1 ? "odd" : "even";
				$ret .= sprintf ("<tr class='%s'>\n", $c);
				$ret .= sprintf ("<td colspan='%d'>",
						 $ncols);
				$ret .= $row->val;
				$ret .= "</td></tr>\n";
				break;
			}
			continue;
		}

		$rownum++;
		$ret .= sprintf ("<tr class='%s'>\n",
				 $rownum & 1 ? "odd" : "even");

		for ($colidx = 0; $colidx < $ncols; $colidx++) {
			if($ncols == 1) {
				$class = "lrtd";
			} else if ($colidx == 0) {
				$class = "ltd";
			} else if ($colidx < $ncols - 1) {
				$class = "mtd";
			} else {
				$class = "rtd";
			}

			$col = @$row[$colidx];

			if (is_array ($col)) {
				$c = $col[0];
				$v = $col[1];
			} else {
				$c = "";
				$v = $col;
			}
			$ret .= sprintf ("<td class='%s %s'>%s</td>\n",
					 $class, $c, $v);
		}

		$ret .= "</tr>\n";
	}

	if (count ($rows) == 0)
		$ret .= "<tr><td>(empty)</td></tr>\n";

	$ret .= "</tbody>\n";
	$ret .= "</table>\n";

	return ($ret);
}

function make_option ($val, $curval, $desc)
{
	global $body;

	if ($val == $curval)
		$selected = "selected='selected'";
	else
		$selected = "";

	$body .= sprintf ("<option value='%s' $selected>", h($val));
	$body .= h ($desc);
	$body .= "</option>\n";
}

function make_option2 ($val, $curval, $desc)
{
	$ret = "";

	if ($val == $curval)
		$selected = "selected='selected'";
	else
		$selected = "";

	$ret .= sprintf ("<option value='%s' $selected>", $val);
	if (trim ($desc))
		$ret .= h ($desc);
	else
		$ret .= "&nbsp;";
	$ret .= "</option>\n";

	return ($ret);
}
/* ================================================================ */

$pstart_args = (object)NULL;
$pstart_args->nocache = 0;
$pstart_args->body_class = "";
$pstart_args->body_id = "";
$pstart_args->require_password = 0;

function pstart () {
	ob_start ();
	global $body;
	$body = "";
}

function pstart_nocache () {
	global $pstart_args;
	$pstart_args->nocache = 1;
	pstart ();
}

$extra_scripts = "";

function footer_scripts () {
	$ret = "";
	$ret .= "<script type='text/javascript'"
		." src='/jquery-FIXME.min.js'></script>\n";

	return ($ret);
}

function google_analytics () {
	$ret = "";
	return ($ret);
}

function google_conversion ($id, $label) {
	$ret = "";
	return ($ret);
}

function html_head () {
	$ret = "";

	$ret .= "<meta http-equiv='Content-Type'"
		." content='text/html; charset=utf-8' />\n"
		."<meta name='viewport'"
		." content='width=device-width,user-scalable=no,"
		."maximum-scale=1.0,minimum-scale=1.0' />\n";

	$ret .= "<link rel='alternate' type='application/atom+xml'"
		." title='Recent Entries' href='/feed.php' />\n";

	$t = sprintf ("/favicon.ico?s=%s", get_cache_defeater ());
	$ret .= sprintf ("<link rel='shortcut icon' type='image/x-icon'"
			 ." href='%s' />\n", fix_target ($t));


	$ret .= make_stylesheet_link ();

	return ($ret);
}

$page_gen_time_limit = 0.100;

function pfinish () {
	global $body;

	global $pstart_args;

	/* force IE to not use compatibility mode */
	header('X-UA-Compatible: IE=edge');

	if ($pstart_args->nocache) {
		header ("Cache-Control: no-store, no-cache, must-revalidate,"
			." post-check=0, pre-check=0");
		header ("Pragma: no-cache");
		header ("Expires: Thu, 19 Nov 1981 08:52:00 GMT");
	}

	$ret = "";

	$ret .= "<!DOCTYPE html>\n";

	/* defend against breach attack */
	$ret .= "<!-- ".generate_urandom_string(100+rand()%100)." -->\n";

	$ret .= "<!--[if lt IE 7]>"
		."	<html class='no-js lt-ie9 lt-ie8 lt-ie7'>"
		."	<![endif]-->\n"
		."	<!--[if IE 7]>"
		."	<html class='no-js lt-ie9 lt-ie8'><![endif]-->\n"
		."	<!--[if IE 8]>"
		."	<html class='no-js lt-ie9'><![endif]-->\n"
		."	<!--[if gt IE 8]>"
		."	<!--> <html class='no-js'><!--<![endif]-->\n";
	$ret .= "<head>\n";

	$ret .= html_head ();

	$ret .= google_analytics ();

	$ret .= "</head>\n";

	$ret .= sprintf ("<body %s %s>\n",
			 mkattr ("class", @$pstart_args->body_class),
			 mkattr ("id", @$pstart_args->body_id));

	$ret .= "<!--[if lt IE 7]><p class='chromeframe'>"
		."You are using an outdated browser. "
		."<a href='http://browsehappy.com/'>"
		."Upgrade your browser today</a> or "
		."<a href='http://www.google.com/chromeframe/"
		."?redirect=true'>install Google Chrome Frame"
		."</a> to better experience this site.</p>"
		."<![endif]-->";

	global $devel_mode;

	$ret .= "<div id='container' class='clearfix'>\n";
	$ret .= "<div id='container-inner'>\n";

	$ret .= banner_header ();

	$ret .= "<div id='content' class='clearfix'>\n";
	$ret .= "<div id='content-inner' class='clearfix'>\n";

	echo ($ret);
	global $body;
	echo ($body);

	$ret = "";

	$ret .= "</div>\n"; /* content-inner */
	$ret .= "</div>\n"; /* content */

	$ret .= banner_footer ();

	$ret .= footer_scripts ();

	global $extra_scripts;
	$ret .= $extra_scripts;

	global $extra_inline_javascript;
	if (@$extra_inline_javascript) {
		$ret .= "<script type='text/javascript'>\n";
		$ret .= $extra_inline_javascript;
		$ret .= "</script>\n";
	}

	/* end container and container inner*/
	$ret .= "</div>\n";
	$ret .= "</div>\n";


	echo ($ret);
	$ret = "";

	do_commits ();

	if (session_id ())
		session_write_close ();
	global $devel_mode, $page_gen_time_limit;
	if ($devel_mode) {
		global $start_microtime;
		$gentime = microtime (true) - $start_microtime;
		$ret .= "<script type='text/javascript'>\n";
		$ret .= sprintf ("var page_gen_time = %.3f;\n",
				 $gentime * 1000);
		$ret .= "</script>\n";

		if ($gentime > $page_gen_time_limit) {
			$ret .= sprintf ("<div id='page_gen_time'>"
					 ." excessive page gen time<br/>"
					 ." %.3fms</div>",
					 $gentime * 1000);
		}
	}

	$ret .= "</body>\n"
		."</html>\n";

	echo ($ret);
	exit ();
}

function ajax_finish ($ret) {
	do_commits ();
	@ob_end_clean ();
	echo (json_encode ($ret));
	exit ();
}

function html_parse ($html_frag) {
	libxml_use_internal_errors (true);
	$doc = new DOMDocument ();

	$html = "<!DOCTYPE html PUBLIC"
		." '-//W3C//DTD XHTML 1.0 Transitional//EN'"
		." 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'>"
		."<html xmlns='http://www.w3.org/1999/xhtml'>"
		."<head>"
		."<meta http-equiv='Content-Type'"
		." content='text/html; charset=utf-8' />"
		."<title></title>"
		."</head>"
		."<body>"
		."<div>";
	$html .= $html_frag;
	$html .= "</div></body></html>\n";

	$doc->loadHTML ($html);
	return ($doc);
}

function wrap_media_container ($html) {
	if (0) {
		/* test data */
		$html = "<p>"
			."<iframe src='foo'>"
			."  <iframe src='bar'>"
			."  </iframe>"
			."</iframe>"
			."<iframe src='xyz'>"
			."</iframe>"
			."<object src='obj1'>"
			."  <embed src='emb1'>"
			."  </embed>"
			."</object>"
			."<embed src='emb2'></embed>"
			."</p>";
	}

	$doc = new DOMDocument;

	@$doc->loadHTML ("<?xml encoding='utf-8'>" . $html);

	$xpath = new DOMXPath ($doc);

	$elts = $xpath->query ("//iframe[not(ancestor::iframe)]"
			       ."|//object[not(ancestor::object)]"
			       ."|//embed[not(ancestor::embed)"
			       ."          and not(ancestor::object)]");
	foreach ($elts as $elt) {
		$width = 0 + $elt->getAttribute ("width");
		$height = 0 + $elt->getAttribute ("height");

		if ($width)
			$aspect = $height / $width;
		else
			$aspect = 1;

		$val = sprintf ("padding-top:%.1f%%", $aspect * 100);

		$wrap = $doc->createElement ("div");
		$wrap->setAttribute ("class", "media_container");
		$wrap->setAttribute ("style", $val);
		$elt->parentNode->replaceChild ($wrap, $elt);
		$wrap->appendChild ($elt);
	}

	/* chop http or https from start of video url */
	/* this fixes <iframe src='...'> and <embed src='...'> */
	$elts = $xpath->query ("//iframe|//embed");
	foreach ($elts as $elt) {
		$val = $elt->getAttribute ("src");
		$val = preg_replace ("/^https?:/", "", $val);
		$elt->setAttribute ("src", $val);
	}

	/*
	 * this fixes <param name='src' value='...'> and
	 * <pararm name='movie' value='...'
	 */
	$elts = $xpath->query ("//object/param[@name='src' or @name='movie']");
	foreach ($elts as $elt) {
		$val = $elt->getAttribute ("value");
		$val = preg_replace ("/^https?:/", "", $val);
		$elt->setAttribute ("value", $val);
	}


	$elts = $xpath->query ("//body/*");
	$new_html = "";
	foreach ($elts as $elt) {
		$new_html .= $doc->saveHtml($elt);
	}
	if (0) {
		echo (h($new_html));
		exit ();
	}
	return ($new_html);
}

function html_get ($doc) {
	$xpath = new DOMXPath ($doc);
	$b = $xpath->query ("/html/body/div");
	return ($doc->saveXml ($b->item(0)));
}

function html_get_xpath ($doc, $expr) {
	$xpath = new DOMXPath ($doc);
	$node = $xpath->query ($expr);
	if (! $node)
		return (NULL);
	$val = $node->item(0);
	if (! $val)
		return (NULL);
	return ($doc->saveXml ($val));
}

function find_first_tag ($doc, $tag) {
	return (html_get_xpath ($doc, "//".$tag));
}

function require_password ($user_password, $desired_password_seq) {
	$url = $_SERVER['REQUEST_URI'];

	if(isset($user_password)) {
		if (@$_REQUEST['preview_password'] == $user_password) {
			$_SESSION['password_seq'] = $desired_password_seq;
			redirect ($url);
		}
	}

	if (@$_SESSION['password_seq'] == $desired_password_seq)
		return;

	$ret = "";
	$ret .= "<div style='text-align:center; margin:0 auto;'>\n";
	$ret .= sprintf ("<form action='%s' style='font-size:2em;'"
			 ." method='post' />", h($url));

	$ret .= "<div>\n";
	$ret .= "<input type='hidden' name='login' value='1' />\n";
	$ret .= "Password required: ";
	$ret .= "</div>\n";

	$ret .= "<div>\n";
	$ret .= "<input type='password' style='font-size:.8em;'"
		." name='preview_password'  />";
	$ret .= "</div>\n";

	$ret .= "<div>\n";
	$ret .= "<input type='submit' style='font-size:2em;'"
		." name='submit' value='Enter' />";
	$ret .= "</div>\n";

	$ret .= "</form>";
	$ret .= "</div>\n";

	echo ($ret);
	exit ();
}

function do_tidy ($raw_html, $tag_for_error = "") {
	$html = "<!DOCTYPE html PUBLIC"
		." '-//W3C//DTD XHTML 1.0 Transitional//EN'"
		." 'http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd'"
		.">"
		."<html xmlns='http://www.w3.org/1999/xhtml'>"
		."<head>"
		."<meta http-equiv='Content-Type'"
		." content='text/html; charset=utf-8' />"
		."<title></title>"
		."</head>"
		."<body>";
	$html .= $raw_html;
	$html .= "\n</body></html>\n";

	$config = array ();
	$config['indent'] = 1;
	$config['indent-spaces'] = 4;
	$config['indent-attributes'] = 1;
	$config['wrap'] = 120;
	$config['gnu-emacs'] = 1;
	$config['literal-attributes'] = 1;
	$config['output-xhtml'] = 1;
	$config['quote-nbsp'] = 1;
	$config['show-errors'] = 10;
	$config['vertical-space'] = 1;

	// $config['TidyCharEncoding'] = "utf8";

	$config['show-body-only'] = 1;
	$config['force-output'] = 1;
	$config['quiet'] = 1;
	$config['new-inline-tags'] = "video,audio,canvas";
	$config['new-blocklevel-tags']
		= "menu,article,header,footer,section,nav";
	$config['drop-proprietary-attributes'] = false;

	$tidy = new tidy;
	$tidy->parseString ($html, $config, 'utf8');
	$tidy->cleanRepair ();
	$tidy->diagnose ();

	if ($tidy->errorBuffer) {
		global $tidy_errs;
		if (! isset ($tidy_errs))
			$tidy_errs = "";
		if ($tag_for_error) {
			$tidy_errs .= sprintf ("<p>errors in %s</p>\n",
					       h($tag_for_error));
		}
		$tidy_errs .= "<pre>\n";
		$tidy_errs .= htmlentities ($tidy->errorBuffer, ENT_QUOTES,
					    'UTF-8');
		$tidy_errs .= "</pre>\n";
	}

	return (trim ($tidy));
}

function safe_contents ($filename) {
	if (! file_exists ($filename)) {
		echo (sprintf ("%s does not exit\n", h($filename)));
		exit ();
	}
	$tidy_errs = "";
	$html = file_get_contents ($filename);
	$safe_html = do_tidy ($html);
	if ($tidy_errs) {
		echo (sprintf ("validation errors for %s\n", $filename));
		echo ($tidy_errs);
		exit ();
	}

	return ($safe_html);
}

function nice_prefix ($str, $limit = 50) {
	$str = preg_replace ("/[ \t\r\n]+/", " ", $str);
	$str = trim ($str);
	if (strlen ($str) < $limit)
		return ($str);

	$str = substr ($str, 0, $limit);
	$str = preg_replace ("/ [^ ]*$/", "", $str);

	$str .= " ...";

	return ($str);
}


function getvar ($var, $defval = "") {
	global $vars_cache;
	if (! isset ($vars_cache)) {
		$vars_cache = array ();
		$q = query ("select var, val from vars");
		while (($r = fetch ($q)) != NULL) {
			if ($r->val)
				$vars_cache[$r->var] = $r->val;
			else
				$vars_cache[$r->var] = "";
		}
	}

	if (isset ($vars_cache[$var]))
		return ($vars_cache[$var]);
	return ($defval);
}

function setvar ($var, $val) {
	global $vars_cache;

	getvar ($var);

	if ($val == NULL)
		$val = "";

	if (isset ($vars_cache[$var])) {
		if (strcmp ($vars_cache[$var], $val) != 0) {
			query ("update vars set val = ? where var = ?",
			       array ($val, $var));
		}
	} else {
		query ("insert into vars (var, val) values (?, ?)",
		       array ($var, $val));
	}
	$vars_cache[$var] = $val;
}

function file_put_atomic ($filename, $val) {
	$tname = tempnam (dirname ($filename), "TMP");
	if (($f = fopen ($tname, "w")) == NULL) {
		unlink ($tname);
		return (-1);
	}
	chmod ($tname, 0664);
	fwrite ($f, $val);
	fclose ($f);
	rename ($tname, $filename);
	return (0);
}

function valid_identifier ($str) {
	if (preg_match ('/[^_a-z0-9]/', $str))
		return (0);
	if (! preg_match ('/^[a-z]/', $str))
		return (0);
	$len = strlen ($str);
	if ($len < 1 || $len > 30)
		return (0);
	return (1);
}

function safe_filename ($str) {
	if (preg_match ('/[^-_a-z0-9]/', $str))
		return (0);
	if (! preg_match ('/^[a-z]/', $str))
		return (0);
	$len = strlen ($str);
	if ($len < 1 || $len > 50)
		return (0);
	return (1);
}

function run_command ($cmd)
{
	dbg ($cmd);
	do_commits ();
	exec ($cmd, $outlines, $rc);
	if ($rc == 0)
		return ("");

        $ret = "";

	$arr = explode (" ", $cmd);
	$file = @$arr[0];

	$ret .= sprintf ("error running %s\n", $file);
	if ($file && file_exists ($file) == 0)
		$ret .= "(not installed properly)\n";

	global $devel_mode;
	if ($devel_mode) {
		$ret .= sprintf ("ret = %s; cmd = %s\n", $rc, $cmd);
	}

	if ($ret)
		$ret .= "\n\n";

	if ($outlines)
		$ret .= join ("\n", $outlines);

	return ($ret);
}


$newcache_clean_flag = 0;
$newcache_duration = 1;

function get_other_mtime () {
	global $cache_src_mtime, $site_root;

	if (! isset ($cache_src_mtime)) {
		$dir = opendir ($site_root);
		$newest = 0;
		while (($filename = readdir ($dir)) != NULL) {
			if (preg_match ('/\\.php$/', $filename)) {
				$fullname = $site_root . "/" . $filename;
				$t = filemtime ($fullname);
				if ($t > $newest)
					$newest = $t;
			}
		}
		closedir ($dir);

		if (@$_SERVER['wordpress_flag']) {
			$q = query ("select max(post_modified) as dttm"
				    ." from wp_posts");
			if (($r = fetch ($q)) != NULL) {
				$t = strtotime ($r->dttm . " +0000");
				if ($t > $newest)
					$newest = $t;
			}
		}
		$cache_src_mtime = $newest;
	}

	return ($cache_src_mtime);
}

function newcache_clean () {
	global $newcache_duration, $newcache_clean_flag, $siteid;

	if ($newcache_clean_flag)
		return;
	$newcache_clean_flag = 1;

	if (rand (0, 99) != 0)
		return;

	$dirname = sprintf ("/tmp/%s-cache", $siteid);
	if (($dir = @opendir ($dirname)) == NULL)
		return;

	$now = time ();
	$files = array ();
	while (($name = readdir ($dir)) != NULL) {
		$fullname = sprintf ("%s/%s", $dirname, $name);
		if ($now - @filemtime ($fullname) > $newcache_duration)
			$files[] = $name;
	}
	closedir ($dir);

	foreach ($files as $name) {
		$fullname = sprintf ("%s/%s", $dirname, $name);
		@unlink ($fullname);
	}
}

function newcache_invalidate ($key = "") {
	global $siteid;

	if ($key) {
		$fullname = sprintf ("/tmp/%s-cache/%s", $siteid, md5($key));
		@unlink ($fullname);
	} else {
		$dirname = sprintf ("/tmp/%s-cache", $siteid);
		$files = array ();
		if (($dir = @opendir ($dirname)) != NULL) {
			$files[] = readdir ($dir);
		}
		foreach ($files as $name) {
			$fullname = sprintf ("%s/%s", $dirname, $name);
			@unlink ($fullname);
		}
		closedir ($dir);
	}
}

/* magic argument handling: args are generating function as string,
 * then any areguments the generating function needs
 */
function newcache ($gen_func /* optional additional args */) {
	global $newcache_duration, $devel_mode, $page_lang;

	newcache_clean ();

	$args = array_slice (func_get_args (), 1);
	$key = sprintf ("%s|%s", $gen_func, $page_lang);
	foreach ($args as $arg)
		$key .= "|".newcache_make_key_string ($arg);

	$dirname = sprintf ("/tmp/%s-cache", $siteid);
	if (! file_exists ($dirname))
		mkdir ($dirname, 0775);
	$fullname = sprintf ("%s/%s", $dirname, md5 ($key));
	$cached_mtime = 0 + @filemtime ($fullname);

	if ($devel_mode) {
		$other_mtime = get_other_mtime ();
		if ($other_mtime > $cached_mtime)
			$cached_mtime = 0;
	}

	if (time() - $cached_mtime > $newcache_duration) {
		$val = call_user_func_array ($gen_func, $args);
		file_put_atomic ($fullname, serialize ($val));

		$argname = sprintf ("%s.args", $fullname);
		file_put_atomic ($argname,
				 strftime ("%Y-%m-%d %H:%M:%S\n")
				 .var_export ($gen_func, true)
				 ."\n"
				 .var_export ($args, true));
	} else {
		$raw_val = file_get_contents ($fullname);
		$val = @unserialize ($raw_val);
	}

	return ($val);
}

function newcache_make_key ($basename, $val) {
	return ($basename . ":" . newcache_make_key_string ($val));
}

function newcache_guts ($gen_func_and_args, $cache_args = NULL) {
	global $aux_dir, $newcache_duration, $devel_mode;

	if (count ($gen_func_and_args) == 0)
		fatal ("newcache must be called with a generating function");

	newcache_clean ();
	
	$key = newcache_make_key ($gen_func_and_args);

	$fullname = sprintf ("%s/newcache/%s", $aux_dir, md5 ($key));
	$cached_mtime = 0 + @filemtime ($fullname);

	if ($devel_mode) {
		$other_mtime = get_other_mtime ();
		if ($other_mtime > $cached_mtime) {
			$cached_mtime = 0;
		}
	}

	if (@$cache_args->sentinel_file
	    && (@filemtime ($cache_args->sentinel_file) > $cached_mtime)) {
		$cached_mtime = 0;
	}

	if (time() - $cached_mtime > $newcache_duration) {
		$gen_func = $gen_func_and_args[0];
		$gen_args = array_slice ($gen_func_and_args, 1);

		$val = call_user_func_array ($gen_func, $gen_args);
		file_put_atomic ($fullname, serialize ($val));
		
		$argname = sprintf ("%s.args", $fullname);
		file_put_atomic ($argname,
				 strftime ("%Y-%m-%d %H:%M:%S\n")
				 .var_export ($gen_func_and_args, true));
	} else {
		$raw_val = file_get_contents ($fullname);
		$val = @unserialize ($raw_val);
	}

	return ($val);
}
function newcache_make_key_string ($val) {
	switch (gettype ($val)) {
	case "string":
		return ($val);
	case "boolean":
	case "integer":
	case "double":
	case "float":
		return ((string)$val);
	case "array": case "object":
		$arr = array ();
		foreach ($val as $field_name => $field_val) {
			$elt = (object)NULL;
			$elt->name = $field_name;
			$elt->val = $field_val;
			$arr[] = $elt;
		}
		usort ($arr, function ($a, $b) {
				return(strcmp($a->name, $b->name));
			});

		$ret = "{";
		$sep = "";
		for ($idx = 0; $idx < count($arr); $idx++) {
			$elt = $arr[$idx];
			$ret .= $sep
				. $elt->name
				. "="
				. newcache_make_key_string($elt->val)
				;
			$sep = "|";
		}
		$ret .= "}";
		return ($ret);
	default:
		var_dump ($val);
		fatal ("newcache_make_key: invalid");
		break;
	}
}

function set_cache ($duration_secs) {
	if ($duration_secs == 0) {
		header ("Cache-Control: no-store, no-cache, must-revalidate,"
			." post-check=0, pre-check=0");
		header ("Pragma: no-cache");
		header ("Expires: Thu, 19 Nov 1981 08:52:00 GMT");
	} else {
		$t = time () + $duration_secs;
		$ts = gmdate ("D, d M Y H:i:s", $t) . " GMT";

		header ("Expires: $ts");
		header ("Cache-Control: max-age=".$duration_secs.", public");
		header ("Pragma: public");
	}
}


function redirect_https ($requests, $pagename) {
	global $devel_mode;

	if (! $devel_mode && strcmp (@$_SERVER['HTTPS'], "on") != 0) {
		global $ssl_url;
		$url = $ssl_url . build_query_url ($requests,
						   $pagename,
						   'https');
		redirect ($url);
	}
}
