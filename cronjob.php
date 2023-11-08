<?php 

error_reporting(E_ALL);
ini_set('display_errors', 1);

require('ytd/vendor/autoload.php');
require_once('constants.php');	
require_once('wpapublisher/jwt_wp_api.class.php');	

use YouTube\YouTubeDownloader;
use YouTube\Exception\YouTubeException;
use YouTube\Utils\Utils;

try {

	$api_data = get_api_call_data();
	if (is_array($api_data) && isset($api_data['smartdownloader'])) {
		proc_yt_download_url($api_data);
		exit;
	}

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

function filter_rss_title($title) {

	$title = trim(str_replace('...', '', trim($title)));
	$pos = strpos($title, " ");

	if ($pos !== false) {
		$title = substr($title, 0, $pos);  // Estrai la parte della stringa fino al carattere
	}
	
	return trim($title);
}

function extract_yt_text_from_rss_item_desc($html) {

	$html_content = new \Html2Text\Html2Text($html, array('do_links' => 'none', 'width' => 0));
	$text = $html_content->getText();
	$text = str_ireplace('[Immagine]', '', $text);
	return trim($text);
		
}

function process_rss_item($item) {

	if (is_array($item) && (!empty($item['title']))) {
		$title = filter_rss_title($item['title']);
		if (filter_var($title, FILTER_VALIDATE_URL)) {
			if 	(
					(strpos(strtolower(substr($title, 0, 17)), 'https://youtu.be/') === false)
					&&
					(strpos(strtolower(substr($title, 0, 21)), 'https://www.youtu.be/') === false)
					&&
					(strpos(strtolower(substr($title, 0, 24)), 'https://www.youtube.com/') === false)
					&&
					(strpos(strtolower(substr($title, 0, 20)), 'https://youtube.com/') === false)
				) {
				$content_link = extract_content_url($item['description']);
				return process_link_post($content_link);
			} else {
				$image_url = extract_yt_img_from_rss_item_desc($item['description']);
				if ($image_url) {
					return process_youtube_post($title, $image_url, extract_yt_text_from_rss_item_desc($item['description']));
				}
			}
		} else {
		
		
		
		

		}
	}
	
	return false;
	
}

function extract_yt_img_from_rss_item_desc($content) {
	
	$content = trim($content);
	
	if ($content) {
		$pos1 = strpos($content, '<img src="');
		if ($pos1 !== false) {
			$pos1 += 10;
			$pos2 = strpos($content, '"', $pos1);
			if ($pos2 !== false) {
				$content = trim(substr($content, $pos1, $pos2 - $pos1));
				if (filter_var($content, FILTER_VALIDATE_URL))
					return $content;
			}
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

function process_youtube_post($link_to_video, $image_url, $desc) {

	if (!function_exists('get_curr_url')) {
		function get_curr_url() {
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
			$domainName = $_SERVER['HTTP_HOST'];
			$scriptPath = $_SERVER['SCRIPT_NAME'];
			return $protocol . $domainName . $scriptPath;
		
		}		
	}


//ab_log("<p>[PROCESS_YOUTUBE_POST] 0 link_to_video = $link_to_video; desc = $desc</p>");	// debug
	
	if (filter_var($link_to_video, FILTER_VALIDATE_URL)) {
		$err = '';
		$video_infos = null;
		$quality = YTD_PREFERRED_QUALITY_LOWER;
		$media_type = YTD_MEDIA_TYPE_AUDIO_ONLY;
		$video_files = get_yt_video_download_urls($link_to_video, $err, $video_infos, $quality, $media_type, YTD_MEDIA_MAX_SIZE);
		if (empty($video_files)) {
//			ab_log("[PROCESS_YOUTUBE_POST] Failed downloading YT video $link_to_video: $err");	// debug
			return true;
		}

//ab_log("[PROCESS_YOUTUBE_POST] 100 files = " . print_r($video_files, true) . "; obj = " . print_r($video_objects, true) . "; image_url = $image_url; desc = $desc</p>");	// debug
//ab_log("<p>[PROCESS_YOUTUBE_POST] 100 files = " . print_r($video_files, true) . "; image_url = $image_url; desc = $desc</p>");	// debug

		if ($video_infos) {
			$yt_image_url = get_yt_video_thumbnail($video_infos, 500, null);
			if ($yt_image_url)
				$image_url = $yt_image_url;

//ab_log("[PROCESS_YOUTUBE_POST] 100 video_infos = " . print_r($video_infos, true));	// debug

			$video_url = $video_files[0];
			$desc = $video_infos->getShortDescription();
			$desc = str_replace("\n", NEW_LINE_REPLACEMENT, $desc);
			$channel = $video_infos->getChannelName();
			$data_ext = json_encode(	[
											'smartdownloader' => 1, 
											'image_url' => $image_url, 
											'title' => $video_infos->getTitle(), 
											'desc' => $desc, 
											'tags' => $video_infos->getKeywords(),
											'video_url' => $link_to_video,
											'channel' => $channel 
										]
									, JSON_HEX_QUOT | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE
									);
			$postdata = ['token' => get_token(get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN')), 'url' => $video_url, 'callback' => get_curr_url(), 'callbackData' => $data_ext, 'callbackType' => 'GET'];
			$err = '';
			wpap_curl_post(SMART_DOWNLOADER_ENDPOINT, $postdata, [CURLOPT_RETURNTRANSFER => 0, CURLOPT_TIMEOUT => 3], $err);	
		}
	}
	
	return true;
	
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
										$str_comp_len = ((int)(strlen($excerpt) / 2));
										$text_content = $post->get_text_content('<!-- END SUMMARY -->', 'Fonte: ');
										if (strtoupper(substr($excerpt, 0, $str_comp_len)) === strtoupper(substr($text_content, 0, $str_comp_len))) {
											$post->excerpt = "DESCRIZIONE UGUALE";
										}
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
										if (!is_array($the_tags))
											$the_tags = [];
										$err = '';
										$JWTWpAPI = new JWTWpAPI(WP_WEBSITE_TARGET_URL, $wp_website_target_user, $wp_website_target_password, false);
										$res = $JWTWpAPI->add_post_tags($post, $err, '[pollyness]', 'Fonte: ', get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN'));
										
ab_log("[PROCESS_LINK_POST][$title] 100 the_tags = " . print_r($the_tags, true));	// debug
ab_log("[PROCESS_LINK_POST] 200 res = " . print_r($res, true));	// debug

										
										$the_tags = array_values(array_unique(array_merge($the_tags, $res)));
										
ab_log("[PROCESS_LINK_POST] 300 the_tags = " . print_r($the_tags, true));	// debug

										$post->tags = $the_tags;
										$post->featured_media_url = $featured_image;
										$extra_data = [];
										$extra_data['_yoast_wpseo_metadesc'] = $excerpt;
										$extra_data['meta'] = [];
										$extra_data['meta']['_yoast_wpseo_metadesc'] = $excerpt;
										$res = $JWTWpAPI->add_post_categories($post, $err, '[pollyness]', 'Fonte: ', get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN'));
										if (empty($res)) {
ab_log("[PROCESS_LINK_POST][$title] 350 err = $err");	// debug
											echo "<p>[CATS] " . date("Y-m-d H:i:s") . " [{$post->title}] ERRORE: $err</p>";
										} else {
											$post->categories = $res;
											$extra_data['meta']['wp_firstcat_cetegory_id'] = (int)$post->categories[0];
											$tmp_cat = $JWTWpAPI->categories();
											$tmp_res = [];
											foreach($res as $tmp_r) {
												$tmp_r = (int)$tmp_r;
												if (empty($tmp_cat[$tmp_r])) {
													echo "<p>[ERRORE][205] indice $tmp_r inesistente.</p>";
												} else {
													$new_item = ['indice' => $tmp_r, 'nome' => $tmp_cat[$tmp_r]->name];
													$tmp_res[] = $new_item;
												}
											}
											echo "<p>[CATS] " . date("Y-m-d H:i:s") . " [{$post->title}] " . print_r($tmp_res, true) . "</p>";
										}
										
										/* if ((count($post->categories) > 1) && ($post->status === 'publish')) {
											$post->status = 'draft';
											$res = $JWTWpAPI->create_post($post, $err, true, $extra_data);
											if ($res) {
												$post->status = 'publish';
												$res = $JWTWpAPI->modify_post($post, $err, true, $extra_data);
											}
										} else {
											$res = $JWTWpAPI->create_post($post, $err, true, $extra_data);
										}*/
										$res = $JWTWpAPI->create_post($post, $err, true, $extra_data);

										if ($res) {
											return true;
										} else {
											echo "\n" . '[ERROR] ' . $err . "\n";
											ab_log('[PROCESS_LINK_POST][ERROR] ' . $err);
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

function filter_content($content, $add_audio = true) {

	if (!function_exists('comedonchisciotte')) {

		function comedonchisciotte($html) {

			global $wp_website_target_user, $wp_website_target_password;

		/* METTO TUTTE LE URL DELLE IMMAGINI IN $array1 */			
			$array1 = explode('<img', $html);

			if (is_array($array1)) {
				$array2 = [];
				foreach ($array1 as $item1) {
					if (strpos($item1, 'wp-image') !== false)
						$array2[] = $item1;
				}
				$array1 = [];
				foreach ($array2 as $item2) {
					$pos1 = strpos($item2, 'src="');
					if ($pos1) {
						$pos1 += 5;
						$pos2 = strpos($item2, '"', $pos1);
						$url = substr($item2, $pos1, $pos2 - $pos1);
						if (filter_var($url, FILTER_VALIDATE_URL))
							$array1[] = $url;						
					}
				}
			}

		/* AGGIUNGO LE IMMAGINI A WP */

			$wp = new JWTWpAPI(WP_WEBSITE_TARGET_URL, $wp_website_target_user, $wp_website_target_password, false);			
			$images_list = [];

			foreach ($array1 as $img_url) {
				$err = '';
				$wp_url = '';
				$img_id = $wp->create_media($img_url, '', $err, $wp_url);
				if ($img_id)
					$images_list[] = ['source' => $img_url, 'dest' => $wp_url];
			}

		/* SOSTITUISCO LE URL DELLE IMMAGINI */

			foreach ($images_list as $img) {
				$html = str_ireplace($img['source'], $img['dest'], $html);
			}

			return $html;

		}

	}

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
		$content = str_ireplace(' src="', ' title="', $content);
		$content = str_ireplace('data-src="', 'src="', $content);
		$content = str_ireplace('srcset="', 'src="', $content);
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
		$content = comedonchisciotte($content);
	}	
/* <[END] comedonchisciotte.org */	

/* [START]> ilsussidiario.net */	
	$pos1 = strpos($content, '<p><strong>— — — —</strong>');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
	$pos1 = strpos($content, '<p class="p1"><b>— — — —</b>');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
	$content = str_ireplace('© RIPRODUZIONE RISERVATA', '', $content);
	$content = str_ireplace('class="intext_related"', 'class="hidden"', $content);
/* <[END] ilsussidiario.net */			

/* [START]> sabinopaciolla.com */	
	$pos1 = strpos($content, '<p><span><b>Sostieni il Blog di Sabino Paciolla');
	if ($pos1 !== false) {
		$content = substr($content, 0, $pos1);
	}	
	$pos1 = strpos($content, '<div><label>Fai una donazione.');
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
	$content = str_ireplace(' Ascolta l’articolo ', '', $content);
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
		$content = str_ireplace('<p><span class="td-adspot-title">&#8211; Pubblicità &#8211;</span></p>', '', $content);
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
	$content = str_ireplace('Loading…', '', $content);
	$content = str_ireplace('Ascolta la versione audio dell&#8217;articolo', '', $content);
	$content = str_ireplace('Ascolta la versione audio dell’articolo', '', $content);
/* <[END] ilsole24ore.com */	

/* [START]> repubblica.it */	
	$content = str_ireplace('<section class="inline-article', '<section class="hidden inline-article', $content);
/* [END]> repubblica.it */	

/* [START]> ilparagone.it */	
	$content = str_ireplace('(Continua a leggere dopo il video)', '', $content);
	$content = str_ireplace('(Continua a leggere dopo la foto)', '', $content);
	$content = str_ireplace('class="code-block', 'class="hidden code-block', $content);
/* [END]> ilparagone.it */	

/* [START]> maurizioblondet.it */	
	$content = str_ireplace('class="external external_icon"', 'class="hidden"', $content);
/* [END]> maurizioblondet.it */	

/* [START]> Canale YT Border Nights */	
	$content = str_ireplace('Sottotitoli e revisione a cura di QTSS.', '', $content);
	$content = str_ireplace('Sottotitoli e revisione a cura di QTSS', '', $content);
	$content = str_ireplace('Sottotitoli creati dalla comunità Amara.org.', '', $content);
	$content = str_ireplace('Sottotitoli creati dalla comunità Amara.org', '', $content);
	$content = str_ireplace('- 1 Minute News.', '', $content);
	$content = str_ireplace('- 1 Minute News', '', $content);
	$content = str_ireplace('– 1 Minute News.', '', $content);
	$content = str_ireplace('– 1 Minute News', '', $content);
/* <[END] Canale YT Border Nights */

/* [START]> Libro di Nicolai Lilin */	
	$content = str_ireplace('La guerra e l&#8217;odio', '<a href="https://amzn.to/45oSvbo" target="_blank" rel="nofollow sponsored noreferrer noopener"><b>La guerra e l&#8217;odio</b></a>', $content);
	$content = str_ireplace("La guerra e l'odio", '<a href="https://amzn.to/45oSvbo" target="_blank" rel="nofollow sponsored noreferrer noopener"><b>La guerra e l\'odio</b></a>', $content);
	$content = str_ireplace("La guerra e l’odio", '<a href="https://amzn.to/45oSvbo" target="_blank" rel="nofollow sponsored noreferrer noopener"><b>La guerra e l\'odio</b></a>', $content);
	if ((strpos($content, "La guerra e l'odio") !== false) || (strpos($content, "La guerra e l’odio") !== false) || (strpos($content, 'La guerra e l&#8217;odio') !== false)) {
		$book = '<div style="width:100%;"><br><br><center><iframe sandbox="allow-popups allow-scripts allow-modals allow-forms allow-same-origin" style="width:120px;height:240px;" marginwidth="0" marginheight="0" scrolling="no" frameborder="0" src="//rcm-eu.amazon-adsystem.com/e/cm?lt1=_blank&bc1=000000&IS2=1&bg1=FFFFFF&fc1=000000&lc1=0000FF&t=bacheca-ss-21&language=it_IT&o=29&p=8&l=as4&m=amazon&f=ifr&ref=as_ss_li_til&asins=8856687690&linkId=c5061c5014504371ec3357ea5cdfaa95"></iframe></center></div>';
		$content .= $book;		
	}
/* [END]> Libro di Nicolai Lilin */	

	$content = trim($content);
	$summary_html = get_summary_html($content);

	$pollyness = '';
	if ($add_audio)
		$pollyness = '[pollyness] ';

	return $pollyness . '<!-- START SUMMARY -->' . $summary_html . '<!-- END SUMMARY -->' . $content;
	
}

function get_content_tags($content) {
	
	$result = null;
	$JWTWpAPI = new JWTWpAPI(WP_WEBSITE_TARGET_URL, null, null, false, WP_TARGET_API_ENDPOINT);
	$target_tags = $JWTWpAPI->tags();
	
	if (!empty($target_tags)) {
		foreach($target_tags as $target_tag) {
			if ((strripos($content, " $target_tag ") !== false) || (strripos($content, " $target_tag,") !== false) || (strripos($content, " $target_tag.") !== false) || (strripos($content, " $target_tag;") !== false) || (strripos($content, " $target_tag?") !== false) || (strripos($content, " $target_tag!") !== false) || (strripos($content, " {$target_tag}…") !== false) || (strripos($content, " {$target_tag}:") !== false) || (strripos($content, "’$target_tag ") !== false) || (strripos($content, "’$target_tag,") !== false) || (strripos($content, "’$target_tag.") !== false) || (strripos($content, "’$target_tag;") !== false) || (strripos($content, "’$target_tag?") !== false) || (strripos($content, "’$target_tag!") !== false) || (strripos($content, "’{$target_tag}…") !== false) || (strripos($content, "’{$target_tag}:") !== false) || (strripos($content, "'$target_tag ") !== false) || (strripos($content, "'$target_tag,") !== false) || (strripos($content, "'$target_tag.") !== false) || (strripos($content, "'$target_tag;") !== false) || (strripos($content, "'$target_tag?") !== false) || (strripos($content, "'$target_tag!") !== false) || (strripos($content, "'{$target_tag}…") !== false) || (strripos($content, "'{$target_tag}:") !== false)) {
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

	$excerpt = str_ireplace('\u0022', '"', $excerpt);
	$excerpt = str_ireplace('u0022', '"', $excerpt);
	
/* [START]> nicolaporro.it */	
	$excerpt = str_replace('Dimensioni testo', '', $excerpt);
/* <[END] nicolaporro.it */	

/* [START]> ilsole24ore.com */	
	$excerpt = str_replace('Ascolta la versione audio dell&#039;articolo 2&#039; di lettura', '', $excerpt);
/* <[END] ilsole24ore.com */	

/* [START]> Canale YT Border Nights */	
	$res = explode('Se ti piace Border Nights', $excerpt);
	if (is_array($res) && (count($res) > 1)) {
		$excerpt = trim($res[0]);
	}
	$excerpt = str_ireplace('– 1 Minute News.', '', $excerpt);
	$excerpt = str_ireplace('– 1 Minute News', '', $excerpt);
	$excerpt = str_ireplace('- 1 Minute News.', '', $excerpt);
	$excerpt = str_ireplace('- 1 Minute News', '', $excerpt);
/* [END]> Canale YT Border Nights */	

/* [START]> Canale YT Nicolai Lilin */		
	$res = explode('Per approfondire questa e altre notizie', $excerpt);
	if (is_array($res) && (count($res) > 1)) {
		$excerpt = trim($res[0]);
	}	
/* [END]> Canale YT Nicolai Lilin */		

/* [START]> Canale YT l'Antidiplomatico */		
	$res = explode('Abbonati al canale', $excerpt);
	if (is_array($res) && (count($res) > 1)) {
		$excerpt = trim($res[0]);
	}
/* [END]> Canale YT l'Antidiplomatico */		

	$excerpt = str_replace('#', '', $excerpt);

	return trim($excerpt);

}

function filter_title($title) {

	if (!function_exists('convertToTitleCase')) {
		function convertToTitleCase($input) {
			if (strtoupper($input) === $input) {
				return ucfirst(strtolower($input));
			} else {
				return $input;
			}
		}
	}

	$title = str_ireplace(' - Come Don Chisciotte', '', $title);
	$title = str_ireplace(' - Mondo', '', $title);
	$title = str_ireplace(' - Imola Oggi', '', $title);
	$title = str_ireplace(' • Imola Oggi', '', $title);
	$title = str_ireplace(' - 1 Minute News', '', $title);
	$title = str_ireplace('\u0022', '"', $title);
	$title = str_ireplace('u0022', '"', $title);
	
	return convertToTitleCase(trim($title));
	
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

function get_summary_html($text_to_summarize, $min_words = 300) {

	$html = '';

	$html_content = new \Html2Text\Html2Text($text_to_summarize, array('do_links' => 'none', 'width' => 0));
	$text_to_summarize = $html_content->getText();
	$text_to_summarize = str_ireplace('[Immagine]', '', $text_to_summarize);
	$text_to_summarize = trim($text_to_summarize);

	if (str_word_count($text_to_summarize) >= $min_words) {
		$content = "Riassumi il testo che segue in modo che sia comprensibile a un bambino di 10 anni. Rispondi solo con il riassunto. Ecco il testo: \r\n" . $text_to_summarize;
		$query = [(object)['role' => 'user', 'content' => $content]];
		$postdata = ['query' => json_encode($query), 'token' => get_token(get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN'))];
		$err = '';
		$res = wpap_curl_post(AI_CHAT_API_URL, $postdata, array(), $err);	
		$res = trim($res);
		if ($res) {
			$result = json_decode($res, true);
			if (!empty($result['message']))
				$result['error'] = $result['message'];
			if ((!empty($result)) && empty($result["error"])) {
				$html = $result['data'];
			}
		}
	}

	if ($html) {
		$html = "<div class=\"wp-block-uagb-info-box uagb-block-80353057 uagb-infobox__content-wrap  uagb-infobox-icon-above-title uagb-infobox-image-valign-top\"><div class=\"uagb-ifb-content\"><div class=\"uagb-ifb-icon-wrap\"><svg xmlns=\"https://www.w3.org/2000/svg\" viewBox=\"0 0 512 512\"><path d=\"M0 256C0 114.6 114.6 0 256 0C397.4 0 512 114.6 512 256C512 397.4 397.4 512 256 512C114.6 512 0 397.4 0 256zM371.8 211.8C382.7 200.9 382.7 183.1 371.8 172.2C360.9 161.3 343.1 161.3 332.2 172.2L224 280.4L179.8 236.2C168.9 225.3 151.1 225.3 140.2 236.2C129.3 247.1 129.3 264.9 140.2 275.8L204.2 339.8C215.1 350.7 232.9 350.7 243.8 339.8L371.8 211.8z\"></path></svg></div><div class=\"uagb-ifb-title-wrap\"><h3 class=\"uagb-ifb-title\">Spiegato semplice</h3></div><p class=\"uagb-ifb-desc\">$html</p></div></div><span class=\"ab-hidden-text-tts\">Fine spiegato semplice.</span>";
	}

	return $html;
		
}

/*
function download_yt_video(string &$url, string &$err = '', array &$result = [], int &$quality = YTD_PREFERRED_QUALITY_LOWER, int &$media_type = YTD_MEDIA_TYPE_AUDIO_ONLY): ?array {

	if (!function_exists('sub_str_after_last_char')) {
		function sub_str_after_last_char(string $str, string $char): string {
			$substring = $str;
			$lastSlashPos = strrpos($str, $char);
			if ($lastSlashPos !== false) {
				$str = substr($str, $lastSlashPos + 1);
				if ($str !== false) {
					$substring = $str;
				}
			}
			return $substring;
		}
	}

	if (!function_exists('mime_type_to_file_ext')) {
		function mime_type_to_file_ext(string $mime_type): string {
			$mime_type = trim(strtolower($mime_type));
			if (strpos($mime_type, 'video/mp4') === 0)
				return '.mp4';
			if (strpos($mime_type, 'audio/webm') === 0)
				return '.ogg';
			if (strpos($mime_type, 'audio/mp4') === 0)
				return '.m4a';
			return '.mpeg';
		}
	}

	if (!function_exists('get_uuid')) {
		function get_uuid() {
			if (function_exists('com_create_guid')) {
				$result = com_create_guid();
			} else {
				mt_srand((double)microtime()*10000);//optional for php 4.2.0 and up.
				$charid = strtoupper(md5(uniqid(rand(), true)));
				$hyphen = chr(45);// "-"
				$uuid = chr(123)// "{"
						.substr($charid, 0, 8).$hyphen
						.substr($charid, 8, 4).$hyphen
						.substr($charid,12, 4).$hyphen
						.substr($charid,16, 4).$hyphen
						.substr($charid,20,12)
						.chr(125);// "}"
				$result = $uuid;
			}
			$result = str_replace('{', '', $result);
			$result = str_replace('}', '', $result);
			$result = str_replace('-', '', $result);
			return $result;
		}
	}		

	if (!function_exists('get_file_curl')) {
		function get_file_curl($url, array $options = array(), &$err = '') {
			$defaults = array(
				CURLOPT_HEADER => 0,
				CURLOPT_URL => $url,
				CURLOPT_FRESH_CONNECT => 1,
				CURLOPT_RETURNTRANSFER => 1,
				CURLOPT_FORBID_REUSE => 1,
				CURLOPT_SSL_VERIFYPEER => false,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_USERAGENT => 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; .NET CLR 1.1.4322)'
			);
			$ch = curl_init();
			curl_setopt_array($ch, ($options + $defaults));
			if( ! $result = curl_exec($ch)) {
				$err .= curl_error($ch);
				$result = null;
			}
			curl_close($ch);
			return $result;
		} 
	} 
			
	if (!function_exists('downloadFile')) {
		function downloadFile($url, $path) {
			try {

				$newFileName = $path;
				$file = fopen($url, "rb");
				if($file) {
					try {
						$newFile = fopen ($newFileName, "wb");
						if ($newFile) {
							try {
								while(!feof($file)) {
									fwrite($newFile, fread($file, 1024), 1024);
								}
								return true;
							} finally {
								fclose($newFile);
							}
						}
					} finally {
						fclose($file);
					}
				}
				return false;
			} catch (Exception $e) {
				return false;
			}
		}	
	}	
	
	if (filter_var($url, FILTER_VALIDATE_URL)) {
		$final_url = trim(sub_str_after_last_char($url, '/'));
		if (!$final_url) {
			$err = 'Bad video URL.';
			return false;
		}
		if (substr(strtolower($final_url), 0, 8) !== 'watch?v=') {
			$url = 'https://www.youtube.com/watch?v=' . $final_url;
		}
		$youtube = new YouTubeDownloader();
		try {
			$downloadOptions = $youtube->getDownloadLinks($url);
			$formats = $downloadOptions->getAllFormats();

			if ($formats) {
				$result = [];
				switch ($media_type) {
					case YTD_MEDIA_TYPE_COMBINED:
						$result = Utils::arrayFilterReset($formats, function ($format) {
							return strpos($format->mimeType, 'video') === 0 && !empty($format->audioQuality);
						});
						if (empty($result)) {
							$quality = YTD_PREFERRED_QUALITY_BEST;
							$media_type = YTD_MEDIA_TYPE_ALL;
							return download_yt_video($url, $err, $result, $quality, $media_type);
						}
						break;
					case YTD_MEDIA_TYPE_AUDIO_ONLY:
						$result = Utils::arrayFilterReset($formats, function ($format) {
							return strpos($format->mimeType, 'audio') === 0;
						});
						if (empty($result)) {
							$media_type = YTD_MEDIA_TYPE_COMBINED;
							return download_yt_video($url, $err, $result, $quality, $media_type);
						}
						break;
					case YTD_MEDIA_TYPE_VIDEO_ONLY:
						$result = Utils::arrayFilterReset($formats, function ($format) {
							return strpos($format->mimeType, 'video') === 0 && empty($format->audioQuality);
						});
						if (empty($result)) {
							$media_type = YTD_MEDIA_TYPE_COMBINED;
							return download_yt_video($url, $err, $result, $quality, $media_type);
						}
						break;
					default:
						$result = array_values($formats);
					break;
				}
				if (!empty($result)) {
					if ($quality !== YTD_PREFERRED_QUALITY_ALL) {
						usort($result, function ($a, $b) {
							return $a->contentLength - $b->contentLength;
						});
						switch ($quality) {
							case YTD_PREFERRED_QUALITY_BEST:
								$result = [$result[count($result) - 1]];
								break;
							case YTD_PREFERRED_QUALITY_LOWER:
								$result = [$result[0]];
								break;
						}
					}
					$output = [];
					foreach($result as $item) {
						$output[] = $item->url;
					}
					if (empty($output))
						return null;
					$file_paths = [];
					foreach($output as $key => $val) {
						$file_ext = mime_type_to_file_ext($result[$key]->mimeType);
						$file_name = get_uuid() . $file_ext;
						$directory = dirname(__FILE__) . '/temp';
						if (!is_dir($directory)) {
							mkdir($directory, 0777, true);
						}
						$file_name = $directory . '/' . $file_name;
						if (downloadFile($val, $file_name)) {
							$file_paths[] = $file_name;
						} else {
							if ($err)
								$err .= " \n";
							$err .= "Failed downloading file $val.";
						}
					}
					if (!empty($file_paths))
						return $file_paths;
				}
			} else {
				$err = 'No links found.';
			}
		} catch (YouTubeException $e) {
			$err = $e->getMessage();
		}
	}

	return null;

} 
*/

function proc_yt_download_url(array $data) {

	try {
		if ((!empty($data['download_url'])) && (!empty($data['image_url'])) && (!empty($data['title'])) && (!empty($data['video_url']))) {
			$download_url = trim($data['download_url']);
			$image_url = trim($data['image_url']);
			$video_url = trim($data['video_url']);
			$title = trim($data['title']);
			$file_size = 0;
			if (isset($data['file_size']))
				$file_size = (int)$data['file_size'];
			$desc = null;
			if (!empty($data['desc']))
				$desc = trim(filter_excerpt($data['desc']));
			$tags = null;
			if ((!empty($data['tags'])) && is_array($data['tags'])) {
				$tags = [];
				foreach ($data['tags'] as $tag_item) {
					$tags[] = str_replace('#', '', $tag_item);
				}
			}
			if (YTD_MEDIA_MAX_SIZE && ($file_size > YTD_MEDIA_MAX_SIZE))
				throw new Exception("$file_size exceeded YT media max size.");
			if (!filter_var($download_url, FILTER_VALIDATE_URL))
				throw new Exception("Wrong download URL \"$download_url\".");
			if (!filter_var($image_url, FILTER_VALIDATE_URL))
				throw new Exception("Wrong image URL \"$image_url\".");
			if (!filter_var($video_url, FILTER_VALIDATE_URL))
				throw new Exception("Wrong YT video URL \"$video_url\".");

//ab_log("[PROC_YT_DOWNLOAD_URL] 200 download_url = $download_url; image_url = $image_url; title = $title; desc = $desc; tags = " . print_r($tags, true) . "; video_url = $video_url; msg = {$data['msg']}");	// debug

			$postdata = ['query' => $download_url, 'token' => get_token(get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN'))];
			$err = '';
			$res = wpap_curl_post(AI_STT_API_URL, $postdata, array(), $err);	
			$res = trim($res);
			$transcript = '';
			if ($res) {
				$result = json_decode($res, true);
				if (!empty($result['message']))
					$result['error'] = $result['message'];
				if ((!empty($result)) && empty($result["error"])) {
					$transcript = trim($result['data']);
				}
			}	
			if (empty($result['error'])) {
				if ($transcript) {
					if (false && (!empty($result['tmpFileName']))) {
						$tmp_file = trim($result['tmpFileName']);					
						$postdata = ['tmpFileName' => $tmp_file];
						wpap_curl_post(AI_STT_API_URL, $postdata, [CURLOPT_RETURNTRANSFER => 0, CURLOPT_TIMEOUT => 3], $err);
					}
					$video_html = get_yt_video_embed_html($video_url);
					if ($video_html) {
						$desc = trim($desc);
						$desc_html = '';
						if ($desc) {
							$desc_html = str_replace(NEW_LINE_REPLACEMENT, '<br>', $desc);
							$desc_html = "<p>$desc_html</p>";
						}
						$desc = explode(NEW_LINE_REPLACEMENT, $desc)[0];
						$html = "$video_html{$desc_html}<h3>Trascrizione del video</h3>\n\n<p>$transcript</p>\n\n";
						$html = filter_content($html, false);
						if ($html) {
							$title = "[VIDEO] " . filter_title($title);
							$post = new WpPost($title, $html, $desc);
							$str_comp_len = ((int)(strlen($desc) / 2));
							$text_content = $post->get_text_content('</div></figure>');
							if (strtoupper(substr($desc, 0, $str_comp_len)) === strtoupper(substr($text_content, 0, $str_comp_len))) {
								$post->excerpt = "DESCRIZIONE UGUALE";
							}
							if (WP_PUBLISH_AS_DRAFT) {
								$post->status = 'draft';	
							} else {
								$post->status = 'publish';	
							}
							$the_tags = get_content_tags("$html $desc");
							if (!is_array($the_tags))
								$the_tags = [];
							if (!empty($data['channel']))
								$the_tags[] = $data['channel'];
							$the_tags[] = 'Video';
							$the_tags[] = 'YouTube';
							if (is_array($tags))
								$the_tags = array_merge($the_tags, $tags);
							$err = '';
							$wp_website_target_user = get_env_var('SC_WP_ADMIN_USER');
							$wp_website_target_password = get_env_var('SC_WP_ADMIN_PWD');
							$JWTWpAPI = new JWTWpAPI(WP_WEBSITE_TARGET_URL, $wp_website_target_user, $wp_website_target_password, false);
							$res = $JWTWpAPI->add_post_tags($post, $err, '', '', get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN'));
							if (isset($res['data']))
								unset($res['data']);
							if (is_array($res))
								$the_tags = array_values(array_unique(array_merge($the_tags, $res)));

// ab_log("[PROC_YT_DOWNLOAD_URL] [$title] 100 the_tags = " . print_r($the_tags, true));	// debug

							$post->tags = $the_tags;
							$post->featured_media_url = $image_url;
							$extra_data = [];
							$extra_data['_yoast_wpseo_metadesc'] = $desc;
							$extra_data['meta'] = [];
							$extra_data['meta']['_yoast_wpseo_metadesc'] = $desc;
							$res = $JWTWpAPI->add_post_categories($post, $err, '', '', get_env_var('AI_API_USER_KEY'), get_env_var('AI_API_TOKEN'));

// ab_log("[PROC_YT_DOWNLOAD_URL] [$title] 200 res = " . print_r($res, true));	// debug

							$post->categories = $res;
							$extra_data['meta']['wp_firstcat_cetegory_id'] = (int)$post->categories[0];
							$res = $JWTWpAPI->create_post($post, $err, true, $extra_data);
							if (!$res) {
								ab_log('[PROC_YT_DOWNLOAD_URL][ERROR] ' . $err);
							}
						}
					} else {
						throw new Exception("Error generating HTML for video $video_url.");
					}
				}
			} else {
				throw new Exception($result['error']);
			}
		}	
    } catch (Exception $e) {
		ab_log("[PROC_YT_DOWNLOAD_URL] Errore: " . $e->getMessage());
	}

}

function get_yt_video_download_urls(string &$url, string &$err = '', &$video_info = null, int &$quality = YTD_PREFERRED_QUALITY_LOWER, int &$media_type = YTD_MEDIA_TYPE_AUDIO_ONLY, ?int $max_media_size = null): ?array {

	$result = [];

	if ($max_media_size) {
		if ($max_media_size < 0)
			$max_media_size = null;
	}

	if (!function_exists('sub_str_after_last_char')) {
		function sub_str_after_last_char(string $str, string $char): string {
			$substring = $str;
			$lastSlashPos = strrpos($str, $char);
			if ($lastSlashPos !== false) {
				$str = substr($str, $lastSlashPos + 1);
				if ($str !== false) {
					$substring = $str;
				}
			}
			return $substring;
		}
	}
	
	if (filter_var($url, FILTER_VALIDATE_URL)) {
		$final_url = trim(sub_str_after_last_char($url, '/'));
		if (!$final_url) {
			$err = 'Bad video URL.';
			return false;
		}
		if (substr(strtolower($final_url), 0, 8) !== 'watch?v=') {
			$url = 'https://www.youtube.com/watch?v=' . $final_url;
		}
		$youtube = new YouTubeDownloader();
		try {
			$downloadOptions = $youtube->getDownloadLinks($url);
			$video_info = $downloadOptions->getInfo();
			$formats = $downloadOptions->getAllFormats();
			if ($formats) {
				$result = [];
				switch ($media_type) {
					case YTD_MEDIA_TYPE_COMBINED:
						$result = Utils::arrayFilterReset($formats, function ($format) {
							return strpos($format->mimeType, 'video') === 0 && !empty($format->audioQuality);
						});
						if (empty($result)) {
							$quality = YTD_PREFERRED_QUALITY_BEST;
							$media_type = YTD_MEDIA_TYPE_ALL;
							return get_yt_video_download_urls($url, $err, $result, $quality, $media_type);
						}
						break;
					case YTD_MEDIA_TYPE_AUDIO_ONLY:
						$result = Utils::arrayFilterReset($formats, function ($format) {
							return strpos($format->mimeType, 'audio') === 0;
						});
						if (empty($result)) {
							$media_type = YTD_MEDIA_TYPE_COMBINED;
							return get_yt_video_download_urls($url, $err, $result, $quality, $media_type);
						}
						break;
					case YTD_MEDIA_TYPE_VIDEO_ONLY:
						$result = Utils::arrayFilterReset($formats, function ($format) {
							return strpos($format->mimeType, 'video') === 0 && empty($format->audioQuality);
						});
						if (empty($result)) {
							$media_type = YTD_MEDIA_TYPE_COMBINED;
							return get_yt_video_download_urls($url, $err, $result, $quality, $media_type);
						}
						break;
					default:
						$result = array_values($formats);
					break;
				}
				if (!empty($result)) {
					if ($quality !== YTD_PREFERRED_QUALITY_ALL) {
						usort($result, function ($a, $b) {
							return $a->contentLength - $b->contentLength;
						});
						switch ($quality) {
							case YTD_PREFERRED_QUALITY_BEST:
								$result = [$result[count($result) - 1]];
								break;
							case YTD_PREFERRED_QUALITY_LOWER:
								$result = [$result[0]];
								break;
						}
					}
					$output = [];
					foreach($result as $item) {
						$item_url = $item->url;
						$content_length = (int)$item->contentLength;
						if ($max_media_size && ($content_length > $max_media_size)) {
							if ($err)
								$err .= "\n";
							$err .= "Media size ($content_length bytes) for video at $url exceeded max allowed size of $max_media_size bytes.";
						} else {
							$output[] = $item_url;
						}
					}
					if (empty($output)) {
						return null;
					} else {
						return $output;
					}
				}
			} else {
				$err = 'No links found.';
			}
		} catch (YouTubeException $e) {
			$err = $e->getMessage();
		}
	}

	return null;

} 

function get_api_call_data() {
    
    $data = file_get_contents('php://input');

    if ($data) {
        $data = json_decode($data, true);
        if (empty($data))
            $data = null;
    }
    
    if ((!$data) && (!empty($_POST)))
        $data = $_POST;
    
    if ((!$data) && (!empty($_GET))) {
        $data = $_GET;
    }    

    return $data;

}

function get_yt_video_embed_html($video_url) {

	if (!function_exists('getYoutubeVideoId')) {
		function getYoutubeVideoId($url) {
			$pattern = '/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|[^#]*[?&]v=|[^#]*#p\/[a-z\/]*))([\w-]{11})/';
			preg_match($pattern, $url, $matches);	
			if (isset($matches[1])) {
				return $matches[1];
			} else {
				// Verifica anche per le short URL
				$path = parse_url($url, PHP_URL_PATH);
				$pathParts = explode('/', $path);
				if (count($pathParts) > 1 && strlen($pathParts[1]) === 11) {
					return $pathParts[1];
				} else {
					return false;
				}
			}
		}
	}
		
	$video_id = getYoutubeVideoId($video_url);

	if ($video_id) {
/*		return 	"
			<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\"><span class=\"embed-youtube\" style=\"text-align:center; display: block;\"><iframe src=\"https://www.youtube.com/embed/$video_id?version=3&amp;rel=1&amp;showsearch=0&amp;showinfo=1&amp;iv_load_policy=1&amp;fs=1&amp;hl=it-IT&amp;autohide=2&amp;wmode=transparent\" class=\"youtube-player _iub_cs_activate _iub_cs_activate-activated\" width=\"1200\" height=\"675\" allowfullscreen=\"true\" style=\"border:0;\" sandbox=\"allow-scripts allow-same-origin allow-popups allow-presentation\" data-iub-purposes=\"3\" async=\"false\"></iframe></span></div></figure>	
		";	*/
		return 	"
			<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\"><div class=\"wp-block-embed__wrapper\"><span class=\"embed-youtube\" style=\"text-align:center; display: block;\"><iframe class=\"youtube-player\" width=\"1200\" height=\"675\" src=\"https://www.youtube.com/embed/$video_id?version=3&amp;rel=1&amp;showsearch=0&amp;showinfo=1&amp;iv_load_policy=1&amp;fs=1&amp;hl=it-IT&amp;autohide=2&amp;wmode=transparent\" allowfullscreen=\"true\" style=\"border:0;\" sandbox=\"allow-scripts allow-same-origin allow-popups allow-presentation\"></iframe></span></div></figure>				
		";
	} else {
		return null;
	}

}

function get_yt_video_thumbnail(YouTube\Models\VideoDetails $video, ?int $min_width = null, ?int $min_height = null, bool $force_min_size = false): ?string {

	$result = '';

	$video_infos = $video->getThumbnail();

	if (!empty($video_infos)) {
		if (is_array($video_infos)) {
			if ((!empty($video_infos['thumbnails'])) && is_array($video_infos['thumbnails'])) {
				$raw_array = $video_infos['thumbnails'];
				$results = [];
				foreach ($raw_array as $value) {
					if ($min_width && (((int)$value['width']) < $min_width))
						continue;
					if ($min_height && (((int)$value['height']) < $min_height))
						continue;
					$results[] = $value['url'];
				}
				if (empty($results)) {
					if (!$force_min_size)
						$result = $raw_array[count($raw_array) - 1]['url'];
				} else {
					$index = count($results) - 1;
					if ($min_width || $min_height)
						$index = 0;
					$result = $results[$index];
				}
			}
		} else {
			$result = $video_infos;
		}
	}

	if (filter_var($result, FILTER_VALIDATE_URL))
		return $result;

	return null;

} 