<?php

/**
 * Content sources: RSS feeds and website scraping for AI article generation.
 *
 * @package WP-AutoInsight
 * @since   4.0.0
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * Get all configured content sources.
 *
 * @return array
 */
function abcc_get_content_sources()
{
	return get_option('abcc_content_sources', array());
}

/**
 * Save content sources.
 *
 * @param array $sources Array of source configurations.
 * @return bool
 */
function abcc_save_content_sources($sources)
{
	return update_option('abcc_content_sources', $sources);
}

/**
 * 构造 Google 新闻搜索 RSS URL。
 *
 * Google News 暴露了一个任意关键词即可调用的 RSS 端点：
 *   https://news.google.com/rss/search?q=KEYWORD&hl=zh-CN&gl=CN&ceid=CN:zh-Hans
 * 它会返回跨媒体聚合的最新新闻（支持中文），比固定站点 RSS 覆盖面广得多。
 *
 * @since 4.1.4
 * @param string $query    搜索关键词（自由文本）。
 * @param string $language UI/检索语言，默认 zh-CN；其他如 en-US、zh-TW。
 * @param string $region   国家/地区 code，默认 CN；其他如 US、HK、TW。
 * @return string
 */
function abcc_build_google_news_search_url($query, $language = 'zh-CN', $region = 'CN')
{
	$query    = trim((string) $query);
	$language = $language ?: 'zh-CN';
	$region   = $region ?: 'CN';

	// ceid = region:language_simple，中文特殊处理成 zh-Hans。
	$lang_simple = (0 === stripos($language, 'zh')) ? 'zh-Hans' : substr($language, 0, 2);
	$ceid        = $region . ':' . $lang_simple;

	return sprintf(
		'https://news.google.com/rss/search?q=%s&hl=%s&gl=%s&ceid=%s',
		rawurlencode($query),
		rawurlencode($language),
		rawurlencode($region),
		rawurlencode($ceid)
	);
}

/**
 * 用 Perplexity sonar 按主题做一次联网检索，返回"待洗稿"素材条目。
 *
 * 思路：sonar 模型自带实时 web 检索 + 引用；给它一个"新闻研究员"提示词，
 * 让它返回最近关于 {topic} 的 3-5 条新闻摘要。返回结构对齐 RSS items，
 * 好让下游 abcc_build_source_prompt 无差别再洗稿一次。
 *
 * @since 4.1.5
 * @param string $query Topic.
 * @param string $model sonar / sonar-pro / sonar-reasoning-pro。
 * @return array|WP_Error
 */
function abcc_fetch_perplexity_research($query, $model = 'sonar')
{
	if (! function_exists('abcc_perplexity_generate_text')) {
		return new WP_Error('perplexity_unavailable', __('Perplexity 提供方未加载。', 'automated-blog-content-creator'));
	}

	$api_key = abcc_get_provider_api_key('perplexity');
	if (empty($api_key)) {
		return new WP_Error(
			'no_perplexity_key',
			__('未配置 Perplexity API 密钥，无法使用 AI 研究型来源。', 'automated-blog-content-creator')
		);
	}

	$allowed = array('sonar', 'sonar-pro', 'sonar-reasoning-pro');
	if (! in_array($model, $allowed, true)) {
		$model = 'sonar';
	}

	$prompt  = "你是一位专业的新闻研究员。请检索最近关于「{$query}」的 3-5 条最重要的新闻或进展。\n\n";
	$prompt .= "对每条新闻请给出：\n";
	$prompt .= "1. 一行标题（不超过 30 个字）\n";
	$prompt .= "2. 事件发生时间（若可获取，格式 YYYY-MM-DD，若无则写「近期」）\n";
	$prompt .= "3. 核心事实摘要（3-5 句话，用中文）\n\n";
	$prompt .= "格式要求：\n";
	$prompt .= "- 每条之间用单独一行「---」分隔\n";
	$prompt .= "- 不要加总标题、不要给个人评论或总结\n";
	$prompt .= "- 不要编造，只根据你检索到的信息回答\n";
	$prompt .= "- 全部用中文输出";

	$response = abcc_perplexity_generate_text($api_key, $prompt, 1500, $model);

	if (false === $response || empty($response['text'])) {
		return new WP_Error(
			'perplexity_empty',
			__('Perplexity 未返回内容，请检查 API 密钥或主题。', 'automated-blog-content-creator')
		);
	}

	$full_text = implode("\n", (array) $response['text']);
	$citations = isset($response['citations']) ? (array) $response['citations'] : array();

	// 按 "---" 分块，解析出多个 item；若模型没按格式走，整块作为单条素材。
	$chunks = preg_split('/^\s*-{3,}\s*$/m', $full_text);
	$items  = array();
	foreach ($chunks as $idx => $chunk) {
		$chunk = trim($chunk);
		if ('' === $chunk) {
			continue;
		}
		$lines = array_values(array_filter(array_map('trim', explode("\n", $chunk))));
		if (empty($lines)) {
			continue;
		}
		$title = preg_replace('/^\s*(\d+[.、)]\s*|[-*•]\s*|标题[:：]\s*)/u', '', $lines[0]);
		$title = trim($title, " \t\n\r\0\x0B\"'‘’“”「」『』《》");
		$body  = count($lines) > 1 ? implode("\n", array_slice($lines, 1)) : $lines[0];

		$items[] = array(
			'title'       => $title ?: $query,
			'link'        => $citations[$idx] ?? ($citations[0] ?? ''),
			'description' => wp_strip_all_tags($body),
			'content'     => wp_strip_all_tags($body),
			'date'        => current_time('Y-m-d H:i:s'),
		);
	}

	if (empty($items)) {
		$items[] = array(
			'title'       => $query,
			'link'        => $citations[0] ?? '',
			'description' => wp_strip_all_tags($full_text),
			'content'     => wp_strip_all_tags($full_text),
			'date'        => current_time('Y-m-d H:i:s'),
		);
	}

	return $items;
}

/**
 * 预置的体育新闻 RSS 源。英文站由 AI 改写为中文，所以源是英文也没关系。
 *
 * 维护说明：RSS 地址会变动，若某个失效到后台"内容来源"里直接删掉或替换即可。
 *
 * @since 4.1.3
 * @return array
 */
function abcc_get_preset_sports_rss_feeds()
{
	return array(
		array(
			'name'     => 'BBC Sport',
			'url'      => 'https://feeds.bbci.co.uk/sport/rss.xml',
			'type'     => 'rss',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'ESPN Top Headlines',
			'url'      => 'https://www.espn.com/espn/rss/news',
			'type'     => 'rss',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'The Guardian Sport',
			'url'      => 'https://www.theguardian.com/sport/rss',
			'type'     => 'rss',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'Sky Sports News',
			'url'      => 'https://www.skysports.com/rss/12040',
			'type'     => 'rss',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'CBS Sports Headlines',
			'url'      => 'https://www.cbssports.com/rss/headlines/',
			'type'     => 'rss',
			'category' => 0,
			'enabled'  => true,
		),
	);
}

/**
 * 预置的体育类 Google 新闻关键词搜索（中文）。
 *
 * 每条会被展开成 Google News RSS 搜索 URL，覆盖全网主流媒体（中/英混合返回），
 * 然后由 AI 洗稿改写成中文原创文章发布。
 *
 * @since 4.1.4
 * @return array
 */
