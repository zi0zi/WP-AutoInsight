<?php

/**
 * Content generation functions
 *
 * @package WP-AutoInsight
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Builds a normalized generation payload with defaults.
 *
 * @since 3.6.0
 * @param array $args Overrides for the defaults.
 * @return array The normalized payload.
 */
function abcc_build_generation_payload($args = array())
{
	$defaults = array(
		'keywords'   => array(),
		'model'      => abcc_get_setting('prompt_select', 'gpt-4.1-mini-2025-04-14'),
		'tone'       => abcc_get_setting('openai_tone', 'default'),
		'char_limit' => (int) abcc_get_setting('openai_char_limit', 200),
		'post_type'  => 'post',
		'category'   => 0,
		'template'   => 'default',
		'source'     => 'manual', // manual, scheduled, bulk, regenerate
	);

	return wp_parse_args($args, $defaults);
}

/**
 * Build the tracking meta written to generated posts.
 *
 * @param array $payload Generation payload.
 * @return array
 */
function abcc_build_generation_tracking_meta($payload)
{
	return array(
		'_abcc_generated'         => '1',
		'_abcc_model'             => $payload['model'],
		'_abcc_generation_params' => wp_json_encode(
			array(
				'keywords'   => (array) $payload['keywords'],
				'model'      => $payload['model'],
				'tone'       => $payload['tone'],
				'char_limit' => (int) $payload['char_limit'],
				'post_type'  => $payload['post_type'],
				'category'   => (int) $payload['category'],
				'template'   => $payload['template'],
				'source'     => $payload['source'],
			)
		),
	);
}

/**
 * Generates a new post using AI services.
 *
 * @param string  $api_key        The API key for the selected service
 * @param array   $keywords       Keywords to focus the article on
 * @param string  $prompt_select  Which AI service to use
 * @param string  $tone          The tone to use for the article
 * @param boolean $auto_create   Whether this is an automated creation
 * @param int     $char_limit    Maximum token limit
 * @param string  $post_type     The post type
 * @param array   $options       Additional options (e.g., template, category, source)
 * @return int|WP_Error Post ID on success, WP_Error on failure
 */
