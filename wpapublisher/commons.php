<?php

function wpap_curl(string $url, ?array $options = array(), ?string &$err = '', ?array &$header = array()): ?string {

	$headers = array(
		"Cache-Control: no-cache, no-store, must-revalidate",
		"Pragma: no-cache",
		"Expires: 0",
	);

	if (!empty($header))
		$headers = $header + $headers;

	$defaults = array(
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_FORBID_REUSE => 1,
		CURLOPT_TIMEOUT => 200,
		CURLOPT_CONNECTTIMEOUT => 0,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false,
		CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'
	);

	$ch = curl_init($url);

	if (!empty($options))
		$defaults = $options + $defaults;

	curl_setopt_array($ch, $defaults);
	curl_setopt($ch, CURLOPT_HEADERFUNCTION,
		function($curl, $headers) use (&$header) {
			$len = strlen($headers);
			$headers = explode(':', $headers, 2);
			if (count($headers) < 2) // ignore invalid headers
				return $len;

			$header[strtolower(trim($headers[0]))][] = trim($headers[1]);

			return $len;
		}
	);

	$header = [];

	if( ! $result = curl_exec($ch)) {
		$err .= curl_error($ch);
		$result = null;
	}

	curl_close($ch);
	return $result;

}

function wpap_curl_post(string $url, $data, ?array $options = array(), ?string &$err = '', array $header = array(), bool $form_mode = true): ?string {

	$headers = array(
		"Cache-Control: no-cache, no-store, must-revalidate",
		"Pragma: no-cache",
		"Expires: 0",
	);

	if ($form_mode) {
		if (is_array($data)) {
			$payload = http_build_query($data);
		} else {
			$payload = $data;
		}
	} else {
		$headers[] = 'Content-Type:application/json';
		if (is_array($data)) {
			$payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		} else {
			$payload = $data;
		}
	}

	if (!empty($header))
		$headers = $header + $headers;

	$defaults = array(
		CURLOPT_POST => 1,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_POSTFIELDS => $payload,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_FORBID_REUSE => 1,
		CURLOPT_TIMEOUT => 200,
		CURLOPT_CONNECTTIMEOUT => 0,
		CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'
	);

	$ch = curl_init($url);

	if (!empty($options))
		$defaults = $options + $defaults;

	curl_setopt_array($ch, $defaults);

	if( ! $result = curl_exec($ch)) {
		$err .= curl_error($ch);
		$result = null;
	}

	curl_close($ch);
	return $result;

}

function get_token($key, $token, $time_stamp = null) {

	if (!$time_stamp) {
		$time_stamp = time();
	}

	if ($time_stamp % 2 == 0) {
		$token = $token . $time_stamp . $token;
	} else {
		$token = $time_stamp . $token;
	}

	$token = hash("sha256", $token);
	return $time_stamp . '_' . $key . '_' . $token;

}