function abcc_get_preset_sports_news_searches()
{
	return array(
		array(
			'name'     => 'Google 新闻：NBA',
			'type'     => 'news_search',
			'query'    => 'NBA',
			'language' => 'zh-CN',
			'region'   => 'CN',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'Google 新闻：英超',
			'type'     => 'news_search',
			'query'    => '英超',
			'language' => 'zh-CN',
			'region'   => 'CN',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'Google 新闻：中超',
			'type'     => 'news_search',
			'query'    => '中超联赛',
			'language' => 'zh-CN',
			'region'   => 'CN',
			'category' => 0,
			'enabled'  => true,
		),
		array(
			'name'     => 'Google 新闻：世界杯',
			'type'     => 'news_search',
			'query'    => '世界杯',
			'language' => 'zh-CN',
			'region'   => 'CN',
			'category' => 0,
			'enabled'  => true,
		),
	);
}

/**
 * 把预置 Google 新闻搜索合入现有来源，按 name+query 去重。供 4.1.4 迁移调用。
 *
 * @since 4.1.4
 * @return int 本次新增条目数。
 */
function abcc_seed_preset_sports_news_searches()
{
	$existing = abcc_get_content_sources();
	$existing_keys = array();
	foreach ((array) $existing as $src) {
		if ('news_search' === ($src['type'] ?? '')) {
			$existing_keys[] = strtolower(trim($src['query'] ?? '')) . '|' . ($src['language'] ?? '');
		}
	}

	$added = 0;
	foreach (abcc_get_preset_sports_news_searches() as $preset) {
		$key = strtolower(trim($preset['query'])) . '|' . $preset['language'];
		if (in_array($key, $existing_keys, true)) {
			continue;
		}
		$existing[]      = $preset;
		$existing_keys[] = $key;
		$added++;
	}

	if ($added > 0) {
		abcc_save_content_sources($existing);
	}

	return $added;
}

/**
 * 把预置体育 RSS 合入现有来源，按 URL 去重。供 4.1.3 迁移调用。
 *
 * @since 4.1.3
 * @return int 本次新增条目数。
 */
function abcc_seed_preset_sports_rss_feeds()
{
	$existing     = abcc_get_content_sources();
	$existing_urls = array();
	foreach ((array) $existing as $src) {
		if (! empty($src['url'])) {
			$existing_urls[] = strtolower(trim($src['url']));
		}
	}

	$added = 0;
	foreach (abcc_get_preset_sports_rss_feeds() as $preset) {
		$key = strtolower(trim($preset['url']));
		if (in_array($key, $existing_urls, true)) {
			continue;
		}
		$existing[]      = $preset;
		$existing_urls[] = $key;
		$added++;
	}

	if ($added > 0) {
		abcc_save_content_sources($existing);
	}

	return $added;
}

/**
 * Fetch items from an RSS feed.
 *
 * Uses WordPress built-in SimplePie via fetch_feed().
 *
 * @param string $url   RSS feed URL.
 * @param int    $limit Max items to fetch.
 * @return array|WP_Error Array of items or WP_Error on failure.
 */
function abcc_fetch_rss_items($url, $limit = 5)
{
	$feed = fetch_feed($url);

	if (is_wp_error($feed)) {
		return $feed;
	}

	$max_items = $feed->get_item_quantity($limit);
	$items     = $feed->get_items(0, $max_items);
	$result    = array();

	foreach ($items as $item) {
		$description_html = $item->get_description();
		$content_html     = $item->get_content();
		$permalink        = $item->get_permalink();

		$images = array();

		// RSS enclosures（Atom / RSS 2.0 的常规带图方式，含 media:content / media:thumbnail）。
		foreach ((array) $item->get_enclosures() as $enc) {
			if (! $enc) {
				continue;
			}
			$link = method_exists($enc, 'get_link') ? $enc->get_link() : '';
			$type = method_exists($enc, 'get_type') ? (string) $enc->get_type() : '';
			if (empty($link)) {
				continue;
			}
			if ('' !== $type && 0 !== strpos($type, 'image/')) {
				continue;
			}
			if (abcc_source_is_useful_image($link)) {
				$images[] = $link;
			}
		}

		// 从 description / content HTML 里再挖一遍 <img src="">。
		$images = array_merge(
			$images,
			abcc_source_extract_images_from_html($description_html, $permalink),
			abcc_source_extract_images_from_html($content_html, $permalink)
		);

		$result[] = array(
			'title'       => $item->get_title(),
			'link'        => $permalink,
			'description' => wp_strip_all_tags($description_html),
			'content'     => wp_strip_all_tags($content_html),
			'date'        => $item->get_date('Y-m-d H:i:s'),
			'images'      => array_values(array_unique($images)),
		);
	}

	return $result;
}

/**
 * 过滤掉明显没用的图片（追踪像素、logo、头像、emoji、sprite 等）。
 */
function abcc_source_is_useful_image($url)
{
	if (empty($url) || ! is_string($url)) {
		return false;
	}
	if (! preg_match('#^https?://#i', $url)) {
		return false;
	}
	$junk = '/(pixel|tracking|beacon|analytics|logo|avatar|emoji|icon|sprite|blank\.gif|1x1|spacer|badge)/i';
	if (preg_match($junk, $url)) {
		return false;
	}
	// 明显的超小图
	if (preg_match('/[?&-](\d{1,2})x(\d{1,2})[.&_-]/i', $url)) {
		return false;
	}
	return true;
}

/**
 * 把相对 URL 解析为绝对 URL。基础 URL 通常是原文链接。
 */
function abcc_source_normalize_url($src, $base_url = '')
{
	$src = trim((string) $src);
	if ('' === $src) {
		return '';
	}
	if (preg_match('#^data:#i', $src)) {
		return '';
	}
	if (preg_match('#^//#', $src)) {
		return 'https:' . $src;
	}
	if (preg_match('#^https?://#i', $src)) {
		return $src;
	}
	if (empty($base_url)) {
		return $src;
	}
	$base = wp_parse_url($base_url);
	if (! $base || empty($base['host'])) {
		return $src;
	}
	$scheme = $base['scheme'] ?? 'https';
	$host   = $base['host'];
	if (0 === strpos($src, '/')) {
		return $scheme . '://' . $host . $src;
	}
	$base_path = isset($base['path']) ? preg_replace('#/[^/]*$#', '/', $base['path']) : '/';
	return $scheme . '://' . $host . $base_path . $src;
}

/**
 * 从一段 HTML 里提取 <img src> + og:image。
 *
 * @param string $html     任意 HTML 片段或整页。
 * @param string $base_url 用于解析相对地址的基准。
 * @return string[]
 */
function abcc_source_extract_images_from_html($html, $base_url = '')
{
	if (empty($html)) {
		return array();
	}
	$images = array();

	if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
		$url = abcc_source_normalize_url($m[1], $base_url);
		if (abcc_source_is_useful_image($url)) {
			$images[] = $url;
		}
	}
	if (preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
		$url = abcc_source_normalize_url($m[1], $base_url);
		if (abcc_source_is_useful_image($url)) {
			$images[] = $url;
		}
	}
	if (preg_match_all('/<img[^>]+(?:data-src|src)=["\']([^"\']+)["\']/i', $html, $m)) {
		foreach ($m[1] as $src) {
			$url = abcc_source_normalize_url($src, $base_url);
			if (abcc_source_is_useful_image($url)) {
				$images[] = $url;
			}
		}
	}

	return array_values(array_unique($images));
}