function abcc_openai_generate_post($api_key, $keywords, $prompt_select, $tone = 'default', $auto_create = false, $char_limit = 200, $post_type = 'post', $options = array())
{
	try {
		$generate_seo = abcc_get_setting('openai_generate_seo', true) && 'none' !== abcc_get_active_seo_plugin();

		$payload     = abcc_build_generation_payload(
			array(
				'keywords'   => $keywords,
				'model'      => $prompt_select,
				'tone'       => $tone,
				'char_limit' => $char_limit,
				'post_type'  => $post_type,
				'category'   => isset($options['category']) ? (int) $options['category'] : 0,
				'template'   => isset($options['template']) ? $options['template'] : 'default',
				'source'     => isset($options['source']) ? sanitize_text_field($options['source']) : ($auto_create ? 'scheduled' : 'manual'),
			)
		);
		$category_id = (int) $payload['category'];
		$template    = $payload['template'];
		$source      = $payload['source'];

		if (true === $generate_seo) {
			// Generate title and SEO data.
			$title_and_seo = abcc_generate_title_and_seo(
				$api_key,
				$keywords,
				$prompt_select,
				array(
					'site_name'        => get_bloginfo('name'),
					'site_description' => get_bloginfo('description'),
				)
			);
			$title         = $title_and_seo['title'];
			$seo_data      = $title_and_seo['seo_data'];
		} else {
			// Just generate a title.
			$title    = abcc_generate_title($api_key, $keywords, $prompt_select);
			$seo_data = array();
		}

		// Then, generate the content.
		$content_array = abcc_generate_post_content_with_template(
			$api_key,
			$keywords,
			$prompt_select,
			$title,
			$char_limit,
			array(
				'template' => $template,
				'tone'     => $tone,
				'category' => $category_id,
			)
		);

		if (false === $content_array || ! is_array($content_array)) {
			// 常见原因：max_tokens 太低被推理模型吃光 / API key 失效 / provider 返回 5xx。
			// 具体失败原因已在 gpt.php 里 error_log，查 debug.log 搜 "API Error" 可定位。
			throw new Exception(sprintf(
				'Content generation failed - no content returned from AI service (model: %s, tokens: %d). 请检查 debug.log 中的 API Error。',
				$prompt_select,
				(int) $char_limit
			));
		}

		$content_array = array_filter(
			$content_array,
			function ($line) {
				return ! strpos($line, '<title>') && '' !== trim($line);
			}
		);

		if (empty($content_array)) {
			throw new Exception('Content generation failed');
		}

		$content_array = array_map('trim', $content_array);
		$content_array = array_filter(
			$content_array,
			function ($line) {
				return ! empty($line) &&
					! strpos($line, '<title>') &&
					! strpos($line, '[SEO]');
			}
		);

		// Process Perplexity citations if applicable.
		if (0 === strpos($prompt_select, 'sonar')) {
			$generation_id = 'abcc_pplx_citations_' . get_current_user_id();
			$citations     = get_transient($generation_id);
			if (! empty($citations)) {
				$citation_style = get_option('abcc_perplexity_citation_style', 'inline');
				$content_array  = abcc_process_perplexity_citations($content_array, $citations, $citation_style);
				delete_transient($generation_id);
			}
		}

		// Brand Kit — 注入品牌提及、内链锚文本、文末品牌卡片。
		if (function_exists('abcc_apply_brand_to_content')) {
			$content_array = abcc_apply_brand_to_content($content_array);
		}

		$format_content = abcc_create_blocks($content_array);
		$post_content   = abcc_gutenberg_blocks($format_content);

		$is_draft_first = abcc_get_setting('abcc_draft_first', true);

		// 质量闸门：查重 + 评分，决定是否入库/是否强制草稿。
		$quality_evaluation = null;
		if (function_exists('abcc_quality_is_enabled') && abcc_quality_is_enabled()) {
			$quality_evaluation = abcc_quality_evaluate($title, $post_content, (array) $keywords);
			if (! $quality_evaluation['passed']) {
				throw new Exception('质量闸门拦截：' . implode('；', $quality_evaluation['reasons']));
			}
			// 低分强制草稿，即便站点设置允许直发。
			if ($quality_evaluation['force_draft']) {
				$is_draft_first = true;
			}
		}

		$post_data = array(
			'post_title'    => $title,
			'post_content'  => wp_kses_post($post_content),
			'post_status'   => $is_draft_first ? 'draft' : 'publish',
			'post_author'   => get_current_user_id(),
			'post_type'     => $post_type,
			'post_category' => $category_id ? array($category_id) : get_option('openai_selected_categories', array()),
		);

		// Add SEO data if Yoast is active.
		if (true === $generate_seo && ! empty($seo_data)) {
			$post_data['meta_input'] = abcc_get_seo_meta_fields($seo_data);
		}

		// Ensure our tracking meta is present.
		if (! isset($post_data['meta_input'])) {
			$post_data['meta_input'] = array();
		}
		$post_data['meta_input'] = array_merge($post_data['meta_input'], abcc_build_generation_tracking_meta($payload));

		$post_id = wp_insert_post($post_data, true);

		if (is_wp_error($post_id)) {
			throw new Exception($post_id->get_error_message());
		}

		// 入库成功后写入质量评分 meta（便于审计和列表页显示）。
		if ($quality_evaluation && function_exists('abcc_quality_attach_meta')) {
			abcc_quality_attach_meta($post_id, $quality_evaluation, $title, $post_content);
		}

		if (abcc_get_setting('openai_generate_images', true)) {
			try {
				$category_ids   = get_option('openai_selected_categories', array());
				$category_names = array();

				if (! empty($category_ids)) {
					foreach ($category_ids as $cat_id) {
						$category = get_category($cat_id);
						if ($category) {
							$category_names[] = $category->name;
						}
					}
				}

				$image_result = abcc_generate_featured_image($prompt_select, $keywords, $category_names);

				if ($image_result) {
					$alt_text = abcc_build_featured_image_alt_text($title, $seo_data['primary_keyword'] ?? '');

					if (is_array($image_result) && ! empty($image_result['attachment_id'])) {
						// Media library image — set thumbnail directly.
						set_post_thumbnail($post_id, $image_result['attachment_id']);
					} elseif (is_string($image_result)) {
						// AI-generated URL — download and attach.
						abcc_set_featured_image($post_id, $image_result, $alt_text);
					}
				}
			} catch (Exception $e) {
				// Image failures should not abort successful text generation.
				unset($e);
			}
		}

		if (true === abcc_get_setting('openai_email_notifications', false)) {
			abcc_send_post_notification($post_id);
		}

		return $post_id;
	} catch (Exception $e) {
		return new WP_Error('post_generation_failed', $e->getMessage());
	}
}


