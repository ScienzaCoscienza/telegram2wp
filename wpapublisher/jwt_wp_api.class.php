<?php

include_once 'commons.php';
include_once 'Html2Text.php';

const ENDPOINT = 'wp-json/wp/v2/';
const AUTH_ENDPOINT = 'wp-json/jwt-auth/v1/token/';


function wpap_curl_post2(string $url, $data, ?string &$err = ''): ?string {

	$ch = curl_init($url);
	$post_data = http_build_query($data);
	
	curl_setopt($ch, CURLOPT_POST, true); // Metodo POST
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data); // Dati da inviare
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Restituisci il risultato come stringa
	
	if( ! $result = curl_exec($ch)) {
		$err .= curl_error($ch);
		$result = null;
	}

	curl_close($ch);
	return $result;

}

class WpCategory {

	public int $id;
	public ?int $parent_id = null;
	public string $name;

	public function __construct(string $name, int $id, ?int $parent_id = null) {

		$this->id = $id;
		$this->parent_id = $parent_id;
		$this->name = $name;

	}

}

class WpPost {

	public ?int $id = null;
	public string $title;
	public string $content;
	public string $excerpt;
	public ?int $date = null;
	public ?string $status = null; /* publish, future, draft, pending, private */
	public ?int $author = null;
	public ?int $featured_media_id = null;
	public ?string $featured_media_url = null;
	public ?string $format = null;
	public ?array $categories = null;
	public ?array $tags = null;

	public function __construct(string $title, string $content, string $excerpt) {

		$this->title = $title;
		$this->content = $content;
		$this->excerpt = $excerpt;

	}

}

class WpUser {

	public ?int $id = null;
	public ?string $username = null;
	public ?string $email = null;
	public ?string $nicename = null;
	public ?string $display_name = null;

}

class JWTWpAPI {

	private string $_endpoint = ENDPOINT;
	private string $_auth_endpoint = AUTH_ENDPOINT;
	private string $_url;
	private ?string $_username = null;
	private ?string $_password = null;
	private ?string $_last_token = null;
	private ?string $_user_email = null;
	private ?string $_user_nicename = null;
	private ?string $_user_display_name = null;
	private ?array $_header = null;
	private bool $_always_test_connection = true;
	private bool $_auth_mode = true;

	public function create_post(WpPost $post, ?string &$err = '', bool $media_required = false, ?array $extra_data = null): ?int {

		try {
			$proceed = true;
			if ($this->_auth_mode) {
				if ($this->_always_test_connection)
					$proceed = $this->connect($err);
			} else {
				$proceed = false;
			}
			if ($proceed) {
				if ((!$post->featured_media_id) && ($post->featured_media_url)) {
					$post->featured_media_id = $this->create_media($post->featured_media_url, '', $err);
				}
				if ($media_required && (!$post->featured_media_id))
					return null;
				$post_data = 	[
									'author' => $post->author,
									'date' => empty($post->date) ? null : date('Y-m-d', $post->date) . 'T' . date('H:i:s', $post->date),
									'status' => $post->status,
									'title' => $post->title,
									'content' => $post->content,
									'excerpt' => $post->excerpt,
									'featured_media' => $post->featured_media_id,
									'format' => $post->format,
									'categories' => $this->filter_post_categories($post->categories),
									'tags' => $this->_implode_tags($post->tags)
								];
				if (!empty($extra_data))
					$post_data = $post_data + $extra_data;
				$res = wpap_curl_post($this->_url . $this->_endpoint . 'posts', $post_data, null, $err, $this->_header);
				if ($res) {
					$token_raw_data = json_decode($res, TRUE);
					if (empty($token_raw_data)) {
						$err = 'Bad JSON result data.';
					} else {
						if (empty($token_raw_data['message'])) {
							if (!empty($token_raw_data['id']))
								return (int)$token_raw_data['id'];
						} else {
							$err = $token_raw_data['message'];
						}
					}
				}
			}
			return null;
		} catch (Exception $e) {
			$err .= $e->getMessage();
			return null;
		}

	}

