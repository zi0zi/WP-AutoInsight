<?php

/**
 * Brand Kit — 品牌推广关键词与品牌卡片注入
 *
 * 将品牌词、品牌链接、品牌介绍作为「辅助广告位」注入到 AI 生成的文章中，
 * 用于提升品牌搜索收录与站内品牌锚文本曝光。
 *
 * 核心原则：
 * - 品牌词与主题词 (keyword groups) 解耦，避免稀释文章主题。
 * - 自然融入：优先在 prompt 阶段引导 AI 顺势提及品牌，而非机械替换。
 * - 兜底注入：若 AI 未按指令提及品牌，在正文首段自动补充一句带链接的品牌提及。
 * - 文末品牌卡片：统一样式的 CTA 卡片，独立于正文之外。
 *
 * @package WP-AutoInsight
 * @since 3.9.0
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * 读取当前品牌配置并做一次标准化。
 *
 * @return array 配置数组（已标准化字段类型）。
 */
function abcc_get_brand_config()
{
	$brand_keywords = abcc_get_setting('abcc_brand_keywords', array());
	if (is_string($brand_keywords)) {
		$brand_keywords = array_filter(array_map('trim', explode("\n", $brand_keywords)));
	}
	$brand_keywords = array_values(array_filter(array_map('trim', (array) $brand_keywords)));

	return array(
		'enabled'          => (bool) abcc_get_setting('abcc_brand_enabled', false),
		'name'             => trim((string) abcc_get_setting('abcc_brand_name', '')),
		'url'              => trim((string) abcc_get_setting('abcc_brand_url', '')),
		'blurb'            => trim((string) abcc_get_setting('abcc_brand_blurb', '')),
		'keywords'         => $brand_keywords,
		'cta_text'         => trim((string) abcc_get_setting('abcc_brand_cta_text', '')),
		'body_mentions'    => max(0, (int) abcc_get_setting('abcc_brand_body_mentions', 2)),
		'link_rel'         => (string) abcc_get_setting('abcc_brand_link_rel', 'dofollow'),
		'first_link_only'  => (bool) abcc_get_setting('abcc_brand_first_link_only', true),
		'footer_card'      => (bool) abcc_get_setting('abcc_brand_footer_card', true),
	);
}

/**
 * 判断 Brand Kit 是否已启用且配置可用（至少有品牌名）。
 *
 * @return bool
 */
function abcc_brand_is_active()
{
	$cfg = abcc_get_brand_config();
	return $cfg['enabled'] && '' !== $cfg['name'];
}

/**
 * 生成 prompt 后缀，引导 AI 自然提及品牌。
 *
 * 挂到 prompt 末尾（在 ABCC_CONTENT_FORMAT_REQUIREMENTS 之前/之后均可）。
 *
 * @return string 若未启用返回空字符串。
 */
function abcc_build_brand_prompt_suffix()
{
	if (! abcc_brand_is_active()) {
		return '';
	}

	$cfg      = abcc_get_brand_config();
	$mentions = $cfg['body_mentions'];

	if ($mentions <= 0) {
		return '';
	}

	$parts   = array();
	$parts[] = "\n\n品牌植入要求（重要）：";
	$parts[] = sprintf('- 在文章中自然提及品牌「%s」约 %d 次，不要堆砌', $cfg['name'], $mentions);
	if ('' !== $cfg['blurb']) {
		$parts[] = sprintf('- 品牌简介（可作为参考背景，但不要照搬整句）：%s', $cfg['blurb']);
	}
	if (! empty($cfg['keywords'])) {
		$parts[] = sprintf('- 可顺势使用的品牌相关词：%s', implode('、', array_slice($cfg['keywords'], 0, 8)));
	}
	$parts[] = '- 品牌提及必须与上下文内容逻辑相关，作为案例、方案提供方或延伸阅读出现，不要像硬广';
	$parts[] = '- 不要在文章开头第一句就提及品牌，应在正文自然展开后再引入';
	$parts[] = '- 不要自己添加 <a> 链接标签，系统会在后处理阶段自动为品牌词加链接';

	return implode("\n", $parts);
}

