<?php

/* defined('RELEASE_TARGET') OR define('RELEASE_TARGET', 'prod');	*/
defined('RELEASE_TARGET') OR define('RELEASE_TARGET', 'dev');	

defined('PROCESSED_RSS_ITEMS_REG_NAME') OR define('PROCESSED_RSS_ITEMS_REG_NAME', 'published.txt');
defined('TXT_FILE_ROW_END') OR define('TXT_FILE_ROW_END', "\n");
defined('PROCESSED_RSS_ITEMS_REG_MAX_SIZE') OR define('PROCESSED_RSS_ITEMS_REG_MAX_SIZE', 100);
defined('WP_TARGET_API_ENDPOINT') OR define('WP_TARGET_API_ENDPOINT', '/wp-json/wp/v2/');

if (trim(strtolower(RELEASE_TARGET)) === 'dev')
	require_once('config/dev/settings.php');	

if (trim(strtolower(RELEASE_TARGET)) === 'test')
	require_once('config/test/settings.php');		

if (trim(strtolower(RELEASE_TARGET)) === 'prod')
	require_once('config/prod/settings.php');	