	private function filter_post_categories(?array $categories): ?array {

		$result = [];

		if (empty($categories))
			return null;

		foreach ($categories as $category) {
			$category = (int)$category;
			if ($category)
				$result[] = $category;
		}
		
		if (empty($result))
			return null;

		return $result;

	}

	public function tags(?string &$err = ''): ?array {

		try {
			$go_on = true;
			$totalpages = 0;
			$page = 1;
			$result = [];
			while($go_on) {
				$go_on = false;
				$headers = [];
				$res = wpap_curl($this->_url . $this->_endpoint . "tags?per_page=100&page=$page", null, $err, $headers);
				if ($res) {
					$token_raw_data = json_decode($res, TRUE);
					if (empty($token_raw_data)) {
						$err = 'Bad JSON result data.';
					} else {
						if (empty($token_raw_data['message'])) {
							if ((!empty($headers)) && is_array($headers) && (!empty($headers['x-wp-totalpages'][0]))) {
								foreach($token_raw_data as $tag){
									$result[(int)$tag['id']] = $tag['name'];
								}
								$totalpages = (int)$headers['x-wp-totalpages'][0];
								if ($totalpages === $page) {
									return $result;
								} else {
									$page += 1;
									$go_on = true;
								}
							} else {
								$err .= 'Headers data not found.';
								break;
							}
						} else {
							$err .= $token_raw_data['message'];
							break;
						}
					}
				}
			};
			return NULL;
		} catch (Exception $e) {
			$err .= $e->getMessage();
			return NULL;
		}

	}

	public function categories(?string &$err = ''): ?array {

		try {
			$go_on = true;
			$totalpages = 0;
			$page = 1;
			$result = [];
			while($go_on) {
				$go_on = false;
				$headers = [];
				$res = wpap_curl($this->_url . $this->_endpoint . "categories?per_page=100&page=$page", null, $err, $headers);
				if ($res) {
					$token_raw_data = json_decode($res, TRUE);
					if (empty($token_raw_data)) {
						$err = 'Bad JSON result data.';
					} else {
						if (empty($token_raw_data['message'])) {
							if ((!empty($headers)) && is_array($headers) && (!empty($headers['x-wp-totalpages'][0]))) {
								foreach($token_raw_data as $cat){
									if (empty($cat['parent'])) {
										$parent = null;
									} else {
										$parent = (int)$cat['parent'];
									}
									$category = new WpCategory($cat['name'], $cat['id'], $parent);
									$result[(int)$cat['id']] = $category;
								}
								$totalpages = (int)$headers['x-wp-totalpages'][0];
								if ($totalpages === $page) {
									return $result;
								} else {
									$page += 1;
									$go_on = true;
								}
							} else {
								$err .= 'Headers data not found.';
								break;
							}
						} else {
							$err .= $token_raw_data['message'];
							break;
						}
					}
				}
			};
			return NULL;
		} catch (Exception $e) {
			$err .= $e->getMessage();
			return NULL;
		}

	}

	public function users(?string &$err = ''): ?array {

		try {
			$go_on = true;
			$totalpages = 0;
			$page = 1;
			$result = [];
			while($go_on) {
				$go_on = false;
				$headers = [];
				$res = wpap_curl($this->_url . $this->_endpoint . "users?per_page=100&page=$page", null, $err, $headers);
				if ($res) {
					$token_raw_data = json_decode($res, TRUE);
					if (empty($token_raw_data)) {
						$err = 'Bad JSON result data.';
					} else {
						if (empty($token_raw_data['message'])) {
							if ((!empty($headers)) && is_array($headers) && (!empty($headers['x-wp-totalpages'][0]))) {
								foreach($token_raw_data as $user){
									$new_user = new WpUser();
									$new_user->display_name = $user['name'];
									$new_user->username = $user['slug'];
									$new_user->id = $user['id'];
									$result[] = $new_user;
								}
								$totalpages = (int)$headers['x-wp-totalpages'][0];
								if ($totalpages === $page) {
									return $result;
								} else {
									$page += 1;
									$go_on = true;
								}
							} else {
								$err .= 'Headers data not found.';
								break;
							}
						} else {
							$err .= $token_raw_data['message'];
							break;
						}
					}
				}
			};
			return NULL;
		} catch (Exception $e) {
			$err .= $e->getMessage();
			return NULL;
		}

	}