/**
 * 后处理：对 AI 已生成的内容数组应用品牌规则。
 *
 * 1. 若 AI 已提及品牌名 → 给首次出现的品牌词加内链锚文本。
 * 2. 若 AI 完全未提及 → 在第 2 段自然句子中补一句兜底提及（带链接）。
 * 3. 根据配置追加文末品牌卡片。
 *
 * @param array $content_array AI 返回的内容行数组。
 * @return array 处理后的内容数组。
 */
function abcc_apply_brand_to_content($content_array)
{
	if (! abcc_brand_is_active() || empty($content_array)) {
		return $content_array;
	}

	$cfg = abcc_get_brand_config();

	// 第一步：给首次出现的品牌名加内链。
	$content_array = abcc_brand_linkify_content($content_array, $cfg);

	// 第二步：若内容中完全没有品牌名，注入兜底提及。
	if ($cfg['body_mentions'] > 0 && ! abcc_brand_content_has_mention($content_array, $cfg)) {
		$content_array = abcc_brand_inject_fallback_mention($content_array, $cfg);
	}

	// 第三步：附加文末品牌卡片。
	if ($cfg['footer_card']) {
		$card = abcc_build_brand_footer_card($cfg);
		if ('' !== $card) {
			$content_array[] = $card;
		}
	}

	return $content_array;
}

/**
 * 检查内容中是否已提及品牌名或任意品牌关键词。
 *
 * @param array $content_array
 * @param array $cfg
 * @return bool
 */
function abcc_brand_content_has_mention($content_array, $cfg)
{
	$haystack = wp_strip_all_tags(implode(' ', $content_array));
	if ('' === $haystack) {
		return false;
	}

	$needles = array($cfg['name']);
	foreach ($cfg['keywords'] as $kw) {
		$needles[] = $kw;
	}

	foreach ($needles as $needle) {
		if ('' === $needle) {
			continue;
		}
		if (false !== mb_stripos($haystack, $needle)) {
			return true;
		}
	}
	return false;
}

/**
 * 给首次（或全部，取决于配置）出现的品牌名加 <a> 链接 + <strong>。
 *
 * 仅在 <p> 段落内做替换，不会动 <h2>/<h3> 等标题，避免破坏 SEO 层级。
 *
 * @param array $content_array
 * @param array $cfg
 * @return array
 */
function abcc_brand_linkify_content($content_array, $cfg)
{
	if ('' === $cfg['name'] || '' === $cfg['url']) {
		return $content_array;
	}

	$rel_attr = abcc_brand_build_rel_attr($cfg['link_rel']);
	$link_tpl = sprintf(
		'<strong><a href="%s" title="%s"%s>$1</a></strong>',
		esc_url($cfg['url']),
		esc_attr($cfg['name']),
		$rel_attr ? ' rel="' . esc_attr($rel_attr) . '"' : ''
	);

	$pattern    = '/(' . preg_quote($cfg['name'], '/') . ')/u';
	$linked     = false;

	foreach ($content_array as $i => $line) {
		// 只对段落做替换，标题与既有 <a>/<strong> 跳过。
		if (0 !== stripos(ltrim($line), '<p')) {
			continue;
		}
		if (false !== stripos($line, '<a ') || false !== stripos($line, 'href=')) {
			// 该段落已含链接，跳过避免嵌套。
			continue;
		}
		if (false === mb_stripos($line, $cfg['name'])) {
			continue;
		}

		$replace_count = $cfg['first_link_only'] ? 1 : -1;
		$line          = preg_replace($pattern, $link_tpl, $line, $replace_count);
		$content_array[$i] = $line;
		$linked            = true;

		if ($cfg['first_link_only']) {
			break;
		}
	}

	return $content_array;
}