/**
 * Fetch and extract main text content from a webpage.
 *
 * @param string $url Webpage URL.
 * @return string|WP_Error Extracted text or WP_Error on failure.
 */
function abcc_fetch_webpage_content($url)
{
	$article = abcc_fetch_webpage_article($url);
	if (is_wp_error($article)) {
		return $article;
	}
	return $article['text'];
}

/**
 * Fetch a webpage and return both body text and image URLs in one request.
 *
 * 和旧的 abcc_fetch_webpage_content 共用同一个 HTTP 请求 —— 之前洗稿要拉一次，
 * 找图又要再拉一次，重复浪费带宽也容易被反爬。这个版本一次性返回 text+images。
 *
 * @param string $url Webpage URL.
 * @return array|WP_Error `['text' => string, 'images' => string[]]` 或 WP_Error。
 */
function abcc_fetch_webpage_article($url)
{
	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 30,
			'user-agent' => 'Mozilla/5.0 (compatible; WP-AutoInsight/' . ABCC_VERSION . ')',
		)
	);

	if (is_wp_error($response)) {
		return $response;
	}

	$status = wp_remote_retrieve_response_code($response);
	if ($status < 200 || $status >= 300) {
		return new WP_Error(
			'http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__('HTTP 请求失败，状态码：%d', 'automated-blog-content-creator'),
				$status
			)
		);
	}

	$body = wp_remote_retrieve_body($response);
	if (empty($body)) {
		return new WP_Error('empty_body', __('网页内容为空。', 'automated-blog-content-creator'));
	}

	// 先从整页抽 og:image / twitter:image（用原始 $body，不要脱标签）。
	$images = abcc_source_extract_images_from_html($body, $url);

	// 取主体区域用于正文提取。
	$region = '';
	if (preg_match('/<article[^>]*>(.*?)<\/article>/si', $body, $m)) {
		$region = $m[1];
	} elseif (preg_match('/<main[^>]*>(.*?)<\/main>/si', $body, $m)) {
		$region = $m[1];
	} elseif (preg_match('/<body[^>]*>(.*?)<\/body>/si', $body, $m)) {
		$region = $m[1];
	} else {
		$region = $body;
	}

	// 主体里再挖一遍 <img>，这些通常是正文插图，插回文章更自然。
	$region_images = abcc_source_extract_images_from_html($region, $url);
	$images        = array_values(array_unique(array_merge($images, $region_images)));

	// 去脚本/样式后再提纯文字。
	$text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $region);
	$text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $text);
	$text = wp_strip_all_tags($text);
	$text = preg_replace('/\s+/', ' ', $text);
	$text = trim($text);

	if (mb_strlen($text) > 3000) {
		$text = mb_substr($text, 0, 3000) . '...';
	}

	return array(
		'text'   => $text,
		'images' => $images,
	);
}

/**
 * Fetch trending/hot topic items from various Chinese platforms.
 *
 * @since 3.9.0
 * @param string $platform Platform identifier: baidu, toutiao, or zhihu.
 * @param int    $limit    Max items to return.
 * @return array|WP_Error Array of items or WP_Error on failure.
 */
function abcc_fetch_trending_items($platform = 'baidu', $limit = 10)
{
	$apis = array(
		'baidu'   => 'https://top.baidu.com/api/board?platform=wise&tab=realtime',
		'toutiao' => 'https://www.toutiao.com/hot-event/hot-board/?origin=toutiao_pc',
		'zhihu'   => 'https://www.zhihu.com/api/v3/feed/topstory/hot-lists/total?limit=' . $limit,
	);

	if (! isset($apis[$platform])) {
		return new WP_Error('invalid_platform', __('无效的热点平台。', 'automated-blog-content-creator'));
	}

	$response = wp_remote_get(
		$apis[$platform],
		array(
			'timeout'    => 30,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
			'headers'    => array(
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'zh-CN,zh;q=0.9',
			),
		)
	);

	if (is_wp_error($response)) {
		return $response;
	}

	$status = wp_remote_retrieve_response_code($response);
	if ($status < 200 || $status >= 300) {
		return new WP_Error('http_error', sprintf(__('热点 API 请求失败，状态码：%d', 'automated-blog-content-creator'), $status));
	}

	$body = wp_remote_retrieve_body($response);
	$data = json_decode($body, true);

	if (empty($data)) {
		return new WP_Error('parse_error', __('热点数据解析失败。', 'automated-blog-content-creator'));
	}

	$items = array();

	switch ($platform) {
		case 'baidu':
			$raw = $data['data']['cards'][0]['content'] ?? array();
			foreach (array_slice($raw, 0, $limit) as $entry) {
				$items[] = array(
					'title'       => $entry['word'] ?? $entry['query'] ?? '',
					'link'        => $entry['url'] ?? '',
					'description' => $entry['desc'] ?? '',
					'content'     => $entry['desc'] ?? '',
					'date'        => current_time('Y-m-d H:i:s'),
				);
			}
			break;

		case 'toutiao':
			$raw = $data['data'] ?? array();
			foreach (array_slice($raw, 0, $limit) as $entry) {
				$items[] = array(
					'title'       => $entry['Title'] ?? '',
					'link'        => $entry['Url'] ?? '',
					'description' => $entry['Label'] ?? ($entry['Title'] ?? ''),
					'content'     => $entry['Label'] ?? ($entry['Title'] ?? ''),
					'date'        => current_time('Y-m-d H:i:s'),
				);
			}
			break;

		case 'zhihu':
			$raw = $data['data'] ?? array();
			foreach (array_slice($raw, 0, $limit) as $entry) {
				$target  = $entry['target'] ?? array();
				$items[] = array(
					'title'       => $target['title'] ?? '',
					'link'        => isset($target['id']) ? 'https://www.zhihu.com/question/' . $target['id'] : '',
					'description' => $target['excerpt'] ?? '',
					'content'     => $target['excerpt'] ?? '',
					'date'        => current_time('Y-m-d H:i:s'),
				);
			}
			break;
	}

	// Filter out empty titles.
	$items = array_filter($items, function ($item) {
		return ! empty($item['title']);
	});

	if (empty($items)) {
		return new WP_Error('no_items', __('未获取到热点内容。', 'automated-blog-content-creator'));
	}

	return array_values($items);
}

/**
 * Fetch content from a source (RSS or webpage).
 *
 * 该函数会走缓存层 + item 级去重（若启用），避免同一条热点被反复生稿。
 *
 * @param array $source Source configuration.
 * @return array|WP_Error Fetched content items or error.
 */