	public function create_tag(string $name, ?string &$err = ''): ?int {

		try {
			$proceed = true;
			if ($this->_auth_mode) {
				if ($this->_always_test_connection)
					$proceed = $this->connect($err);
			} else {
				$proceed = false;
			}
			if ($proceed) {
				$res = wpap_curl_post($this->_url . $this->_endpoint . 'tags', ['name' => $name], null, $err, $this->_header);
				if ($res) {
					$token_raw_data = json_decode($res, TRUE);
					if (empty($token_raw_data)) {
						$err = 'Bad JSON result data.';
					} else {
						if (empty($token_raw_data['message'])) {
							if (!empty($token_raw_data['id']))
								return (int)$token_raw_data['id'];
						} else {
							$err = $token_raw_data['message'];
						}
					}
				}
			}
			return null;
		} catch (Exception $e) {
			$err .= $e->getMessage();
			return null;
		}

	}

	public function create_media(string $url, ?string $file_name = '', ?string &$err = ''): ?int {

		try {
			$proceed = true;
			if ($this->_auth_mode) {
				if ($this->_always_test_connection)
					$proceed = $this->connect($err);
			} else {
				$proceed = false;
			}
			if ($proceed) {
/*				
				$clean_$url = explode('?', $url);
				if (is_array($clean_$url) && (count($clean_$url) >= 2))
					$url = $clean_$url[0];
*/
				if (filter_var($url, FILTER_VALIDATE_URL)) {
					$file = file_get_contents($url);
					/*	$file = wpap_curl($url, array(), $err);	*/
					if (!$file) {
						$err = "Failed loading media file ($url).";
						return null;
					}
					if (!trim($file_name)) {
						$file_name = explode('?', basename($url))[0];
						$file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
						if (!in_array($file_ext, ['jpg', 'jpeg', 'mp3', 'mp4', 'gif', 'png'], true))
							$file_name = str_ireplace(".$file_ext", '.jpg', $file_name);
					}
					$head = $this->_header;
					$head[] = "Content-Disposition: form-data; filename=\"$file_name\"";
					$res = wpap_curl_post($this->_url . $this->_endpoint . 'media', $file, [CURLOPT_CUSTOMREQUEST => "POST"], $err, $head);
					if ($res) {
						$token_raw_data = json_decode($res, TRUE);
						if (empty($token_raw_data)) {
							$err = "Bad JSON result data ($url).";
						} else {
							if (empty($token_raw_data['message'])) {
								if (!empty($token_raw_data['id']))
									return (int)$token_raw_data['id'];
							} else {
								$err = $token_raw_data['message'] . " ($url).";
							}
						}
					}
				} else {
					$err = "Invalid media URL ($url).";
				}
			}
			return null;
		} catch (Exception $e) {
			$err .= $e->getMessage();
			return null;
		}

	}

	private function _implode_tags(?array $tag_list): ?string {

		$result = '';
		$cur_tags = $this->tags();

		if ($cur_tags) {
			foreach ($tag_list as $tag) {
				$new_tag_id = null;
				foreach ($cur_tags as $key => $val) {
					if (strtolower($val) === strtolower($tag)) {
						$new_tag_id = $key;
						break;
					}
				}
				if (!$new_tag_id)
					$new_tag_id = $this->create_tag($tag);
				if ($new_tag_id) {
					if ($result)
						$result .= ',';
					$result .= $new_tag_id;
				}
			}
		}

		if (!$result)
			$result = null;

		return $result;

	}

