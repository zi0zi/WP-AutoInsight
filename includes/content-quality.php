<?php

/**
 * Content Quality — 查重 + 质量评分闸门
 *
 * 两道闸门同时工作：
 *
 * 1. **查重**（在文章入库前）：
 *    - 标题归一化后（去标点、折叠空白、转小写）算指纹
 *    - 内容提取词集合后算 simhash 近似
 *    - 若近期（默认 180 天）存在高相似度文章，则拒绝本次入库（或强制草稿）
 *
 * 2. **质量评分**（在文章入库前）：
 *    - 字数、H2/H3 层级、段落均长、空段落、主题关键词出现率
 *    - 综合评分 0–100
 *    - 低于 quality_min_score 直接失败
 *    - 低于 force_draft_below 强制草稿（不自动发布）
 *
 * 设计原则：
 * - 纯函数，可独立测试；所有结果写回 post meta 便于后续审计
 * - 失败时返回 WP_Error，由调用方决定是重试还是直接返回用户
 *
 * @package WP-AutoInsight
 * @since 3.9.0
 */

if (! defined('ABSPATH')) {
	exit;
}

/**
 * 是否启用质量闸门。
 *
 * @return bool
 */
function abcc_quality_is_enabled()
{
	return (bool) abcc_get_setting('abcc_quality_enabled', true);
}

/**
 * 归一化标题 —— 用于标题查重。
 *
 * 做法：全角转半角、去所有标点、折叠空白、转小写。
 *
 * @param string $title
 * @return string
 */
function abcc_quality_normalize_title($title)
{
	$title = (string) $title;
	$title = function_exists('mb_convert_kana') ? mb_convert_kana($title, 'as', 'UTF-8') : $title;
	$title = mb_strtolower($title, 'UTF-8');
	// 去掉标点与特殊字符，保留中英文与数字。
	$title = preg_replace('/[\p{P}\p{S}\s]+/u', '', $title);
	return (string) $title;
}

/**
 * 生成标题指纹（MD5 of normalized title）。
 *
 * @param string $title
 * @return string
 */
function abcc_quality_title_fingerprint($title)
{
	return md5(abcc_quality_normalize_title($title));
}

/**
 * 简易 simhash —— 用于内容近似查重。
 *
 * 返回 64 位二进制字符串（64 个 0/1 字符）。
 *
 * @param string $text 可含 HTML，函数内部剥离
 * @return string 64-char binary string
 */
