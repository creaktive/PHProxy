<?php

/*
	PHProxy - Q&D way to emulate mod_proxy's ProxyPass via mod_rewrite+PHP
	Edit the definitions at the start of the code to configure.
	Copyright (C) 2010  Stanislaw Pusep

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/************************************************************************
WARNING    WARNING    WARNING    WARNING    WARNING    WARNING    WARNING

DO NOT USE THIS "PROXY" ON A PRODUCTION SYSTEM! IT IS FAR FROM COMPLETE!
This code emulates only a *SUBSET* of HTTP/1.0 protocol, it works fine
for testing purposes and as a quick replacement until mod_proxy is set up
and enabled. I wrote it mostly because other alternatives I found were
both too complex AND incomplete. Thus, my solution doesn't depend on
complex settings neither dependencies, it only requires fsockopen()
to be available. Good luck!

/***********************************************************************/

// ignore any warning
error_reporting(0);

// destination host settings
define('HOST',		'sysd.org');
define('PORT',		80);
define('TIMEOUT',	10);
define('MAXDATA',	10485760);	// 10 MB

// $base is only used to discover the URI at the destination host
$base = dirname(empty($_SERVER['PHP_SELF']) ? $_SERVER['SCRIPT_NAME'] : $_SERVER['PHP_SELF']);
$base = str_replace(DIRECTORY_SEPARATOR, '/', $base);
$base = rtrim($base, '/');
// this one is a bit tricky... maybe it is a better idea to pass URI directly via .htaccess
$uri = substr($_SERVER['REQUEST_URI'], strlen($base));
if ($uri[0] != '/')
	$uri = '/' . $uri;

// start building the request packet; downgrades to HTTP/1.0!
$req = "$_SERVER[REQUEST_METHOD] {$uri} HTTP/1.0\r\n";
$req .= "Host: " . HOST . "\r\n";
$req .= "X-Forwarded-For: $_SERVER[REMOTE_ADDR]\r\n";

// HTTP tags we'll forward unchanged
$tags = array(
	'Accept',
	'Accept-Charset',
	'Accept-Encoding',
	'Accept-Language',
	'Cache-Control',
	'Content-Type',
	'Cookie',
	'If-Modified-Since',
	'If-None-Match',
	'Pragma',
	'Referer',
	'User-Agent'
);
foreach ($tags as $hdr) {
	// a little bruteforcing trick here, as there's no consistent way of getting passed header values
	$key = strtoupper(strtr($hdr, '-', '_'));
	if (isset($_SERVER[$key])) {
		$val = $_SERVER[$key];
		$req .= "{$hdr}: {$val}\r\n";
	} else if (isset($_SERVER['HTTP_' . $key])) {
		$val = $_SERVER['HTTP_' . $key];
		$req .= "{$hdr}: {$val}\r\n";
	}
}

// rebuild the POST arguments... 99.99% of troubles are here
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$post = http_build_query($_POST, '', '&');
	$req .= "Content-Length: " . strlen($post) . "\r\n";
	$req .= "\r\n";
	$req .= $post;
} else
	$req .= "\r\n";

// comment the line below for some cheap debugging features
/*
$fh = fopen(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'phproxy.log', 'a');
fwrite($fh, $req);
fclose($fh);
//*/

$hdr = array();
$res = '';

// now, to a "raw" TCP/IP transaction!
if (($fs = @fsockopen(@gethostbyname(HOST), PORT, $errno, $errstr, TIMEOUT)) != false) {
	// send request
	fwrite($fs, $req);

	// read response in multiple packets
	while (!feof($fs) && (strlen($res) < MAXDATA))
		$res .= fgets($fs, 1160);	// One TCP-IP packet
	fclose($fs);

	// parse response headers
	list($tmp, $res) = preg_split('%\r?\n\r?\n%', $res, 2);
	$tmp = preg_split('%\r?\n%', $tmp, -1, PREG_SPLIT_NO_EMPTY);
	$cod = array_shift($tmp);

	// is it a valid HTTP response?
	if (preg_match('%^HTTP/(1\.[01])\s+([0-9]{3})\s+(.+)$%i', $cod, $match)) {
		// reassemble the response
		header("HTTP/1.0 $match[2] $match[3]");
		// reassemble the headers
		foreach ($tmp as $line)
			if (preg_match('%^([A-Z-a-z\-]+):\s*(.+)$%', $line, $match))
				header($match[1] . ': ' . $match[2]);
	}
}

// now dump content!
echo $res;

?>