function abcc_fetch_source_content($source)
{
	if ('rss' === $source['type']) {
		$items = abcc_fetch_rss_items_cached($source['url'], 5);
	} elseif ('ai_search' === $source['type']) {
		$query = isset($source['query']) ? trim((string) $source['query']) : '';
		$model = ! empty($source['ai_model']) ? $source['ai_model'] : 'sonar';
		if ('' === $query) {
			return new WP_Error(
				'missing_query',
				__('Perplexity AI 研究需要配置检索主题。', 'automated-blog-content-creator')
			);
		}
		$items = abcc_fetch_perplexity_research($query, $model);
	} elseif ('news_search' === $source['type']) {
		$query  = isset($source['query']) ? trim((string) $source['query']) : '';
		$lang   = ! empty($source['language']) ? $source['language'] : 'zh-CN';
		$region = ! empty($source['region']) ? $source['region'] : 'CN';
		if ('' === $query) {
			return new WP_Error(
				'missing_query',
				__('Google 新闻搜索需要配置关键词。', 'automated-blog-content-creator')
			);
		}
		$search_url = abcc_build_google_news_search_url($query, $lang, $region);
		$items      = abcc_fetch_rss_items_cached($search_url, 8);
	} elseif ('trending' === $source['type']) {
		$platform = $source['platform'] ?? 'baidu';

		// 如果是 "merged" 虚拟平台，跨多个平台合并。
		if ('merged' === $platform) {
			$platforms = abcc_get_setting('abcc_source_merge_platforms', array('baidu', 'toutiao', 'zhihu'));
			$items     = abcc_fetch_merged_trending_items((array) $platforms, 10);
		} else {
			$items = abcc_fetch_trending_items_cached($platform, 10);
		}
	} else {
		// Webpage: wrap single result to match RSS array format.
		$article = abcc_fetch_webpage_article_cached($source['url']);
		if (is_wp_error($article)) {
			return $article;
		}
		$text   = is_array($article) ? (string) ($article['text'] ?? '') : (string) $article;
		$images = is_array($article) && ! empty($article['images']) ? $article['images'] : array();
		$items  = array(
			array(
				'title'       => $source['name'],
				'link'        => $source['url'],
				'description' => $text,
				'content'     => $text,
				'date'        => current_time('Y-m-d H:i:s'),
				'images'      => $images,
			),
		);
	}

	if (is_wp_error($items) || empty($items)) {
		return $items;
	}

	// Item 级去重：过滤已经用过的条目。
	if (abcc_get_setting('abcc_source_dedupe_enabled', true)) {
		$items = abcc_source_filter_unused_items($items);
		if (empty($items)) {
			return new WP_Error(
				'all_items_used',
				__('该来源的所有条目近期已被使用过，请稍后再试或切换来源。', 'automated-blog-content-creator')
			);
		}
	}

	return $items;
}

/* ---------------------------------------------------------------------------
 * Source caching & item-level dedup (since 3.9.0)
 * ---------------------------------------------------------------------------
 *
 * 设计要点：
 * - 热点 API / RSS 响应按 URL 做 transient 缓存，TTL 默认 30 分钟，避免被频繁抓取 banned。
 * - 每个 item 用标题指纹（归一化 + md5）写入「近期已用」集合，默认保留 500 条。
 * - 合并采集：跨平台抓取后按标题相似度做一次汇总，「多平台命中的热点」排在前面，
 *   单平台独有的作为补充。返回时仍然是单个 items[] 结构。
 */

function abcc_source_cache_ttl()
{
	return max(60, (int) abcc_get_setting('abcc_source_cache_ttl', 1800));
}

function abcc_fetch_trending_items_cached($platform, $limit = 10)
{
	$key   = 'abcc_src_trending_' . md5($platform . ':' . $limit);
	$cache = get_transient($key);
	if (false !== $cache) {
		return $cache;
	}
	$fresh = abcc_fetch_trending_items($platform, $limit);
	if (! is_wp_error($fresh) && ! empty($fresh)) {
		set_transient($key, $fresh, abcc_source_cache_ttl());
	}
	return $fresh;
}

function abcc_fetch_rss_items_cached($url, $limit = 5)
{
	$key   = 'abcc_src_rss_' . md5($url . ':' . $limit);
	$cache = get_transient($key);
	if (false !== $cache) {
		return $cache;
	}
	$fresh = abcc_fetch_rss_items($url, $limit);
	if (! is_wp_error($fresh) && ! empty($fresh)) {
		set_transient($key, $fresh, abcc_source_cache_ttl());
	}
	return $fresh;
}

function abcc_fetch_webpage_content_cached($url)
{
	$key   = 'abcc_src_web_' . md5($url);
	$cache = get_transient($key);
	if (false !== $cache) {
		return $cache;
	}
	$fresh = abcc_fetch_webpage_content($url);
	if (! is_wp_error($fresh) && ! empty($fresh)) {
		set_transient($key, $fresh, abcc_source_cache_ttl());
	}
	return $fresh;
}

/**
 * Cached variant of abcc_fetch_webpage_article — returns both text and images.
 */
function abcc_fetch_webpage_article_cached($url)
{
	$key   = 'abcc_src_webart_' . md5($url);
	$cache = get_transient($key);
	if (false !== $cache && is_array($cache)) {
		return $cache;
	}
	$fresh = abcc_fetch_webpage_article($url);
	if (! is_wp_error($fresh) && is_array($fresh) && ! empty($fresh['text'])) {
		set_transient($key, $fresh, abcc_source_cache_ttl());
	}
	return $fresh;
}

/**
 * 生成 item 指纹（基于标题归一化）。
 */
function abcc_source_item_fingerprint($item)
{
	$title = isset($item['title']) ? (string) $item['title'] : '';
	if (function_exists('abcc_quality_normalize_title')) {
		return md5(abcc_quality_normalize_title($title));
	}
	return md5(mb_strtolower(preg_replace('/\s+/u', '', $title), 'UTF-8'));
}

function abcc_source_get_used_fingerprints()
{
	$seen = get_option('abcc_source_items_seen', array());
	return is_array($seen) ? $seen : array();
}

/**
 * 把 item 标记为已使用，限制队列长度。
 */
function abcc_source_mark_item_used($fingerprint)
{
	if ('' === $fingerprint) {
		return;
	}
	$seen = abcc_source_get_used_fingerprints();
	// 值里存时间戳，供以后做 TTL 清理。
	$seen[$fingerprint] = time();

	$max = max(50, (int) abcc_get_setting('abcc_source_dedupe_history_size', 500));
	if (count($seen) > $max) {
		// 按时间戳排序，只保留最新 $max 条。
		arsort($seen);
		$seen = array_slice($seen, 0, $max, true);
	}

	update_option('abcc_source_items_seen', $seen, false);
}

/**
 * 过滤掉已经使用过的 item。
 */
function abcc_source_filter_unused_items($items)
{
	$seen = abcc_source_get_used_fingerprints();
	if (empty($seen)) {
		return $items;
	}
	return array_values(array_filter($items, function ($item) use ($seen) {
		return ! isset($seen[abcc_source_item_fingerprint($item)]);
	}));
}

/**
 * 跨平台合并采集 + 跨源加权。
 *
 * 对多个平台抓到的 items 做一次汇总：
 * - 标题指纹相同的多个条目视作同一热点，权重 +1
 * - 权重高的排前（表示多平台共同报道，信号更强）
 * - 最多返回 $limit 个 item，供 AI 生稿使用
 *
 * @param array $platforms
 * @param int   $limit
 * @return array|WP_Error
 */
