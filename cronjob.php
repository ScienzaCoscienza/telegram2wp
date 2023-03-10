<?php 

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('constants.php');	
require_once('wpapublisher/jwt_wp_api.class.php');	

try {

	$wp_website_target_user = get_env_var('SC_WP_ADMIN_USER');
	$wp_website_target_password = get_env_var('SC_WP_ADMIN_PWD');
	
	$simpleXml = simplexml_load_file(TELEGRAM_RSS_URL, "SimpleXMLElement", LIBXML_NOCDATA);

	if ($simpleXml !== false) {
		$rss_json = json_encode($simpleXml);
		if (trim($rss_json)) {
			$rss_json = json_decode($rss_json, true);
			if ((!empty($rss_json)) && (!empty($rss_json['channel']['item']))) {
				$items = $rss_json['channel']['item'];
				if (is_array($items)) {
					foreach($items as $item) {
						if (!empty($item['guid'])) {
							$item_guid = $item['guid'];
							if (can_proc_rss_item($item_guid)) {
								if (process_rss_item($item)) {
									mark_rss_item_as_processed($item_guid);
								}
							}
						}
					}
					clean_rss_item_reg();
				}
			}
		}
	}
} catch (Exception $e) {
    echo 'Caught exception: ' . $e->getMessage() . "\n";
	ab_log('Caught exception: ' . $e->getMessage());
}

/*******************************************************************************
FUNCTIONS
*******************************************************************************/

function can_proc_rss_item($item_guid) {
	
	if (file_exists(PROCESSED_RSS_ITEMS_REG_NAME)) {
		$reg = file_get_contents(PROCESSED_RSS_ITEMS_REG_NAME);
		if ($reg !== false) {
			return (strpos($reg, "[[$item_guid]]") === false);
		}
	}
	
	return true;
	
}

function mark_rss_item_as_processed($item_guid) {
	
	file_put_contents(PROCESSED_RSS_ITEMS_REG_NAME, "[[$item_guid]]" . TXT_FILE_ROW_END, FILE_APPEND);
	
}

function clean_rss_item_reg() {
	
	if ((PROCESSED_RSS_ITEMS_REG_MAX_SIZE > 0) && file_exists(PROCESSED_RSS_ITEMS_REG_NAME)) {
		$reg = file_get_contents(PROCESSED_RSS_ITEMS_REG_NAME);
		if ($reg !== false) {
			$reg = explode(TXT_FILE_ROW_END, $reg);
			if ($reg !== false) {
				$reg_size = count($reg);
				if ($reg_size > PROCESSED_RSS_ITEMS_REG_MAX_SIZE) {
					$count = 0;
					$new_reg = '';
					foreach ($reg as $item) {
						$item = trim($item);
						if ($item) {
							$count += 1;
							if ($count >= ($reg_size - PROCESSED_RSS_ITEMS_REG_MAX_SIZE))
								$new_reg .= $item . TXT_FILE_ROW_END;
						}
					}
					file_put_contents(PROCESSED_RSS_ITEMS_REG_NAME, $new_reg);
				}
			}
		}
	}
	
}

function process_rss_item($item) {
	
	if (is_array($item) && (!empty($item['title']))) {
		$title = $item['title'];
		if (filter_var(str_replace('...', '', trim($title)), FILTER_VALIDATE_URL)) {
			$content_link = extract_content_url($item['description']);
			return process_link_post($content_link);
		} else {
		
		
		
		

		}
	}
	
	return false;
	
}

function extract_content_url($content) {
	
	$content = trim($content);
	
	if ($content) {
		$pos1 = strpos($content, '<a href="');
		if ($pos1 !== false) {
			$pos1 += 9;
			$pos2 = strpos($content, '"', $pos1);
			if ($pos2 !== false) {
				return trim(substr($content, $pos1, $pos2 - $pos1));
			}
		}
	}
	
	return false;
		
}

