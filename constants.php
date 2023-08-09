<?php

/* defined('RELEASE_TARGET') OR define('RELEASE_TARGET', 'prod');	*/
defined('RELEASE_TARGET') OR define('RELEASE_TARGET', 'dev');	

defined('NEW_LINE_REPLACEMENT') OR define('NEW_LINE_REPLACEMENT', '[§]');	

defined('PROCESSED_RSS_ITEMS_REG_NAME') OR define('PROCESSED_RSS_ITEMS_REG_NAME', 'published.txt');
defined('TXT_FILE_ROW_END') OR define('TXT_FILE_ROW_END', "\n");
defined('PROCESSED_RSS_ITEMS_REG_MAX_SIZE') OR define('PROCESSED_RSS_ITEMS_REG_MAX_SIZE', 100);
defined('WP_TARGET_API_ENDPOINT') OR define('WP_TARGET_API_ENDPOINT', '/wp-json/wp/v2/');
defined('YTD_PREFERRED_QUALITY_ALL') OR define('YTD_PREFERRED_QUALITY_ALL', 0);
defined('YTD_PREFERRED_QUALITY_BEST') OR define('YTD_PREFERRED_QUALITY_BEST', 2);
defined('YTD_PREFERRED_QUALITY_LOWER') OR define('YTD_PREFERRED_QUALITY_LOWER', 4);
defined('YTD_MEDIA_TYPE_ALL') OR define('YTD_MEDIA_TYPE_ALL', 0);
defined('YTD_MEDIA_TYPE_COMBINED') OR define('YTD_MEDIA_TYPE_COMBINED', 2);
defined('YTD_MEDIA_TYPE_AUDIO_ONLY') OR define('YTD_MEDIA_TYPE_AUDIO_ONLY', 4);
defined('YTD_MEDIA_TYPE_VIDEO_ONLY') OR define('YTD_MEDIA_TYPE_VIDEO_ONLY', 8);
defined('YTD_MEDIA_MAX_SIZE') OR define('YTD_MEDIA_MAX_SIZE', 10485760);

if (trim(strtolower(RELEASE_TARGET)) === 'dev')
	require_once('config/dev/settings.php');	

if (trim(strtolower(RELEASE_TARGET)) === 'test')
	require_once('config/test/settings.php');		

if (trim(strtolower(RELEASE_TARGET)) === 'prod')
	require_once('config/prod/settings.php');	