function abcc_fetch_merged_trending_items($platforms, $limit = 10)
{
	$buckets = array(); // fingerprint => ['item'=>..., 'weight'=>int, 'platforms'=>[]]
	$errors  = array();

	foreach ($platforms as $platform) {
		$res = abcc_fetch_trending_items_cached($platform, $limit);
		if (is_wp_error($res)) {
			$errors[] = $platform . ':' . $res->get_error_message();
			continue;
		}
		foreach ($res as $item) {
			$fp = abcc_source_item_fingerprint($item);
			if ('' === $fp) {
				continue;
			}
			if (! isset($buckets[$fp])) {
				$buckets[$fp] = array(
					'item'      => $item,
					'weight'    => 0,
					'platforms' => array(),
				);
			}
			$buckets[$fp]['weight']++;
			$buckets[$fp]['platforms'][] = $platform;

			// 合并 description：保留最长的一条作为主要素材。
			$curr_desc = $buckets[$fp]['item']['description'] ?? '';
			$new_desc  = $item['description'] ?? '';
			if (mb_strlen($new_desc) > mb_strlen($curr_desc)) {
				$buckets[$fp]['item']['description'] = $new_desc;
				$buckets[$fp]['item']['content']     = $new_desc;
			}
		}
	}

	if (empty($buckets)) {
		return new WP_Error(
			'no_items',
			__('跨源合并未获取到任何热点。', 'automated-blog-content-creator') . ' [' . implode('; ', $errors) . ']'
		);
	}

	// 权重降序。
	uasort($buckets, function ($a, $b) {
		return $b['weight'] <=> $a['weight'];
	});

	$merged = array();
	foreach ($buckets as $b) {
		$item                     = $b['item'];
		$item['_merge_weight']    = $b['weight'];
		$item['_merge_platforms'] = array_values(array_unique($b['platforms']));
		$merged[]                 = $item;
		if (count($merged) >= $limit) {
			break;
		}
	}

	return $merged;
}

/**
 * Build a prompt that asks the AI to write an original article based on sourced content.
 *
 * @param array  $items    Content items from the source.
 * @param string $tone     Writing tone.
 * @param string $template Custom prompt template (optional).
 * @return string The assembled prompt.
 */
function abcc_build_source_prompt($items, $tone = 'professional', $template = '')
{
	$is_single = 1 === count($items);

	$source_text = '';
	$index       = 1;
	foreach ($items as $item) {
		$content = ! empty($item['content']) ? $item['content'] : ($item['description'] ?? '');
		if ($is_single) {
			$source_text = sprintf("原文标题：%s\n\n原文正文：\n%s", $item['title'], $content);
		} else {
			$source_text .= sprintf("【素材 %d】%s\n%s\n\n", $index, $item['title'], $content);
		}
		++$index;
	}

	if (! empty($template)) {
		return str_replace(
			array('{source_content}', '{tone}'),
			array($source_text, $tone),
			$template
		);
	}

	if ($is_single) {
		// 洗稿单文模式：只围绕一篇原文重写，避免把多个不相关主题硬凑在一起。
		$prompt  = "你是一个专业的中文内容编辑。请基于下面这一篇原文，撰写一篇全新的、原创的中文博客文章。\n\n";
		$prompt .= "核心要求：\n";
		$prompt .= "- 全文只围绕原文这一个主题展开，不要引入原文没提到的其他新闻/事件/人物\n";
		$prompt .= "- 不要逐句照抄原文，要用自己的语言重新组织表达\n";
		$prompt .= "- 必须保留原文的关键事实：人物、地点、时间、数据、引述等，不得编造\n";
		$prompt .= "- 文章语气：{$tone}\n";
		$prompt .= "- 结构清晰：引言、3–5 个小节（每节带 <h2>/<h3> 标题）、结论\n";
		$prompt .= "- 字数控制在 800–1500 字\n\n";
		$prompt .= $source_text;
	} else {
		$prompt  = "你是一个专业的中文内容编辑。请根据以下采集到的素材内容，撰写一篇全新的、原创的中文博客文章。\n\n";
		$prompt .= "要求：\n";
		$prompt .= "- 不要直接复制素材内容，而是理解、总结并以全新的角度重新表达\n";
		$prompt .= "- 文章语气：{$tone}\n";
		$prompt .= "- 文章结构清晰，包含标题、引言、正文（多个小节）和结论\n";
		$prompt .= "- 内容要有深度和价值，适合博客发布\n";
		$prompt .= "- 文章长度在 800-1500 字之间\n\n";
		$prompt .= "以下是采集到的素材内容：\n\n";
		$prompt .= $source_text;
	}

	return $prompt;
}

/**
 * 给一条 item 补齐正文和配图（原地 enrich），用于 rss/news_search/trending 等需要去原文页再抓一次的来源。
 *
 * @param array $item 单条素材，需要有 link 字段。
 * @return array       enrich 后的 item（至少包含 images 键，可能为空数组）。
 */
function abcc_enrich_source_item_with_article($item)
{
	if (! isset($item['images']) || ! is_array($item['images'])) {
		$item['images'] = array();
	}
	if (empty($item['link'])) {
		return $item;
	}

	$article = abcc_fetch_webpage_article_cached($item['link']);
	if (is_wp_error($article) || ! is_array($article)) {
		return $item;
	}

	$full = isset($article['text']) ? (string) $article['text'] : '';
	if ('' !== $full) {
		$current_len = mb_strlen((string) ($item['content'] ?? $item['description'] ?? ''));
		if (mb_strlen($full) > $current_len) {
			// 截断到 8000 字符，既保证够 AI 洗稿又不爆 token 预算。
			$item['content']     = mb_substr($full, 0, 8000);
			$item['description'] = mb_substr($full, 0, 500);
		}
	}
	if (! empty($article['images']) && is_array($article['images'])) {
		// 原文页抓到的图通常是正文插图，排前面；合并 RSS enclosures/trending 自带图。
		$item['images'] = array_values(array_unique(array_merge(
			$article['images'],
			$item['images']
		)));
	}

	return $item;
}

/**
 * 从候选素材池中挑选一条作为本次洗稿的主素材。
 *
 * 策略：按顺序遍历 items，为每条 item 抓原文正文 + 图片，**优先挑选有可用图片的条目**。
 * 这样热点/RSS/新闻搜索中那些没配图的条目不会被选中，保证最终发布文章都带图片。
 * 若所有候选都没图，仍按顺序退回首条（让 AI 生图逻辑接手）。
 *
 * @param array $items  候选素材数组。
 * @param array $source 来源配置（用于判断 type）。
 * @return array|WP_Error 单个 item，或失败时 WP_Error。
 */
function abcc_pick_primary_source_item($items, $source)
{
	if (empty($items)) {
		return new WP_Error('no_items', __('未获取到素材。', 'automated-blog-content-creator'));
	}

	$type              = $source['type'] ?? 'rss';
	$needs_article_fetch = in_array($type, array('rss', 'news_search', 'trending'), true);

	$first_enriched = null;

	foreach ($items as $idx => $candidate) {
		if (! isset($candidate['images']) || ! is_array($candidate['images'])) {
			$candidate['images'] = array();
		}

		if ($needs_article_fetch) {
			$candidate = abcc_enrich_source_item_with_article($candidate);
		}

		// 第一条若没图也先记下，作为兜底 fallback。
		if (null === $first_enriched) {
			$first_enriched = $candidate;
		}

		if (! empty($candidate['images'])) {
			return $candidate;
		}
	}

	// 所有候选都没图 —— 退回第一条，让 AI 生成特色图。
	return $first_enriched;
}