function abcc_quality_simhash($text)
{
	$text = wp_strip_all_tags((string) $text);
	// 简单分词：中文按 2 字 bigram，英文数字按空白分。
	$tokens = array();

	// 英文 + 数字按空白切。
	if (preg_match_all('/[a-z0-9]+/i', $text, $m)) {
		foreach ($m[0] as $t) {
			$tokens[] = strtolower($t);
		}
	}
	// 中文按 bigram。
	if (preg_match_all('/[\x{4e00}-\x{9fff}]/u', $text, $m)) {
		$chars = $m[0];
		for ($i = 0, $n = count($chars) - 1; $i < $n; $i++) {
			$tokens[] = $chars[$i] . $chars[$i + 1];
		}
	}

	if (empty($tokens)) {
		return str_repeat('0', 64);
	}

	// 统计词频。
	$freq = array_count_values($tokens);

	$bits = array_fill(0, 64, 0);
	foreach ($freq as $token => $weight) {
		// 每个 token 取 md5 前 16 字符 = 64 bit。
		$hash_hex = substr(md5($token), 0, 16);
		$hash_bin = '';
		for ($i = 0; $i < 16; $i++) {
			$hash_bin .= str_pad(base_convert($hash_hex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
		}
		for ($i = 0; $i < 64; $i++) {
			$bits[$i] += ('1' === $hash_bin[$i]) ? $weight : -$weight;
		}
	}

	$simhash = '';
	for ($i = 0; $i < 64; $i++) {
		$simhash .= $bits[$i] > 0 ? '1' : '0';
	}
	return $simhash;
}

/**
 * 计算两个 simhash 的相似度百分比（汉明距离）。
 *
 * @param string $a 64-char binary
 * @param string $b 64-char binary
 * @return int 0–100
 */
function abcc_quality_simhash_similarity($a, $b)
{
	if (64 !== strlen($a) || 64 !== strlen($b)) {
		return 0;
	}
	$diff = 0;
	for ($i = 0; $i < 64; $i++) {
		if ($a[$i] !== $b[$i]) {
			$diff++;
		}
	}
	return (int) round((1 - $diff / 64) * 100);
}

/**
 * 查重：在近期文章中找是否存在高相似度稿件。
 *
 * @param string $title
 * @param string $content
 * @return array|false 命中返回 ['post_id'=>..., 'similarity'=>..., 'reason'=>...]；未命中返回 false。
 */
function abcc_quality_find_duplicate($title, $content)
{
	if (! abcc_get_setting('abcc_quality_dedupe_enabled', true)) {
		return false;
	}

	$threshold    = (int) abcc_get_setting('abcc_quality_dedupe_threshold', 85);
	$scope_days   = (int) abcc_get_setting('abcc_quality_dedupe_scope_days', 180);
	$title_fp     = abcc_quality_title_fingerprint($title);
	$content_hash = abcc_quality_simhash($content);

	// 1. 标题指纹精确命中直接判重（阈值无关）。
	$exact = get_posts(array(
		'post_type'      => 'any',
		'post_status'    => array('publish', 'draft', 'pending', 'future'),
		'posts_per_page' => 1,
		'date_query'     => array(array('after' => $scope_days . ' days ago')),
		'meta_query'     => array(
			array(
				'key'   => '_abcc_title_fingerprint',
				'value' => $title_fp,
			),
		),
		'fields'         => 'ids',
	));
	if (! empty($exact)) {
		return array(
			'post_id'    => (int) $exact[0],
			'similarity' => 100,
			'reason'     => 'title_fingerprint_exact',
		);
	}

	// 2. simhash 近似命中。
	$candidates = get_posts(array(
		'post_type'      => 'any',
		'post_status'    => array('publish', 'draft', 'pending', 'future'),
		'posts_per_page' => 200,
		'date_query'     => array(array('after' => $scope_days . ' days ago')),
		'meta_query'     => array(
			array(
				'key'     => '_abcc_content_simhash',
				'compare' => 'EXISTS',
			),
		),
		'fields'         => 'ids',
	));

	foreach ($candidates as $pid) {
		$other = (string) get_post_meta($pid, '_abcc_content_simhash', true);
		if (64 !== strlen($other)) {
			continue;
		}
		$sim = abcc_quality_simhash_similarity($content_hash, $other);
		if ($sim >= $threshold) {
			return array(
				'post_id'    => (int) $pid,
				'similarity' => $sim,
				'reason'     => 'content_simhash',
			);
		}
	}

	return false;
}

/**
 * 计算内容质量评分（0–100）。
 *
 * 评分维度：
 * - 字数 (30 pts)：<300=0, 300-600=15, 600-1200=25, >=1200=30
 * - 标题层级 (20 pts)：至少 2 个 H2 拿满；有 H3 加分
 * - 段落结构 (20 pts)：段落数 >=4 且均长 60–400 字符
 * - 空标签/结构异常 (15 pts)：每发现一处扣 3 分
 * - 关键词覆盖 (15 pts)：至少有一个关键词出现在正文中
 *
 * @param string $content  HTML 内容
 * @param array  $keywords 用于覆盖率校验
 * @return array ['score'=>int, 'breakdown'=>array, 'issues'=>array]
 */
function abcc_quality_score_content($content, $keywords = array())
{
	$breakdown = array();
	$issues    = array();

	$plain     = trim(wp_strip_all_tags((string) $content));
	$plain_len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);

	// 字数 (30 pts).
	if ($plain_len >= 1200) {
		$breakdown['length'] = 30;
	} elseif ($plain_len >= 600) {
		$breakdown['length'] = 25;
	} elseif ($plain_len >= 300) {
		$breakdown['length'] = 15;
	} else {
		$breakdown['length'] = 0;
		$issues[]            = sprintf('内容过短（%d 字）', $plain_len);
	}

	// 标题层级 (20 pts).
	$h2_count = preg_match_all('/<h2[^>]*>.*?<\/h2>/si', $content, $m_h2);
	$h3_count = preg_match_all('/<h3[^>]*>.*?<\/h3>/si', $content, $m_h3);
	$heading_score = 0;
	if ($h2_count >= 2) {
		$heading_score += 14;
	} elseif ($h2_count === 1) {
		$heading_score += 7;
		$issues[]       = '仅 1 个 H2 标题，建议至少 2–3 个';
	} else {
		$issues[] = '正文缺少 H2 标题';
	}
	if ($h3_count >= 1) {
		$heading_score += 6;
	}
	$breakdown['headings'] = $heading_score;

	// 段落结构 (20 pts).
	preg_match_all('/<p[^>]*>(.*?)<\/p>/si', $content, $p_matches);
	$paragraphs    = isset($p_matches[1]) ? array_map('trim', $p_matches[1]) : array();
	$paragraphs    = array_filter($paragraphs, function ($p) {
		return '' !== wp_strip_all_tags($p);
	});
	$p_count       = count($paragraphs);
	$avg_p_len     = 0;
	if ($p_count > 0) {
		$total = 0;
		foreach ($paragraphs as $p) {
			$total += function_exists('mb_strlen') ? mb_strlen(wp_strip_all_tags($p), 'UTF-8') : strlen(wp_strip_all_tags($p));
		}
		$avg_p_len = $total / $p_count;
	}
	$p_score = 0;
	if ($p_count >= 4) {
		$p_score += 10;
	} elseif ($p_count >= 2) {
		$p_score += 5;
	} else {
		$issues[] = '段落数不足';
	}
	if ($avg_p_len >= 60 && $avg_p_len <= 400) {
		$p_score += 10;
	} elseif ($avg_p_len > 0) {
		$p_score += 5;
		if ($avg_p_len < 60) {
			$issues[] = sprintf('段落均长过短（%d 字）', (int) $avg_p_len);
		}
		if ($avg_p_len > 400) {
			$issues[] = sprintf('段落均长过长（%d 字），建议拆分', (int) $avg_p_len);
		}
	}
	$breakdown['paragraphs'] = $p_score;

	// 空标签/结构异常 (15 pts).
	$structure_score = 15;
	$empty_tag_count = preg_match_all('/<(h[1-6]|p)[^>]*>\s*<\/(h[1-6]|p)>/i', $content);
	if ($empty_tag_count > 0) {
		$structure_score = max(0, $structure_score - $empty_tag_count * 3);
		$issues[]        = sprintf('存在 %d 处空标签', $empty_tag_count);
	}
	// 未闭合标签粗略检测（<p> 数量 vs </p>）。
	$open_p  = preg_match_all('/<p[\s>]/i', $content);
	$close_p = preg_match_all('/<\/p>/i', $content);
	if ($open_p !== $close_p) {
		$structure_score = max(0, $structure_score - 5);
		$issues[]        = '存在未闭合的 <p> 标签';
	}
	$breakdown['structure'] = $structure_score;

	// 关键词覆盖 (15 pts).
	$keyword_score = 0;
	if (! empty($keywords)) {
		$hay = mb_strtolower($plain, 'UTF-8');
		$hit = 0;
		foreach ($keywords as $kw) {
			$kw = mb_strtolower(trim((string) $kw), 'UTF-8');
			if ('' !== $kw && false !== mb_strpos($hay, $kw)) {
				$hit++;
			}
		}
		if ($hit > 0) {
			$keyword_score = min(15, (int) round(15 * $hit / max(1, count($keywords))));
		} else {
			$issues[] = '正文未出现任何设定关键词';
		}
	} else {
		// 无关键词时不扣分，给满。
		$keyword_score = 15;
	}
	$breakdown['keywords'] = $keyword_score;

	$score = array_sum($breakdown);
	return array(
		'score'     => (int) min(100, max(0, $score)),
		'breakdown' => $breakdown,
		'issues'    => $issues,
	);
}

/**
 * 闸门：综合评估 + 查重。
 *
 * 返回结构：
 * [
 *   'passed'       => bool,       // 是否允许入库（硬拒则为 false）
 *   'force_draft'  => bool,       // 即使放行，也强制草稿
 *   'score'        => int,
 *   'score_detail' => array,
 *   'duplicate'    => array|false,
 *   'reasons'      => string[],   // 人类可读的原因
 * ]
 *
 * @param string $title
 * @param string $content
 * @param array  $keywords
 * @return array
 */
function abcc_quality_evaluate($title, $content, $keywords = array())
{
	$score_data = abcc_quality_score_content($content, $keywords);
	$duplicate  = abcc_quality_find_duplicate($title, $content);

	$min_score         = (int) abcc_get_setting('abcc_quality_min_score', 60);
	$force_draft_below = (int) abcc_get_setting('abcc_quality_force_draft_below', 70);

	$reasons     = array();
	$passed      = true;
	$force_draft = false;

	if ($duplicate) {
		$passed   = false;
		$reasons[] = sprintf(
			'与文章 #%d 高度相似（%d%%，原因：%s）',
			(int) $duplicate['post_id'],
			(int) $duplicate['similarity'],
			$duplicate['reason']
		);
	}

	if ($score_data['score'] < $min_score) {
		$passed   = false;
		$reasons[] = sprintf('质量分 %d 低于硬闸门 %d', $score_data['score'], $min_score);
	} elseif ($score_data['score'] < $force_draft_below) {
		$force_draft = true;
		$reasons[]   = sprintf('质量分 %d 低于自动发布线 %d，强制草稿', $score_data['score'], $force_draft_below);
	}

	return array(
		'passed'       => $passed,
		'force_draft'  => $force_draft,
		'score'        => $score_data['score'],
		'score_detail' => $score_data,
		'duplicate'    => $duplicate,
		'reasons'      => $reasons,
	);
}

/**
 * 将质量闸门结果写入 post meta（供后续审计 / 列表页显示）。
 *
 * @param int   $post_id
 * @param array $evaluation abcc_quality_evaluate() 的返回值
 * @param string $title
 * @param string $content
 */
function abcc_quality_attach_meta($post_id, $evaluation, $title, $content)
{
	update_post_meta($post_id, '_abcc_quality_score', (int) $evaluation['score']);
	update_post_meta($post_id, '_abcc_quality_issues', wp_json_encode($evaluation['score_detail']['issues']));
	update_post_meta($post_id, '_abcc_quality_breakdown', wp_json_encode($evaluation['score_detail']['breakdown']));
	update_post_meta($post_id, '_abcc_title_fingerprint', abcc_quality_title_fingerprint($title));
	update_post_meta($post_id, '_abcc_content_simhash', abcc_quality_simhash($content));
}