function process_link_post($content_link) {

	global $wp_website_target_user, $wp_website_target_password;
	
	
	if (filter_var($content_link, FILTER_VALIDATE_URL)) {
		$content_link = urlencode($content_link);
		$content_link = str_ireplace('https%3A%2F%2F', 'sec%3A%2F%2F', $content_link);
		$content_link = str_ireplace('http%3A%2F%2F', 'sec%3A%2F%2F', $content_link);
		$ftr_url = FULL_TEXT_RSS_URL . "/makefulltextfeed.php?url={$content_link}&max=1&links=preserve&exc=1&summary=1&format=json&submit=Create+Feed";
		$json_data = ab_curl($ftr_url);
		if ($ftr_url !== false) {
			$json_data = json_decode($json_data, true);
			if (is_array($json_data) && (!empty($json_data))) {
				if (!empty($json_data['rss']['channel']['item'])) {
					$json_data = $json_data['rss']['channel']['item'];
					if (!empty($json_data['link'])) {
						$link = $json_data['link'];
						$featured_image = null;
						if (!empty($json_data['og_image'])) {
							$featured_image = $json_data['og_image'];
						} else {
							if (!empty($json_data['twitter_image']))
								$featured_image = $json_data['twitter_image'];
						}
						if ($featured_image) {
							$title = filter_title(trim($json_data['title']));
							if ($title) {
								$excerpt = '';
								if (!empty($json_data['description'])) {
									$excerpt = trim($json_data['description']);
								} else {
									if (!empty($json_data['og_description']))
										$excerpt = $json_data['og_description'];
								}
								if ($excerpt) {
									$excerpt = filter_excerpt($excerpt);
									$domain = null;
									$content_html = filter_content($json_data['content_encoded']);
									if ($content_html) {
										$content_html .= post_credits_html($link, $domain);
									} else {
										return true;
									}
									if (!in_array(trim(strtolower($domain)), DOMAIN_BLACK_LIST, true)) {
										$post = new WpPost($title, $content_html, $excerpt);
										if (WP_PUBLISH_AS_DRAFT) {
											$post->status = 'draft';	
										} else {
											$post->status = 'publish';	
										}
										$the_tags = get_content_tags("$content_html $excerpt $domain");
										if (strripos(implode('|', $the_tags), $domain) === false) {
											if ($the_tags === null)
												$the_tags = [];
											$the_tags[] = $domain;
										}
										$post->tags = $the_tags;
										$post->featured_media_url = $featured_image;
										$JWTWpAPI = new JWTWpAPI(WP_WEBSITE_TARGET_URL, $wp_website_target_user, $wp_website_target_password, false);
										$err = '';
										$extra_data = [];
										$extra_data['_yoast_wpseo_metadesc'] = $excerpt;
										$extra_data['meta_data'] = [['key' => '_yoast_wpseo_metadesc', 'value' => $excerpt]];
										if ($JWTWpAPI->create_post($post, $err, true, $extra_data)) {
											return true;
										} else {
											echo '[ERROR] ' . $err . "\n";
											ab_log('[ERROR] ' . $err);
										}
									}
								}
							}
						}
					}
				}
			}
		}
	}
	
	return true;
	
}

function filter_content($content) {

/* [START]> lindipendente.online */	
	if (strpos($content, 'Senza-titolo-3.png') !== false)
		return '';
/* <[END] lindipendente.online */	

/* [START]> ideeazione.com */	
	if (strpos($content, 'ideeazione') !== false) { // Verifica della fonte 
		$pos1 = strpos($content, '<p><strong>Seguici sui nostri canali');
		if ($pos1 !== false) {
			$content = substr($content, 0, $pos1);
		}	
	}	
/* <[END] ideeazione.com */	
	
/* [START]> affaritaliani.it */	
	$pos1 = strpos($content, '"nl-bottom-content"');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1 - 14);
	}	
/* <[END] affaritaliani.it */	