	public function __construct(string $url, ?string $username = null, ?string $password = null, bool $always_test_connection = true, $endpoint = ENDPOINT, $auth_endpoint = AUTH_ENDPOINT) {

		$this->_endpoint = ltrim(rtrim(trim($endpoint), "/"), "/") . '/';
		$this->_url = rtrim(trim($url), "/") . '/';
		$this->_always_test_connection = $always_test_connection;

		if ($username && $password) {
			$this->_auth_endpoint = ltrim(rtrim(trim($auth_endpoint), "/"), "/") . '/';
			$this->_username = $username;
			$this->_password = $password;
			$err = '';
			if (!$this->connect($err))
				throw new Exception($err);
		} else {
			$this->_auth_mode = false;
		}
	}

	public function user_data(): ?WpUser {

		if ($this->_auth_mode && $this->token()) {
			$result = new WpUser;
			$result->username = $this->_username;
			$result->email = $this->_user_email;
			$result->nicename = $this->_user_nicename;
			$result->display_name = $this->_user_display_name;
			$res = wpap_curl($this->_url . $this->_endpoint . "users?username={$result->username}");
			if ($res) {
				$token_raw_data = json_decode($res, true);
				if (empty($token_raw_data)) {
					return null;
				} else {
					if (empty($token_raw_data[0]['id'])) {
						return null;
					} else {
						$result->id = (int)$token_raw_data[0]['id'];
					}
				}
			} else {
				return null;
			}
			return $result;
		} else {
			return null;
		}

	}

	public function token(?bool $do_test = null): ?string {

		if (!$this->_auth_mode)
			return null;

		if ($do_test === null)
			$do_test = $this->_always_test_connection;

		if ($do_test) {
			return $this->connect();
		} else {
			return $this->_last_token;
		}

	}

	public function header(?bool $do_test = null): array {

		if (!$this->_auth_mode)
			return [];

		$this->token($do_test);

		if ($this->_header === null) {
			return [];
		} else {
			return $this->_header;
		}

	}

	public function connect(?string &$err = ''): ?string {

		if (!$this->_auth_mode)
			return null;

		$errmsg = '';

		if ($this->_last_token) {
			$res = wpap_curl_post($this->_url . $this->_auth_endpoint . 'validate', [], null, $errmsg, $this->_header);
			if ($res) {
				$token_raw_data = json_decode($res, TRUE);
				if (!empty($token_raw_data)) {
					if (!empty($token_raw_data['data'])) {
						if (!empty($token_raw_data['data']['status'])) {
							if ($token_raw_data['data']['status'] == 200)
								return $this->_last_token;
						}
					}
				}
			}
			$this->_last_token = null;
			return $this->connect($err);
		} else {
			$res = wpap_curl_post($this->_url . $this->_auth_endpoint, ['username' => $this->_username, 'password' => $this->_password], null, $errmsg);
			if ($res) {
				$token_raw_data = json_decode($res, true);
				if (empty($token_raw_data)) {
					$err .= 'Bad JSON token data.';
				} else {
					if (empty($token_raw_data['token'])) {
						if (empty($token_raw_data['message'])) {
							$err .= 'Empty JSON token data.';
						} else {
							$err .= $token_raw_data['message'];
						}
					} else {
						$this->_last_token = $token_raw_data['token'];
						$this->_header = ["Authorization: Bearer {$this->_last_token}"];
						$this->_user_email = empty($token_raw_data['user_email']) ? '' : $token_raw_data['user_email'];
						$this->_user_display_name = empty($token_raw_data['user_display_name']) ? '' : $token_raw_data['user_display_name'];
						$this->_user_nicename = empty($token_raw_data['user_nicename']) ? '' : $token_raw_data['user_nicename'];
					}
				}
			} else {
				$err .= $errmsg;
			}
		}

		if (!$this->_last_token) {
			$this->_user_email = null;
			$this->_user_display_name = null;
			$this->_user_nicename = null;
			$this->_header = null;
		}

		return $this->_last_token;

	}

