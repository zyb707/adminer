<?php
$connection = '';

$has_token = $_SESSION["token"];
if (!$has_token) {
	$_SESSION["token"] = rand(1, 1e6); // defense against cross-site request forgery
}
$token = get_token(); ///< @var string CSRF protection

$permanent = array();
if ($_COOKIE["adminer_permanent"]) {
	foreach (explode(" ", $_COOKIE["adminer_permanent"]) as $val) {
		list($key) = explode(":", $val);
		$permanent[$key] = $val;
	}
}

function add_invalid_login() {
	global $adminer;
	$filename = get_temp_dir() . "/adminer.invalid";
	$fp = @fopen($filename, "r+"); // @ - may not exist
	if (!$fp) { // c+ is available since PHP 5.2.6
		$fp = @fopen($filename, "w"); // @ - may not be writable
		if (!$fp) {
			return;
		}
	}
	flock($fp, LOCK_EX);
	$invalids = unserialize(stream_get_contents($fp));
	$time = time();
	if ($invalids) {
		foreach ($invalids as $ip => $val) {
			if ($val[0] < $time) {
				unset($invalids[$ip]);
			}
		}
	}
	$invalid = &$invalids[$adminer->bruteForceKey()];
	if (!$invalid) {
		$invalid = array($time + 30*60, 0); // active for 30 minutes
	}
	$invalid[1]++;
	$serialized = serialize($invalids);
	rewind($fp);
	fwrite($fp, $serialized);
	ftruncate($fp, strlen($serialized));
	flock($fp, LOCK_UN);
	fclose($fp);
}

session_regenerate_id(); // defense against session fixation

foreach ($servers_list as $key => $value) {
	set_password($value['vendor'], $value['server'], $value['username'], $value['password']);
	$_SESSION["db"][$value['vendor']][$value['server']][$value['username']][$value['db']] = true;
	$permanent_key = true;
	if ($permanent_key) {
		$key = base64_encode($value['vendor']) . "-" . base64_encode($value['server']) . "-" . base64_encode($value['username']) . "-" . base64_encode($value['db']);
		$private = $adminer->permanentLogin(true);
		$permanent[$key] = "$key:" . base64_encode($private ? encrypt_string($value['password'], $private) : "");
		cookie("adminer_permanent", implode(" ", $permanent));
	}

}



$auth = $_POST["auth"];
/*if ($_POST["logout"]) {
	if ($has_token && !verify_token()) {
		page_header(lang('Logout'), lang('Invalid CSRF token. Send the form again.'));
		page_footer("db");
		exit;
	} else {
		foreach (array("pwds", "db", "dbs", "queries") as $key) {
			set_session($key, null);
		}
		unset_permanent();
		redirect(substr(preg_replace('~\b(username|db|ns)=[^&]*&~', '', ME), 0, -1), lang('Logout successful.'));
	}
	
} else*/if ($permanent && !$_SESSION["pwds"]) {
	session_regenerate_id();
	$private = $adminer->permanentLogin();
	foreach ($permanent as $key => $val) {
		list(, $cipher) = explode(":", $val);
		list($vendor, $server, $username, $db) = array_map('base64_decode', explode("-", $key));
		set_password($vendor, $server, $username, decrypt_string(base64_decode($cipher), $private));
		$_SESSION["db"][$vendor][$server][$username][$db] = true;
	}
}

function unset_permanent() {
	global $permanent;
	foreach ($permanent as $key => $val) {
		list($vendor, $server, $username, $db) = array_map('base64_decode', explode("-", $key));
		if ($vendor == DRIVER && $server == SERVER && $username == $_GET["username"] && $db == DB) {
			unset($permanent[$key]);
		}
	}
	cookie("adminer_permanent", implode(" ", $permanent));
}

/** Renders an error message and a login form
* @param string plain text
* @return null exits
*/
function auth_error($error) {
	global $adminer, $has_token;
	$error = h($error);
	$session_name = session_name();
	if (isset($_GET["username"])) {
		header("HTTP/1.1 403 Forbidden"); // 401 requires sending WWW-Authenticate header
		if (($_COOKIE[$session_name] || $_GET[$session_name]) && !$has_token) {
			$error = lang('Session expired, please login again.');
		} else {
			add_invalid_login();
			$password = get_password();
			if ($password !== null) {
				if ($password === false) {
					$error .= '<br>' . lang('Master password expired. <a href="https://www.adminer.org/en/extension/" target="_blank">Implement</a> %s method to make it permanent.', '<code>permanentLogin()</code>');
				}
				set_password(DRIVER, SERVER, $_GET["username"], null);
			}
			unset_permanent();
		}
	}
	if (!$_COOKIE[$session_name] && $_GET[$session_name] && ini_bool("session.use_only_cookies")) {
		$error = lang('Session support must be enabled.');
	}
	$params = session_get_cookie_params();
	cookie("adminer_key", ($_COOKIE["adminer_key"] ? $_COOKIE["adminer_key"] : rand_string()), $params["lifetime"]);
	page_header(lang('Login'), $error, null);
	echo "<form action='' method='post'>\n";
	// $adminer->loginForm();
	echo "<div>";
	hidden_fields($_POST, array("auth")); // expired session
	echo "</div>\n";
	echo "</form>\n";
	page_footer("auth");
	exit;
}

if (isset($_GET["username"])) {
	if (!class_exists("Min_DB")) {
		unset($_SESSION["pwds"][DRIVER]);
		unset_permanent();
		page_header(lang('No extension'), lang('None of the supported PHP extensions (%s) are available.', implode(", ", $possible_drivers)), false);
		page_footer("auth");
		exit;
	}
	$connection = connect();
}

$driver = new Min_Driver($connection);

if (!is_object($connection) || ($login = $adminer->login($_GET["username"], get_password())) !== true) {
	auth_error((is_string($connection) ? $connection : (is_string($login) ? $login : lang('Invalid credentials.'))));
}

if ($auth && $_POST["token"]) {
	$_POST["token"] = $token; // reset token after explicit login
}

$error = ''; ///< @var string
if ($_POST) {
	if (!verify_token()) {
		$ini = "max_input_vars";
		$max_vars = ini_get($ini);
		if (extension_loaded("suhosin")) {
			foreach (array("suhosin.request.max_vars", "suhosin.post.max_vars") as $key) {
				$val = ini_get($key);
				if ($val && (!$max_vars || $val < $max_vars)) {
					$ini = $key;
					$max_vars = $val;
				}
			}
		}
		$error = (!$_POST["token"] && $max_vars
			? lang('Maximum number of allowed fields exceeded. Please increase %s.', "'$ini'")
			: lang('Invalid CSRF token. Send the form again.') . ' ' . lang('If you did not send this request from Adminer then close this page.')
		);
	}
	
} elseif ($_SERVER["REQUEST_METHOD"] == "POST") {
	// posted form with no data means that post_max_size exceeded because Adminer always sends token at least
	$error = lang('Too big POST data. Reduce the data or increase the %s configuration directive.', "'post_max_size'");
	if (isset($_GET["sql"])) {
		$error .= ' ' . lang('You can upload a big SQL file via FTP and import it from server.');
	}
}