/**
 * 记录/读取最后一次 AI 调用的失败原因，方便上层把真实错误回显给用户。
 *
 * 之前各 provider 拿到 API 错误只是 error_log 了一下就 return false，
 * 上层只能笼统报"生成失败"，对排查毫无帮助。用一个 static 捕获最后
 * 一条错误消息，让 content-sources 在 WP_Error 里带出去。
 *
 * @param string|null $message 传字符串=记录；传 null=读取并清空。
 * @return string
 */
function abcc_last_ai_error($message = null)
{
	static $last = '';

	if (null === $message) {
		$value = $last;
		$last  = '';
		return $value;
	}

	$last = (string) $message;
	return $last;
}

/**
 * Helper function to generate content using selected AI service.
 *
 * @param string $api_key API key
 * @param string $prompt Content prompt
 * @param string $service AI service to use
 * @param int    $char_limit Character limit
 * @return array|false
 */
function abcc_generate_content($api_key, $prompt, $service, $char_limit)
{
	$result = false;

	$provider = abcc_get_model_provider($service);
	$callback = abcc_get_provider_text_generation_callback($provider);

	if (empty($callback) || ! is_callable($callback)) {
		abcc_last_ai_error(sprintf('provider "%s" 缺少可用的文本生成回调（模型名是否拼错？）', $provider));
		return $result;
	}

	// 清掉旧的，保证这次调用拿到的 last_error 是本次的。
	abcc_last_ai_error('');

	$response = call_user_func($callback, $api_key, $prompt, $char_limit, $service);

	if (abcc_provider_supports_citations($provider)) {
		$perplexity_result = $response;
		if (false !== $perplexity_result && ! empty($perplexity_result['text'])) {
			// Store citations in a transient for downstream use.
			$generation_id = 'abcc_pplx_citations_' . get_current_user_id();
			set_transient($generation_id, $perplexity_result['citations'], 300);
			$result = $perplexity_result['text'];
		}
	} else {
		$result = $response;
	}

	return $result;
}

/**
 * Sanitize an AI-generated title by stripping conversational preamble and formatting.
 *
 * Many AI models prepend polite phrases like "好的，这里为您提供…" or
 * "当然，以下是…" before the actual title. This function scans the
 * response lines and returns the first line that looks like a real title.
 *
 * @since 3.8.1
 * @param array|string $result AI response (array of lines or raw string).
 * @return string Cleaned title.
 */
function abcc_sanitize_ai_title($result)
{
	$lines = is_array($result) ? $result : explode("\n", $result);

	// Common AI preamble patterns (Chinese).
	$preamble_patterns = array(
		'好的',
		'当然',
		'以下是',
		'这里为您',
		'这里是',
		'为您提供',
		'为您生成',
		'针对您',
		'围绕您',
		'根据您',
		'让我为您',
		'没问题',
		'很高兴',
		'希望这',
		'以下标题',
		'以下几个',
		'供您参考',
		'供您选择',
		'生成的标题',
		'生成了',
		'建议的标题',
	);

	// Also match common English preamble.
	$preamble_patterns_en = array(
		'Sure',
		'Here is',
		'Here are',
		'Here\'s',
		'Of course',
		'Certainly',
		'I\'ve generated',
		'I have generated',
	);

	$best_title = '';

	foreach ($lines as $line) {
		$line = trim($line);

		// Skip empty lines.
		if ('' === $line) {
			continue;
		}

		// Check if this line is AI preamble.
		$is_preamble = false;
		foreach ($preamble_patterns as $pattern) {
			if (false !== mb_strpos($line, $pattern)) {
				$is_preamble = true;
				break;
			}
		}

		if (! $is_preamble) {
			foreach ($preamble_patterns_en as $pattern) {
				if (0 === stripos($line, $pattern)) {
					$is_preamble = true;
					break;
				}
			}
		}

		if ($is_preamble) {
			continue;
		}

		// This looks like actual title content.
		$best_title = $line;
		break;
	}

	// Fallback: if every line was filtered, use the last non-empty line.
	if ('' === $best_title) {
		foreach (array_reverse($lines) as $line) {
			$line = trim($line);
			if ('' !== $line) {
				$best_title = $line;
				break;
			}
		}
	}

	// Strip surrounding quotes.
	$best_title = trim($best_title, '"\'\'`「」『』《》');

	// Strip markdown bold/italic.
	$best_title = preg_replace('/\*{1,3}(.+?)\*{1,3}/', '$1', $best_title);

	// Strip markdown heading markers.
	$best_title = preg_replace('/^#{1,6}\s+/', '', $best_title);

	// Strip numbered list prefix (e.g. "1. ", "1、").
	$best_title = preg_replace('/^\d+[.、]\s*/', '', $best_title);

	// Strip bullet list prefix.
	$best_title = preg_replace('/^[-*•]\s+/', '', $best_title);

	return trim($best_title);
}