/**
 * 兜底：AI 完全没提到品牌时，在第二段正文插入一句自然提及（带链接）。
 *
 * @param array $content_array
 * @param array $cfg
 * @return array
 */
function abcc_brand_inject_fallback_mention($content_array, $cfg)
{
	if ('' === $cfg['name']) {
		return $content_array;
	}

	$rel_attr = abcc_brand_build_rel_attr($cfg['link_rel']);
	$linked   = '' !== $cfg['url']
		? sprintf(
			'<strong><a href="%s" title="%s"%s>%s</a></strong>',
			esc_url($cfg['url']),
			esc_attr($cfg['name']),
			$rel_attr ? ' rel="' . esc_attr($rel_attr) . '"' : '',
			esc_html($cfg['name'])
		)
		: '<strong>' . esc_html($cfg['name']) . '</strong>';

	$sentence = '' !== $cfg['blurb']
		? sprintf('在这一话题上，%s %s，为相关实践提供了参考。', $linked, esc_html($cfg['blurb']))
		: sprintf('在这一领域，%s 也持续在相关方向上投入关注与实践。', $linked);

	$injection = '<p>' . $sentence . '</p>';

	// 找到第 2 个 <p> 段落之前插入；若段落数不足，则追加到末尾。
	$paragraph_count = 0;
	foreach ($content_array as $i => $line) {
		if (0 === stripos(ltrim($line), '<p')) {
			$paragraph_count++;
			if (2 === $paragraph_count) {
				array_splice($content_array, $i + 1, 0, array($injection));
				return $content_array;
			}
		}
	}

	$content_array[] = $injection;
	return $content_array;
}

/**
 * 构造文末品牌卡片 HTML。
 *
 * @param array $cfg
 * @return string
 */
function abcc_build_brand_footer_card($cfg)
{
	if ('' === $cfg['name']) {
		return '';
	}

	$rel_attr = abcc_brand_build_rel_attr($cfg['link_rel']);
	$cta_text = '' !== $cfg['cta_text'] ? $cfg['cta_text'] : sprintf('了解更多关于 %s', $cfg['name']);

	$lines   = array();
	$lines[] = '<div class="abcc-brand-card" style="margin:30px 0;padding:20px 24px;border-left:4px solid #2271b1;background:#f6f7f7;border-radius:4px;">';
	$lines[] = '<p style="margin:0 0 8px;font-size:12px;letter-spacing:0.08em;text-transform:uppercase;color:#646970;">关于 ' . esc_html($cfg['name']) . '</p>';

	if ('' !== $cfg['blurb']) {
		$lines[] = '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#1d2327;">' . esc_html($cfg['blurb']) . '</p>';
	}

	if ('' !== $cfg['url']) {
		$lines[] = sprintf(
			'<p style="margin:0;"><a href="%s"%s style="display:inline-block;padding:8px 16px;background:#2271b1;color:#fff;text-decoration:none;border-radius:3px;font-size:14px;">%s &rarr;</a></p>',
			esc_url($cfg['url']),
			$rel_attr ? ' rel="' . esc_attr($rel_attr) . '"' : '',
			esc_html($cta_text)
		);
	}

	$lines[] = '</div>';

	return implode('', $lines);
}

/**
 * 将 rel 设置值转成 rel 属性字符串。
 *
 * @param string $rel_setting 'dofollow' | 'nofollow' | 'sponsored' | 'ugc'
 * @return string
 */
function abcc_brand_build_rel_attr($rel_setting)
{
	switch ($rel_setting) {
		case 'nofollow':
			return 'nofollow noopener';
		case 'sponsored':
			return 'sponsored noopener';
		case 'ugc':
			return 'ugc noopener';
		case 'dofollow':
		default:
			return ''; // 空字符串 = dofollow，不输出 rel 属性。
	}
}
