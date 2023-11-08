<?php

defined('TELEGRAM_RSS_URL') OR define('TELEGRAM_RSS_URL', 'https://rsshub.botheory.com/telegram/channel/abdevtest1');
defined('SMART_DOWNLOADER_ENDPOINT') OR define('SMART_DOWNLOADER_ENDPOINT', 'http://botheory.api/down/');
defined('FULL_TEXT_RSS_URL') OR define('FULL_TEXT_RSS_URL', 'http://localhost/scienzacoscienza.com/bacheca/full-text-rss');
defined('WP_WEBSITE_TARGET_URL') OR define('WP_WEBSITE_TARGET_URL', 'http://localhost/scienzacoscienza.com/bacheca');
defined('WP_PUBLISH_AS_DRAFT') OR define('WP_PUBLISH_AS_DRAFT', false);

/* defined('DOMAIN_BLACK_LIST') OR define('DOMAIN_BLACK_LIST', ['renovatio21.com', 'voltairenet.org', 'thecradle.co', 'corrierecomunicazioni.it', 'paulcraigroberts.org', 'comedonchisciotte.org', 'mediasetinfinity.mediaset.it', 'maurizioblondet.it', 'lavocedinovara.com']);    */
defined('DOMAIN_BLACK_LIST') OR define('DOMAIN_BLACK_LIST', ['renovatio21.com', 'voltairenet.org', 'thecradle.co', 'corrierecomunicazioni.it', 'paulcraigroberts.org', 'mediasetinfinity.mediaset.it', 'maurizioblondet.it', 'lavocedinovara.com']);

defined('AI_SEARCH_API_URL') OR define('AI_SEARCH_API_URL', 'http://botheory.api/openai/search/');
defined('AI_CHAT_API_URL') OR define('AI_CHAT_API_URL', 'http://botheory.api/openai/chat/');
defined('AI_STT_API_URL') OR define('AI_STT_API_URL', 'http://botheory.api/openai/stt/');