/* [START]> comedonchisciotte.org */	
	if (strpos($content, 'comedonchisciotte') !== false) { // Verifica della fonte 
		$content = str_ireplace('data-src="', 'src="', $content);
		$content = str_ireplace('class="ff-og-image-inserted"', '', $content);
		$pos1 = strpos($content, '<div class="avvisotg"');
		if ($pos1 !== false) {
			$pos2 = strpos($content, '</div>', $pos1 + 21);
			if ($pos2 !== false) {
				$content1 = substr($content, 0, $pos1);
				$content2 = substr($content, $pos2 + 6);
				$content = $content1 . $content2; 
			}	
		}	
		$content = str_ireplace('<p><strong>I canali Telegram ufficiali di comedonchisciotte.org sono i seguenti e SOLO questi</strong>:</p>', '', $content);
		$content = str_ireplace('<p><strong>FONTI:</strong></p>', '', $content);
		$content = str_ireplace('class="post-author-avatar', 'class="hidden post-author-avatar', $content);
		$content = str_ireplace('class="post-author-bio', 'class="hidden post-author-bio', $content);
	}	
/* <[END] comedonchisciotte.org */	

/* [START]> ilsussidiario.net */	
	$pos1 = strpos($content, '<p><strong>??? ??? ??? ???</strong>');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
	$pos1 = strpos($content, '<p class="p1"><b>??? ??? ??? ???</b>');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
	$content = str_ireplace('?? RIPRODUZIONE RISERVATA', '', $content);
	$content = str_ireplace('class="intext_related"', 'class="hidden"', $content);
/* <[END] ilsussidiario.net */			

/* [START]> sabinopaciolla.com */	
	$pos1 = strpos($content, '<p><span><b>Sostieni il Blog di Sabino Paciolla');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
/* <[END] sabinopaciolla.com */		

/* [START]> scenarieconomici.it */	
	$pos1 = strpos($content, "<div class=\"span8\"");
	if ($pos1 !== false) {
/*		$content = substr($content, 0, $pos1 - 5);	*/
		$content = substr($content, 0, $pos1);
	}	
/* <[END] scenarieconomici.it */				

/* [START]> imolaoggi.it */	
	$content = str_ireplace('<h4>Condividi</h4>', '', $content);
	$content = str_ireplace('<table>', '<table class="hidden" >', $content);
	$content = str_ireplace(' Ascolta l&#8217;articolo ', '', $content);
	$content = str_ireplace(' Ascolta l???articolo ', '', $content);
/* <[END] imolaoggi.it */						

/* [START]> secoloditalia.it */	
	$content = str_ireplace('<p>LEGGI ANCHE</p>', '', $content);
	$content = str_ireplace('LEGGI ANCHE', '', $content);
/* <[END] secoloditalia.it */	

/* [START]> databaseitalia.it */	
	if (strpos($content, 'databaseitalia.it') !== false) { // Verifica della fonte 
		$pos1 = strpos($content, '<div class="wp-container-1 wp-block-group"');
		if ($pos1 !== false) {
			$pos2 = strpos($content, '<p>', $pos1 + 42);
			if ($pos2 === false) {
				$content = substr($content, 0, $pos1);
			} else {
				$content1 = substr($content, 0, $pos1);
				$content2 = substr($content, $pos2);
				$content = $content1 . $content2; 
			}	
		}	
		$content = str_ireplace('src="', 'ex-src="', $content);
		$content = str_ireplace('data-ezex-src="', 'src="', $content);
		$content = str_ireplace('<div id="inline-related-post" class="mag-box', '<div id="inline-related-post" class="hidden mag-box', $content);
	}	
/* <[END] databaseitalia.it */			

/* [START]> francescoamodeo.it */	
	$pos1 = strpos($content, "<p><strong>Francesco Amodeo</strong></p>");
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
	$content = str_ireplace('class="post_info"', 'class="hidden"', $content);
/* <[END] francescoamodeo.it */	

/* [START]> truereport.net */	
//	if (strpos($content, 'truereport') !== false) { // Verifica della fonte 
		$content = str_ireplace('class="td-post-featured-image"', 'class="hidden"', $content);
		$content = str_ireplace('class="wp-block-image', 'class="hidden', $content);
		$content = str_ireplace('class="wp-element-caption', 'class="hidden', $content);
		$content = str_ireplace('<p><span class="td-adspot-title">&#8211; Pubblicit?? &#8211;</span></p>', '', $content);
//	}
/* <[END] truereport.net */	