	public function add_post_categories(WpPost $post, ?string &$err = '', ?string $text_start_key = '[pollyness]', ?string $text_end_key = 'Fonte: ', ?string $ai_api_user_key = null, ?string $ai_api_token = null): ?array {

		if (!($ai_api_user_key && $ai_api_token))
			return [false];
			
		$result = null;
		$all_categories = $this->categories($err);

		if (!empty($all_categories)) {
			$all_categories_ids = [];
			$documents = [];
			foreach ($all_categories as $cat_id => $cat) {
				$all_categories_ids[] = (int)$cat_id;
				$documents[] = $this->get_category_path_name($cat, $all_categories);
			}
			if (!empty($documents)) {
				$html_content = new \Html2Text\Html2Text($post->content, array('do_links' => 'none', 'width' => 0));
				$text_content = $html_content->getText();
				$text_content = str_ireplace('[Immagine]', '', $text_content);
				if ($text_start_key) {
/*					
					$pos1 = strpos(utf8_encode($text_content), utf8_encode($text_start_key));
					if ($pos1 !== false) {
						$text_content = trim(mb_substr(utf8_encode($text_content), $pos1 + mb_strlen(utf8_encode($text_start_key), 'UTF-8'), null, 'UTF-8'));
					}
*/
					$pos1 = strpos($text_content, $text_start_key);
					if ($pos1 !== false) {
						$text_content = trim(substr($text_content, $pos1 + strlen($text_start_key)));
					}
					$pos1 = strpos($text_content, $text_end_key);
					if ($pos1 !== false) {
						$text_content = trim(substr($text_content, 0, $pos1));
					}
				}
				$text_content = trim($text_content);
				$query = "{$post->title}. $text_content";
				$docs_text_content = json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				$postdata = ['query' => $query, 'docs' => $docs_text_content, 'token' => get_token($ai_api_user_key, $ai_api_token)];
				$err = '';

				if (RELEASE_TARGET == 'dev') {
					$res = wpap_curl_post(AI_SEARCH_API_URL, $postdata, array(), $err);	
				} else {
					$res = wpap_curl_post(AI_SEARCH_API_URL, $postdata, array(), $err, array(), false);	
				}

				$res = trim($res);

//echo '<p>res = ' . $res . '</p><br><br>';	// debug

				if ($res) {
					$result = json_decode($res, true);
					if (!empty($result['message']))
						$result['error'] = $result['message'];
					if ((!empty($result)) && empty($result["error"])) {

echo '<p>[630] result = ' . print_r($result, true) . '</p><br><br>';	// debug

						$result = $result['data'];
						$categories = [];
						foreach ($result as $cat_index) {
							if ($cat_index < count($all_categories_ids)) {
								$new_cat = $all_categories_ids[(int)$cat_index];
								if (!in_array($new_cat, $categories))
									$categories[] = $new_cat;
							}
						}
						$post->categories = $categories;	
						$result = $categories;
					} else {
						$post->categories = null;
						$result = [false];
					}
				} else {
					echo "<p>err = $err</p><br><br>";	// debug
					$post->categories = null;
					$result = [$res];
				}

			}
		}

		return $result;

	}

	private function get_category_path_name(WpCategory $category, ?array $categories = null, bool $full_path = false, string $sep = ' / ', string $exclude_str = ''): string {

		if ($full_path && $category->parent_id) {
			if ($categories === null)
				$categories = $this->categories();
			if (empty($categories))
				return str_replace($exclude_str, '', $category->name);
			foreach ($categories as $id => $cat) {
				if ($id === ((int)$category->parent_id))
					return $this->get_category_path_name($cat, $categories, $sep) . $sep . str_replace($exclude_str, '', $category->name);
			}
			return str_replace($exclude_str, '', $category->name);
		} else {
			return str_replace($exclude_str, '', $category->name);
		}

	}

}