/**
 * Sideload a remote image as a WP attachment belonging to $post_id.
 *
 * Return includes both attachment ID and the resulting local URL, so the caller
 * can embed the image into post_content via a Gutenberg image block.
 *
 * @param string $url      Remote image URL.
 * @param int    $post_id  Parent post ID.
 * @param string $alt_text Optional alt text.
 * @return array|false     ['attachment_id'=>int, 'url'=>string] or false on failure.
 */
function abcc_sideload_source_image($url, $post_id, $alt_text = '')
{
	if (empty($url) || ! is_string($url)) {
		return false;
	}

	try {
		if (! function_exists('media_sideload_image')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image($url, $post_id, null, 'id');
		if (is_wp_error($attachment_id) || empty($attachment_id)) {
			return false;
		}

		if (! empty($alt_text)) {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
		}

		$src = wp_get_attachment_image_url($attachment_id, 'large');
		if (empty($src)) {
			$src = wp_get_attachment_url($attachment_id);
		}

		return array(
			'attachment_id' => (int) $attachment_id,
			'url'           => (string) $src,
		);
	} catch (Exception $e) {
		error_log('Source image sideload error: ' . $e->getMessage());
		return false;
	}
}

/**
 * Build a Gutenberg image block string for injection into post_content.
 */
function abcc_build_image_block($attachment_id, $url, $alt_text = '')
{
	$alt   = esc_attr($alt_text);
	$src   = esc_url($url);
	$id    = (int) $attachment_id;
	$attrs = wp_json_encode(array(
		'id'              => $id,
		'sizeSlug'        => 'large',
		'linkDestination' => 'none',
	));

	return "<!-- wp:image {$attrs} -->\n"
		. '<figure class="wp-block-image size-large">'
		. "<img src=\"{$src}\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/>"
		. "</figure>\n<!-- /wp:image -->";
}

/**
 * Inject image blocks into post_content at natural break points (before H2 sections).
 *
 * 策略：把图片块插在第 2、第 4 个 <h2> 之前，若不够就按段落间隔插入；
 * 避免一股脑挤在开头或结尾。
 *
 * @param string   $content      已渲染好的 post_content（Gutenberg 块字符串）。
 * @param string[] $image_blocks 预先构造好的 image block HTML 数组。
 * @return string 注入图片后的 post_content。
 */
function abcc_inject_images_into_content($content, $image_blocks)
{
	if (empty($image_blocks) || '' === $content) {
		return $content;
	}

	// 用 H2 块边界切分。
	$parts = preg_split('/(<!-- wp:heading -->\s*<h2[^>]*>.*?<\/h2>\s*<!-- \/wp:heading -->)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
	if (! is_array($parts) || count($parts) < 3) {
		// 没有足够的 H2：直接在内容开头插一张。
		return $image_blocks[0] . "\n\n" . $content;
	}

	$h2_indexes = array();
	foreach ($parts as $i => $chunk) {
		if (preg_match('/^<!-- wp:heading -->\s*<h2/', $chunk)) {
			$h2_indexes[] = $i;
		}
	}

	if (empty($h2_indexes)) {
		return $image_blocks[0] . "\n\n" . $content;
	}

	// 选几个插入点：第 2、第 4、第 6 个 H2 之前（如果存在）。
	$target_positions = array();
	foreach (array(1, 3, 5) as $nth) {
		if (isset($h2_indexes[$nth])) {
			$target_positions[] = $h2_indexes[$nth];
		}
	}
	if (empty($target_positions)) {
		// 只有 1 个 H2：插在它之前。
		$target_positions[] = $h2_indexes[0];
	}

	$img_idx = 0;
	foreach ($target_positions as $pos) {
		if (! isset($image_blocks[$img_idx])) {
			break;
		}
		$parts[$pos] = $image_blocks[$img_idx] . "\n\n" . $parts[$pos];
		$img_idx++;
	}

	return implode('', $parts);
}

/**
 * Generate a post from a content source.
 *
 * @param int   $source_index Index of the source in abcc_content_sources.
 * @param array $options      Additional options (model, tone, category, etc.).
 * @return int|WP_Error Post ID on success, or WP_Error on failure.
 */
function abcc_generate_post_from_source($source_index, $options = array())
{
	$sources = abcc_get_content_sources();

	if (! isset($sources[$source_index])) {
		return new WP_Error('invalid_source', __('无效的内容来源。', 'automated-blog-content-creator'));
	}

	$source = $sources[$source_index];

	// Fetch content.
	$items = abcc_fetch_source_content($source);
	if (is_wp_error($items)) {
		return $items;
	}
	if (empty($items)) {
		return new WP_Error('no_content', __('未从来源采集到内容。', 'automated-blog-content-creator'));
	}

	// 选一条主素材：整批 items 只是候选池 + 去重记忆，真正写稿只用这一条。
	$primary_item = abcc_pick_primary_source_item($items, $source);
	if (is_wp_error($primary_item)) {
		return $primary_item;
	}

	// Build the prompt.
	$tone       = isset($options['tone']) ? $options['tone'] : abcc_get_setting('openai_tone', 'default');
	$prompt     = abcc_build_source_prompt(array($primary_item), $tone);
	$prompt    .= ABCC_CONTENT_FORMAT_REQUIREMENTS;

	// Brand Kit — 追加品牌植入引导。
	if (function_exists('abcc_build_brand_prompt_suffix')) {
		$prompt .= abcc_build_brand_prompt_suffix();
	}

	// Get AI parameters.
	$model      = isset($options['model']) ? $options['model'] : abcc_get_setting('prompt_select', 'gpt-4.1-mini-2025-04-14');
	$char_limit = isset($options['char_limit']) ? (int) $options['char_limit'] : (int) abcc_get_setting('openai_char_limit', 200);
	$category   = isset($options['category']) ? (int) $options['category'] : (int) ($source['category'] ?? 0);
	$post_type  = isset($options['post_type']) && post_type_exists($options['post_type']) ? $options['post_type'] : 'post';

	// Resolve API key.
	$provider = abcc_get_model_provider($model);
	$api_key  = abcc_get_provider_api_key($provider);
	if (empty($api_key)) {
		return new WP_Error('no_api_key', __('API 密钥未配置。', 'automated-blog-content-creator'));
	}

	// Generate title first — 和正文一致，基于主素材生成，避免标题跨主题。
	$title_prompt  = '根据以下内容，生成一个有吸引力的中文博客文章标题：' . mb_substr($primary_item['title'] . ' ' . ($primary_item['description'] ?? ''), 0, 200) . "\n";
	$title_prompt .= '重要：请只输出标题本身，不要包含任何解释、前缀、问候语、编号或其他多余内容。直接输出标题文字即可。';
	// 推理型模型（o-series / gpt-5 / sonar-reasoning / gemini thinking）会在 reasoning 阶段消耗
	// 大量 token；低预算极易在输出前就被吃光。Perplexity sonar 至少 400 才能稳定返回。
	$title_tokens = 200;
	if (0 === strpos((string) $model, 'sonar')) {
		$title_tokens = 400;
	}
	$title_result = abcc_generate_content($api_key, $title_prompt, $model, $title_tokens);

	$title = '';
	if (false !== $title_result && ! empty($title_result)) {
		$title = abcc_sanitize_ai_title($title_result);
	}

	// AI 失败或洗完是空串：直接用源条目的原标题兜底，别整篇中断。
	if ('' === $title) {
		$title_error = function_exists('abcc_last_ai_error') ? abcc_last_ai_error() : '';
		$fallback_title = isset($primary_item['title']) ? trim((string) $primary_item['title']) : '';
		if ('' === $fallback_title) {
			return new WP_Error(
				'title_failed',
				sprintf(
					/* translators: %s is the raw provider error message. */
					__('标题生成失败（源条目缺少标题且 AI 返回空）。底层错误：%s', 'automated-blog-content-creator'),
					'' !== $title_error ? $title_error : __('未知错误', 'automated-blog-content-creator')
				)
			);
		}
		error_log(sprintf(
			'[WP-AutoInsight] abcc_generate_post_from_source: AI title empty from model "%s" (%s); falling back to source title "%s".',
			$model,
			'' !== $title_error ? $title_error : 'no detail',
			$fallback_title
		));
		$title = $fallback_title;
	}

	// Generate content.
	$content_array = abcc_generate_content($api_key, $prompt, $model, max($char_limit, 800));
	if (false === $content_array || empty($content_array)) {
		$content_error = function_exists('abcc_last_ai_error') ? abcc_last_ai_error() : '';
		return new WP_Error(
			'content_failed',
			sprintf(
				/* translators: %s is the raw provider error message. */
				__('内容生成失败。底层错误：%s', 'automated-blog-content-creator'),
				'' !== $content_error ? $content_error : __('未知错误（请开启 WP_DEBUG_LOG 查看 wp-content/debug.log）', 'automated-blog-content-creator')
			)
		);
	}

	$content_array = array_filter(
		array_map('trim', $content_array),
		function ($line) {
			return ! empty($line) && false === strpos($line, '<title>');
		}
	);

	if (empty($content_array)) {
		return new WP_Error('empty_content', __('生成的内容为空。', 'automated-blog-content-creator'));
	}

	// Brand Kit — 后处理注入品牌。
	if (function_exists('abcc_apply_brand_to_content')) {
		$content_array = abcc_apply_brand_to_content($content_array);
	}

	$format_content = abcc_create_blocks($content_array);
	$post_content   = abcc_gutenberg_blocks($format_content);

	$is_draft_first = abcc_get_setting('abcc_draft_first', true);

	// 质量闸门：查重 + 评分。关键词只取主素材的标题，不混入其他候选。
	$quality_evaluation = null;
	if (function_exists('abcc_quality_is_enabled') && abcc_quality_is_enabled()) {
		$source_keywords    = array((string) $primary_item['title']);
		$quality_evaluation = abcc_quality_evaluate($title, $post_content, $source_keywords);
		if (! $quality_evaluation['passed']) {
			return new WP_Error('quality_gate_blocked', '质量闸门拦截：' . implode('；', $quality_evaluation['reasons']));
		}
		if ($quality_evaluation['force_draft']) {
			$is_draft_first = true;
		}
	}

	$post_data = array(
		'post_title'    => $title,
		'post_content'  => wp_kses_post($post_content),
		'post_status'   => $is_draft_first ? 'draft' : 'publish',
		'post_author'   => get_current_user_id() ?: 1,
		'post_type'     => $post_type,
		'post_category' => $category ? array($category) : array(),
		'meta_input'    => array(
			'_abcc_generated'           => '1',
			'_abcc_model'               => $model,
			'_abcc_source'              => $source['name'],
			'_abcc_source_url'          => $source['url'],
			'_abcc_source_type'         => $source['type'],
			'_abcc_source_fingerprints' => wp_json_encode(array(abcc_source_item_fingerprint($primary_item))),
			'_abcc_source_item_title'   => (string) $primary_item['title'],
			'_abcc_source_item_link'    => (string) ($primary_item['link'] ?? ''),
		),
	);

	// Generate SEO data if enabled. 关键词只喂主素材，避免 SEO 描述跨主题。
	$generate_seo = abcc_get_setting('openai_generate_seo', true) && 'none' !== abcc_get_active_seo_plugin();
	if ($generate_seo) {
		$keywords   = array((string) $primary_item['title']);
		$seo_result = abcc_generate_title_and_seo($api_key, $keywords, $model, array(
			'site_name'        => get_bloginfo('name'),
			'site_description' => get_bloginfo('description'),
		));
		if (! empty($seo_result['seo_data'])) {
			$post_data['meta_input'] = array_merge($post_data['meta_input'], abcc_get_seo_meta_fields($seo_result['seo_data']));
		}
	}

	$post_id = wp_insert_post($post_data, true);

	if (is_wp_error($post_id)) {
		return $post_id;
	}

	// 写入质量评分 meta。
	if ($quality_evaluation && function_exists('abcc_quality_attach_meta')) {
		abcc_quality_attach_meta($post_id, $quality_evaluation, $title, $post_content);
	}

	// 标记采集 items 为已使用：只标记本次真正用掉的主素材，其余候选保留下次可用。
	if (abcc_get_setting('abcc_source_dedupe_enabled', true)) {
		abcc_source_mark_item_used(abcc_source_item_fingerprint($primary_item));
	}

	// 优先使用原文带的图片：第一张做特色图，后续 2 张插入正文。
	$used_source_featured = false;
	$source_images        = isset($primary_item['images']) && is_array($primary_item['images']) ? $primary_item['images'] : array();

	if (! empty($source_images)) {
		$alt_text = abcc_build_featured_image_alt_text($title, '');

		// 第一张：特色图。
		$featured_url = array_shift($source_images);
		$featured_att = abcc_set_featured_image($post_id, $featured_url, $alt_text);
		if ($featured_att) {
			$used_source_featured = true;
		}

		// 后续最多 2 张：正文插图。
		$inline_blocks = array();
		$inline_limit  = 2;
		foreach ($source_images as $img_url) {
			if (count($inline_blocks) >= $inline_limit) {
				break;
			}
			$sideloaded = abcc_sideload_source_image($img_url, $post_id, $alt_text);
			if (! $sideloaded) {
				continue;
			}
			$inline_blocks[] = abcc_build_image_block(
				$sideloaded['attachment_id'],
				$sideloaded['url'],
				$alt_text
			);
		}

		if (! empty($inline_blocks)) {
			$new_content = abcc_inject_images_into_content($post_content, $inline_blocks);
			if ($new_content !== $post_content) {
				wp_update_post(array(
					'ID'           => $post_id,
					'post_content' => wp_kses_post($new_content),
				));
			}
		}
	}

	// 若原文没有可用图片，再退回 AI 生成特色图。
	if (! $used_source_featured && abcc_get_setting('openai_generate_images', true)) {
		try {
			$image_result = abcc_generate_featured_image($model, array($title), array());
			if ($image_result) {
				$alt_text = abcc_build_featured_image_alt_text($title, '');

				if (is_array($image_result) && ! empty($image_result['attachment_id'])) {
					// Media library image — set thumbnail directly.
					set_post_thumbnail($post_id, $image_result['attachment_id']);
				} elseif (is_string($image_result)) {
					// AI-generated URL — download and attach.
					abcc_set_featured_image($post_id, $image_result, $alt_text);
				}
			}
		} catch (Exception $e) {
			// Image failures should not abort text generation.
			unset($e);
		}
	}

	// Send notification if enabled.
	if (abcc_get_setting('openai_email_notifications', false)) {
		abcc_send_post_notification($post_id);
	}

	return $post_id;
}

/**
 * Generate posts from all enabled content sources (used by scheduler).
 *
 * @return array Array of results (post IDs or WP_Error objects).
 */
function abcc_generate_posts_from_all_sources()
{
	$sources = abcc_get_content_sources();
	$results = array();

	foreach ($sources as $index => $source) {
		if (empty($source['enabled'])) {
			continue;
		}

		$result          = abcc_generate_post_from_source($index);
		$results[]       = array(
			'source' => $source['name'],
			'result' => $result,
		);
	}

	return $results;
}

/**
 * AJAX handler: Save content sources.
 */
function abcc_ajax_save_sources()
{
	check_ajax_referer('abcc_nonce', 'nonce');

	if (! current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('权限不足。', 'automated-blog-content-creator')));
	}

	$raw_sources = isset($_POST['sources']) ? wp_unslash($_POST['sources']) : array();
	$sources     = array();

	if (is_array($raw_sources)) {
		foreach ($raw_sources as $src) {
			$url  = isset($src['url']) ? esc_url_raw(trim($src['url'])) : '';
			$type = isset($src['type']) && in_array($src['type'], array('rss', 'webpage', 'trending', 'news_search', 'ai_search'), true) ? $src['type'] : 'rss';

			// trending / news_search / ai_search 不需要 URL，只有 rss/webpage 要求 URL。
			if (empty($url) && 'trending' !== $type && 'news_search' !== $type && 'ai_search' !== $type) {
				continue;
			}

			$entry = array(
				'name'     => isset($src['name']) ? sanitize_text_field($src['name']) : '',
				'url'      => $url,
				'type'     => $type,
				'category' => isset($src['category']) ? absint($src['category']) : 0,
				'enabled'  => isset($src['enabled']) ? (bool) $src['enabled'] : true,
			);

			if ('trending' === $type) {
				$entry['platform'] = isset($src['platform']) && in_array($src['platform'], array('baidu', 'toutiao', 'zhihu', 'merged'), true) ? $src['platform'] : 'baidu';
			}

			if ('news_search' === $type) {
				$query = isset($src['query']) ? sanitize_text_field($src['query']) : '';
				if ('' === $query) {
					continue; // 没关键词的 news_search 条目直接丢弃。
				}
				$entry['query']    = $query;
				$entry['language'] = isset($src['language']) ? sanitize_text_field($src['language']) : 'zh-CN';
				$entry['region']   = isset($src['region']) ? sanitize_text_field($src['region']) : 'CN';
			}

			if ('ai_search' === $type) {
				$query = isset($src['query']) ? sanitize_text_field($src['query']) : '';
				if ('' === $query) {
					continue; // 没主题的 ai_search 条目直接丢弃。
				}
				$ai_model = isset($src['ai_model']) ? sanitize_text_field($src['ai_model']) : 'sonar';
				if (! in_array($ai_model, array('sonar', 'sonar-pro', 'sonar-reasoning-pro'), true)) {
					$ai_model = 'sonar';
				}
				$entry['query']    = $query;
				$entry['ai_model'] = $ai_model;
			}

			$sources[] = $entry;
		}
	}

	abcc_save_content_sources($sources);
	wp_send_json_success(array('message' => __('来源保存成功。', 'automated-blog-content-creator')));
}
add_action('wp_ajax_abcc_save_sources', 'abcc_ajax_save_sources');