/* [START]> gospanews.net */	
	if (strpos($content, 'gospa') !== false) { // Verifica della fonte 
		$pos1 = strpos($content, "<hr>");
		if ($pos1 !== false) {
			$content = substr($content, 0, $pos1);
		}	
		$pos1 = strpos_($content, "<p>", -3);
		if ($pos1 !== false) {
			$content = substr($content, 0, $pos1);
		}	
		$content = str_ireplace('class="epvc-eye', 'class="hidden', $content);
		$content = str_ireplace('class="epvc-count', 'class="hidden', $content);
		$content = str_ireplace('class="epvc-label', 'class="hidden', $content);
		$content = str_ireplace('<blockquote class="wp-embedded-content" data-secret="', '<blockquote class="hidden" data-secret="', $content);
	}
/* <[END] gospanews.net */				

/* [START]> toba60.com */	
	if (strpos($content, 'oba60') !== false) { // Verifica della fonte 
		$pos1 = strpos($content, '<p class="has-text-align-center"><strong>Toba60</strong></p>');
		if ($pos1 !== false) {
			$pos2 = strpos($content, '</h3>', $pos1 + 60);
			if ($pos2 !== false) {
				$content1 = substr($content, 0, $pos1);
				$content2 = substr($content, $pos2 + 5);
				$content = $content1 . $content2; 
			}	
		}	
		$pos1 = strpos($content, "t.me/toba60com");
		if ($pos1 !== false) {
			$content = substr($content, 0, $pos1);
		}	
		$pos1 = strpos_($content, "<div", -3);
		if ($pos1 !== false) {
			$content = substr($content, 0, $pos1);
		}	
		$content = str_ireplace(' class="has-text-align-center"', '', $content);
	}	
/* <[END] toba60.com */	

/* [START]> nicolaporro.it */	
	$content = str_ireplace('<div id="text-size-dialog">', '<div class="hidden" id="text-size-dialog">', $content);
	$content = str_ireplace('Dimensioni testo', '', $content);
/* <[END] nicolaporro.it */	

/* [START]> ilsole24ore.com */	
	$content = str_ireplace('<div class="abox', '<div class="hidden abox', $content);
	$content = str_ireplace('class="aembed-audio', 'class="hidden aembed-audio', $content);
	$content = str_ireplace('<div class="abox', '<div class="hidden abox', $content);
	$content = str_ireplace('<div class="akeyp', '<div class="hidden akeyp', $content);
	$content = str_ireplace('<button class="aembed-audio', '<button class="hidden aembed-audio', $content);
	$content = str_ireplace('Loading&#8230;', '', $content);
	$content = str_ireplace('Loading???', '', $content);
	$content = str_ireplace('Ascolta la versione audio dell&#8217;articolo', '', $content);
	$content = str_ireplace('Ascolta la versione audio dell???articolo', '', $content);
/* <[END] ilsole24ore.com */	

/* [START]> repubblica.it */	
	$content = str_ireplace('<section class="inline-article', '<section class="hidden inline-article', $content);
/* [END]> repubblica.it */	

/* [START]> ilparagone.it */	
	$content = str_ireplace('(Continua a leggere dopo il video)', '', $content);
	$content = str_ireplace('(Continua a leggere dopo la foto)', '', $content);
	$content = str_ireplace('class="code-block', 'class="hidden code-block', $content);
/* [END]> ilparagone.it */	

	return '[pollyness] ' . trim($content);
	
}

function get_content_tags($content) {
	
	$result = null;
	$JWTWpAPI = new JWTWpAPI(WP_WEBSITE_TARGET_URL, null, null, false, WP_TARGET_API_ENDPOINT);
	$target_tags = $JWTWpAPI->tags();
	
	if (!empty($target_tags)) {
		foreach($target_tags as $target_tag){
			if ((strripos($content, " $target_tag ") !== false) || (strripos($content, " $target_tag,") !== false) || (strripos($content, " $target_tag.") !== false) || (strripos($content, " $target_tag;") !== false) || (strripos($content, " $target_tag?") !== false) || (strripos($content, " $target_tag!") !== false) || (strripos($content, " {$target_tag}???") !== false) || (strripos($content, " {$target_tag}:") !== false) || (strripos($content, "???$target_tag ") !== false) || (strripos($content, "???$target_tag,") !== false) || (strripos($content, "???$target_tag.") !== false) || (strripos($content, "???$target_tag;") !== false) || (strripos($content, "???$target_tag?") !== false) || (strripos($content, "???$target_tag!") !== false) || (strripos($content, "???{$target_tag}???") !== false) || (strripos($content, "???{$target_tag}:") !== false) || (strripos($content, "'$target_tag ") !== false) || (strripos($content, "'$target_tag,") !== false) || (strripos($content, "'$target_tag.") !== false) || (strripos($content, "'$target_tag;") !== false) || (strripos($content, "'$target_tag?") !== false) || (strripos($content, "'$target_tag!") !== false) || (strripos($content, "'{$target_tag}???") !== false) || (strripos($content, "'{$target_tag}:") !== false)) {
				if ($result === null)
					$result = [];
				$result[] = $target_tag;
			}
		}
	}
		
	if ($result === null)
		$result = [];
	
	return $result;
	
}