/**
 * Generates a title for a post.
 *
 * @param string $api_key API key for the selected service
 * @param array  $keywords Keywords to focus the title on
 * @param string $prompt_select Which AI service to use
 * @return string The generated title
 */
function abcc_generate_title($api_key, $keywords, $prompt_select)
{
	$prompt  = '为以下主题生成一个有吸引力的中文博客文章标题：' . implode(', ', $keywords) . "\n";
	$prompt .= '重要：请只输出标题本身，不要包含任何解释、前缀、问候语、编号或其他多余内容。直接输出标题文字即可。';

	// 推理型模型（o-series / gpt-5 / sonar-reasoning / gemini thinking）会在 reasoning 阶段消耗
	// 大量 token；50 token 极易在输出前就被吃光。Perplexity sonar 系列至少要 200 才能稳定返回。
	$title_tokens = 200;
	if (0 === strpos($prompt_select, 'sonar')) {
		$title_tokens = 400;
	}

	$result = abcc_generate_content($api_key, $prompt, $prompt_select, $title_tokens);

	if (false === $result || empty($result)) {
		// Fallback：API 失败时用关键词拼一个可用标题，避免整篇生成中断。
		$fallback = abcc_generate_title_fallback($keywords);
		error_log(sprintf(
			'[WP-AutoInsight] abcc_generate_title: empty/false result from model "%s"; falling back to keyword title "%s".',
			$prompt_select,
			$fallback
		));
		return $fallback;
	}

	$cleaned = abcc_sanitize_ai_title($result);

	// 清洗后仍为空（极端：AI 只返回 preamble 就被截断）也走 fallback。
	if ('' === $cleaned) {
		$fallback = abcc_generate_title_fallback($keywords);
		error_log(sprintf(
			'[WP-AutoInsight] abcc_generate_title: sanitizer produced empty title from model "%s"; falling back to "%s".',
			$prompt_select,
			$fallback
		));
		return $fallback;
	}

	return $cleaned;
}

/**
 * 根据关键词拼一个兜底标题，保证文章至少有个可用的标题。
 *
 * @param array $keywords
 * @return string
 */
function abcc_generate_title_fallback($keywords)
{
	$keywords = array_values(array_filter(array_map('trim', (array) $keywords)));
	if (empty($keywords)) {
		return '新文章：' . wp_date('Y-m-d H:i');
	}
	$primary = $keywords[0];
	if (count($keywords) >= 2) {
		return sprintf('%s 与 %s：趋势观察与深度解读', $keywords[0], $keywords[1]);
	}
	return sprintf('%s：最新趋势与深度解读', $primary);
}

/**
 * Generates post content.
 *
 * @param string $api_key API key for the selected service
 * @param array  $keywords Keywords to focus the content on
 * @param string $prompt_select Which AI service to use
 * @param string $title The post title
 * @param int    $char_limit Maximum token limit
 * @return array Array of content lines
 */
function abcc_generate_post_content($api_key, $keywords, $prompt_select, $title, $char_limit)
{
	$prompt  = "根据以下标题撰写一篇中文博客文章：{$title}\n\n";
	$prompt .= '使用以下关键词：' . implode(', ', $keywords) . "\n\n";
	$prompt .= '格式要求：
    - 使用 <h2>标题</h2> 作为主要段落标题
    - 使用 <h3>标题</h3> 作为子标题
    - 每个段落用独立的 <p> 标签包裹
    - 不要在内容中包含文章标题
    - 每个段落独占一行
    - 不要包含空行或空段落
    - 确保 HTML 格式整洁，没有多余的空格或换行
    - 在结束回复之前确保所有 HTML 标签已正确闭合';

	// Brand Kit — 在 legacy prompt 末尾同样追加品牌植入引导。
	if (function_exists('abcc_build_brand_prompt_suffix')) {
		$prompt .= abcc_build_brand_prompt_suffix();
	}

	// 所有 provider 的内容生成都给一个最小 token 预算：旧默认 200 对推理模型会被 reasoning 吃光，
 // 导致输出为空。Perplexity sonar 本来就需要更高。
	$char_limit = max((int) $char_limit, 800);

	return abcc_generate_content($api_key, $prompt, $prompt_select, $char_limit);
}

