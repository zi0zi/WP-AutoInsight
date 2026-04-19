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

	$api_key = abcc_resolve_api_key('perplexity');
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
		$result[] = array(
			'title'       => $item->get_title(),
			'link'        => $item->get_permalink(),
			'description' => wp_strip_all_tags($item->get_description()),
			'content'     => wp_strip_all_tags($item->get_content()),
			'date'        => $item->get_date('Y-m-d H:i:s'),
		);
	}

	return $result;
}

/**
 * Fetch and extract main text content from a webpage.
 *
 * @param string $url Webpage URL.
 * @return string|WP_Error Extracted text or WP_Error on failure.
 */
function abcc_fetch_webpage_content($url)
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

	// Extract text from <article>, <main>, or <body>.
	$text = '';
	if (preg_match('/<article[^>]*>(.*?)<\/article>/si', $body, $m)) {
		$text = $m[1];
	} elseif (preg_match('/<main[^>]*>(.*?)<\/main>/si', $body, $m)) {
		$text = $m[1];
	} elseif (preg_match('/<body[^>]*>(.*?)<\/body>/si', $body, $m)) {
		$text = $m[1];
	} else {
		$text = $body;
	}

	// Remove scripts & styles.
	$text = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $text);
	$text = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $text);

	// Strip tags and normalize whitespace.
	$text = wp_strip_all_tags($text);
	$text = preg_replace('/\s+/', ' ', $text);
	$text = trim($text);

	// Limit to ~3000 characters to stay within prompt size constraints.
	if (mb_strlen($text) > 3000) {
		$text = mb_substr($text, 0, 3000) . '...';
	}

	return $text;
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
		$text = abcc_fetch_webpage_content_cached($source['url']);
		if (is_wp_error($text)) {
			return $text;
		}
		$items = array(
			array(
				'title'       => $source['name'],
				'link'        => $source['url'],
				'description' => $text,
				'content'     => $text,
				'date'        => current_time('Y-m-d H:i:s'),
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
	$source_text = '';
	$index       = 1;
	foreach ($items as $item) {
		$content      = ! empty($item['content']) ? $item['content'] : $item['description'];
		$source_text .= sprintf("【素材 %d】%s\n%s\n\n", $index, $item['title'], $content);
		++$index;
	}

	if (! empty($template)) {
		$prompt = str_replace(
			array('{source_content}', '{tone}'),
			array($source_text, $tone),
			$template
		);
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

	// Build the prompt.
	$tone       = isset($options['tone']) ? $options['tone'] : abcc_get_setting('openai_tone', 'default');
	$prompt     = abcc_build_source_prompt($items, $tone);
	$prompt    .= ABCC_CONTENT_FORMAT_REQUIREMENTS;

	// Brand Kit — 追加品牌植入引导。
	if (function_exists('abcc_build_brand_prompt_suffix')) {
		$prompt .= abcc_build_brand_prompt_suffix();
	}

	// Get AI parameters.
	$model      = isset($options['model']) ? $options['model'] : abcc_get_setting('prompt_select', 'gpt-4.1-mini-2025-04-14');
	$char_limit = isset($options['char_limit']) ? (int) $options['char_limit'] : (int) abcc_get_setting('openai_char_limit', 200);
	$category   = isset($options['category']) ? (int) $options['category'] : (int) ($source['category'] ?? 0);

	// Resolve API key.
	$provider = abcc_get_model_provider($model);
	$api_key  = abcc_resolve_api_key($provider);
	if (empty($api_key)) {
		return new WP_Error('no_api_key', __('API 密钥未配置。', 'automated-blog-content-creator'));
	}

	// Generate title first.
	$first_item    = $items[0];
	$title_prompt  = '根据以下内容，生成一个有吸引力的中文博客文章标题：' . mb_substr($first_item['title'] . ' ' . $first_item['description'], 0, 200) . "\n";
	$title_prompt .= '重要：请只输出标题本身，不要包含任何解释、前缀、问候语、编号或其他多余内容。直接输出标题文字即可。';
	$title_result  = abcc_generate_content($api_key, $title_prompt, $model, 50);

	if (false === $title_result || empty($title_result)) {
		return new WP_Error('title_failed', __('标题生成失败。', 'automated-blog-content-creator'));
	}

	$title = abcc_sanitize_ai_title($title_result);

	// Generate content.
	$content_array = abcc_generate_content($api_key, $prompt, $model, max($char_limit, 800));
	if (false === $content_array || empty($content_array)) {
		return new WP_Error('content_failed', __('内容生成失败。', 'automated-blog-content-creator'));
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

	$is_draft_first    = abcc_get_setting('abcc_draft_first', true);
	$is_random_publish = abcc_get_setting('abcc_random_publish', false);

	// 质量闸门：查重 + 评分。
	$quality_evaluation = null;
	if (function_exists('abcc_quality_is_enabled') && abcc_quality_is_enabled()) {
		$source_keywords    = array_slice(array_map(function ($i) {
			return $i['title'];
		}, $items), 0, 3);
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
		'post_status'   => ($is_draft_first || $is_random_publish) ? 'draft' : 'publish',
		'post_author'   => get_current_user_id() ?: 1,
		'post_type'     => 'post',
		'post_category' => $category ? array($category) : array(),
		'meta_input'    => array(
			'_abcc_generated'           => '1',
			'_abcc_model'               => $model,
			'_abcc_source'              => $source['name'],
			'_abcc_source_url'          => $source['url'],
			'_abcc_source_type'         => $source['type'],
			'_abcc_source_fingerprints' => wp_json_encode(array_map('abcc_source_item_fingerprint', $items)),
		),
	);

	// Generate SEO data if enabled.
	$generate_seo = abcc_get_setting('openai_generate_seo', true) && 'none' !== abcc_get_active_seo_plugin();
	if ($generate_seo) {
		$keywords   = array_slice(array_map(function ($i) {
			return $i['title'];
		}, $items), 0, 3);
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

	// 标记采集 items 为已使用，下次 fetch 时会被过滤。
	if (abcc_get_setting('abcc_source_dedupe_enabled', true)) {
		foreach ($items as $used_item) {
			abcc_source_mark_item_used(abcc_source_item_fingerprint($used_item));
		}
	}

	// Schedule random-time publishing if enabled (and not in review-first mode).
	if (! $is_draft_first && $is_random_publish) {
		abcc_schedule_random_publish($post_id);
	}

	// Generate featured image if enabled.
	if (abcc_get_setting('openai_generate_images', true)) {
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
 */
function abcc_ajax_generate_from_source()
{
	check_ajax_referer('abcc_nonce', 'nonce');

	if (! current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('权限不足。', 'automated-blog-content-creator')));
	}

	$source_index = isset($_POST['source_index']) ? absint($_POST['source_index']) : -1;

	if ($source_index < 0) {
		wp_send_json_error(array('message' => __('无效的来源索引。', 'automated-blog-content-creator')));
	}

	$post_id = abcc_generate_post_from_source($source_index);

	if (is_wp_error($post_id)) {
		wp_send_json_error(array('message' => $post_id->get_error_message()));
	}

	wp_send_json_success(
		array(
			'message' => __('从来源生成文章成功！', 'automated-blog-content-creator'),
			'post_id' => $post_id,
			'edit_url' => get_edit_post_link($post_id, ''),
		)
	);
}
add_action('wp_ajax_abcc_generate_from_source', 'abcc_ajax_generate_from_source');