function ab_curl($url, array $options = array(), &$err = '') {
/*	
	$defaults = array(
		CURLOPT_HEADER => 0,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_URL => $url,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_FORBID_REUSE => 1,
		CURLOPT_TIMEOUT => 200,
		CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'
	);
*/
	$headers[] = 'Content-Type: application/json';
	$defaults = array(
		CURLOPT_HEADER => 0,
		CURLOPT_URL => $url,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_TIMEOUT => 200,
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_FORBID_REUSE => 1,
		CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'
	);

    $ch = curl_init();
	curl_setopt_array($ch, ($options + $defaults));
	
    if( ! $result = curl_exec($ch)) {
		$err .= curl_error($ch);
		ab_log("[CURL][ERROR][$url] $err");
		$result = null;
    }
	
    curl_close($ch);
    return $result;
	
} 

function post_credits_html($link, &$domain) {
	
	$domain = parse_url($link, 1);
	$domain = str_ireplace('www.', '', $domain);
	return "<p><br><br><span style=\"font-style: italic;\">Fonte: </span><a rel=\"nofollow\" style=\"font-style: italic;\" href=\"$link\" target=\"_blank\">$domain</a></p>";

}

function strpos_($haystack, $needle, $offset = 0, $resultIfFalse = false) {
	
    $haystack=((string)$haystack);    // (string) to avoid errors with int, float...
    $needle=((string)$needle);

    if ($offset>=0) {
        $offset=strpos($haystack, $needle, $offset);
        return (($offset===false)? $resultIfFalse : $offset);
    } else {
        $haystack=strrev($haystack);
        $needle=strrev($needle);
        $offset=strpos($haystack,$needle,-$offset-1);
        return (($offset===false)? $resultIfFalse : strlen($haystack)-$offset-strlen($needle));
    }
	
}

function filter_excerpt($excerpt) {
	
/* [START]> nicolaporro.it */	
	$excerpt = str_replace('Dimensioni testo', '', $excerpt);
/* <[END] nicolaporro.it */	

/* [START]> ilsole24ore.com */	
	$excerpt = str_replace('Ascolta la versione audio dell&#039;articolo 2&#039; di lettura', '', $excerpt);
/* <[END] ilsole24ore.com */	

	return trim($excerpt);
	
}

function filter_title($title) {
	
	$title = str_ireplace(' - Come Don Chisciotte', '', $title);
	$title = str_ireplace(' - Mondo', '', $title);
	$title = str_ireplace(' - Imola Oggi', '', $title);
	$title = str_ireplace(' ??? Imola Oggi', '', $title);
	
	return trim($title);
	
}

function ab_log($log) {
	
	$now = date('[d/m/Y H:i:s] ');
	$log = $now . $log;
	$log .= "\n\n";
	
	file_put_contents('ab_log.txt', $log, FILE_APPEND);
	
}

function get_env_var($var_name, $raise_err = true) {

	$result = getenv($var_name);

	if ($result === false) {
		$result = ini_get($var_name);		
		if ($result === false) {
			if (file_exists('env-vars.php')) {
				require_once('env-vars.php');
				if (function_exists('get_var'))
					$result = get_var($var_name);		
			}
		}
	}

	if ($raise_err && (!$result))
		throw new Exception("Enviroment variable \"$var_name\" not found.");

	return $result;

}