/**
 * AJAX handler: Preview content from a source.
 */
function abcc_ajax_fetch_source_preview()
{
	check_ajax_referer('abcc_nonce', 'nonce');

	if (! current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('权限不足。', 'automated-blog-content-creator')));
	}

	$url      = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
	$type     = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'rss';
	$platform = isset($_POST['platform']) ? sanitize_key(wp_unslash($_POST['platform'])) : 'baidu';
	$query    = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';
	$language = isset($_POST['language']) ? sanitize_text_field(wp_unslash($_POST['language'])) : 'zh-CN';
	$region   = isset($_POST['region']) ? sanitize_text_field(wp_unslash($_POST['region'])) : 'CN';
	$ai_model = isset($_POST['ai_model']) ? sanitize_text_field(wp_unslash($_POST['ai_model'])) : 'sonar';

	if (empty($url) && 'trending' !== $type && 'news_search' !== $type && 'ai_search' !== $type) {
		wp_send_json_error(array('message' => __('URL 不能为空。', 'automated-blog-content-creator')));
	}
	if ('news_search' === $type && '' === $query) {
		wp_send_json_error(array('message' => __('Google 新闻搜索需要填写关键词。', 'automated-blog-content-creator')));
	}
	if ('ai_search' === $type && '' === $query) {
		wp_send_json_error(array('message' => __('Perplexity AI 研究需要填写主题。', 'automated-blog-content-creator')));
	}

	$source = array(
		'name'     => 'Preview',
		'url'      => $url,
		'type'     => $type,
		'platform' => $platform,
		'query'    => $query,
		'language' => $language,
		'region'   => $region,
		'ai_model' => $ai_model,
	);

	$items = abcc_fetch_source_content($source);

	if (is_wp_error($items)) {
		wp_send_json_error(array('message' => $items->get_error_message()));
	}

	wp_send_json_success(array('items' => $items));
}
add_action('wp_ajax_abcc_fetch_source_preview', 'abcc_ajax_fetch_source_preview');