/**
 * Generates post content using a specific template.
 *
 * @param string $api_key       API key.
 * @param array  $keywords      Keywords.
 * @param string $prompt_select Model.
 * @param string $title         Title.
 * @param int    $char_limit    Limit.
 * @param array  $args          Template and other args.
 * @return array|false
 */
function abcc_generate_post_content_with_template($api_key, $keywords, $prompt_select, $title, $char_limit, $args = array())
{
	$template_slug = $args['template'] ?? 'default';
	$templates     = get_option('abcc_content_templates', array());
	$template      = $templates[$template_slug] ?? ($templates['default'] ?? array());

	if (empty($template)) {
		// Fallback to legacy style if no templates exist.
		return abcc_generate_post_content($api_key, $keywords, $prompt_select, $title, $char_limit);
	}

	$prompt = $template['prompt'];

	// Replace placeholders.
	$category_id   = $args['category'] ?? 0;
	$category_name = $category_id ? get_cat_name($category_id) : 'General';

	$replacements = array(
		'{keywords}'   => implode(', ', $keywords),
		'{title}'      => $title,
		'{tone}'       => $args['tone'] ?? 'professional',
		'{site_name}'  => get_bloginfo('name'),
		'{category}'   => $category_name,
		'{word_count}' => round($char_limit * 0.75), // Rough estimate of words from tokens.
	);

	$prompt = str_replace(array_keys($replacements), array_values($replacements), $prompt);

	// Always enforce HTML structure rules regardless of what the template says.
	$prompt .= ABCC_CONTENT_FORMAT_REQUIREMENTS;

	// Brand Kit — 在 prompt 末尾追加品牌植入引导（若启用）。
	if (function_exists('abcc_build_brand_prompt_suffix')) {
		$prompt .= abcc_build_brand_prompt_suffix();
	}

	// 所有 provider 的内容生成都给一个最小 token 预算：旧默认 200 对推理模型会被 reasoning 吃光，
 // 导致输出为空。Perplexity sonar 本来就需要更高。
	$char_limit = max((int) $char_limit, 800);

	return abcc_generate_content($api_key, $prompt, $prompt_select, $char_limit);
}

/**
 * Processes Perplexity citations and integrates them into the content array.
 *
 * @since 3.3.0
 * @param array  $content_array Array of content lines.
 * @param array  $citations     Array of citation URLs from Perplexity.
 * @param string $style         Citation style: 'inline', 'references', or 'both'.
 * @return array Modified content array with citations applied.
 */
function abcc_process_perplexity_citations($content_array, $citations, $style)
{
	if (empty($citations)) {
		return $content_array;
	}

	$has_inline     = in_array($style, array('inline', 'both'), true);
	$has_references = in_array($style, array('references', 'both'), true);

	// Process inline citations: replace [1], [2] etc. with superscript links.
	if ($has_inline) {
		$content_array = array_map(
			function ($line) use ($citations) {
				return preg_replace_callback(
					'/\[(\d+)\]/',
					function ($matches) use ($citations) {
						$num   = (int) $matches[1];
						$index = $num - 1;
						if (isset($citations[$index])) {
							return sprintf(
								'<sup><a href="%s" target="_blank" rel="noopener noreferrer">[%d]</a></sup>',
								esc_url($citations[$index]),
								$num
							);
						}
						return $matches[0];
					},
					$line
				);
			},
			$content_array
		);
	} elseif ('references' === $style) {
		// Strip [N] markers when only showing references section.
		$content_array = array_map(
			function ($line) {
				return preg_replace('/\[\d+\]/', '', $line);
			},
			$content_array
		);
	}

	// Add references section at the bottom.
	if ($has_references) {
		$content_array[] = '<h2>' . __('Sources', 'automated-blog-content-creator') . '</h2>';
		foreach ($citations as $index => $url) {
			$number          = $index + 1;
			$display_domain  = wp_parse_url($url, PHP_URL_HOST);
			$content_array[] = '<p><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">[' . $number . '] ' . esc_html($display_domain) . '</a></p>';
		}
	}

	return $content_array;
}