/**
 * AJAX handler: Generate a post from a specific source.
 *
 * 走后台 job 队列异步生成——采集+AI洗稿+配图一轮常常需要 30–120s，
 * 同步 AJAX 容易被 PHP/Nginx/CDN 截成 504/断流，前端只能看到"网络错误"。
 * 入队后立即返回 job_id，前端轮询 abcc_get_job_status 读进度。
 */
function abcc_ajax_generate_from_source()
{
	check_ajax_referer('abcc_nonce', 'nonce');

	if (! current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('权限不足。', 'automated-blog-content-creator')));
		return;
	}

	$source_index = isset($_POST['source_index']) ? absint($_POST['source_index']) : -1;

	if ($source_index < 0) {
		wp_send_json_error(array('message' => __('无效的来源索引。', 'automated-blog-content-creator')));
		return;
	}

	$sources = abcc_get_content_sources();
	if (! isset($sources[$source_index])) {
		wp_send_json_error(array('message' => __('无效的来源索引。', 'automated-blog-content-creator')));
		return;
	}

	$payload = abcc_build_generation_payload(array(
		'source'       => 'manual',
		'source_index' => $source_index,
		'category'     => (int) ($sources[$source_index]['category'] ?? 0),
	));

	$job_id = abcc_queue_generation_job($payload);

	if (is_wp_error($job_id)) {
		wp_send_json_error(array('message' => $job_id->get_error_message()));
		return;
	}

	wp_send_json_success(array(
		'message' => __('已加入生成队列，正在后台采集并洗稿…', 'automated-blog-content-creator'),
		'job_id'  => $job_id,
	));
}
add_action('wp_ajax_abcc_generate_from_source', 'abcc_ajax_generate_from_source');
