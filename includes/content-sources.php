<?php
/**
 * Content sources: RSS feeds and website scraping for AI article generation.
 *
 * @package WP-AutoInsight
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get all configured content sources.
 *
 * @return array
 */
function abcc_get_content_sources() {
	return get_option( 'abcc_content_sources', array() );
}

/**
 * Save content sources.
 *
 * @param array $sources Array of source configurations.
 * @return bool
 */
function abcc_save_content_sources( $sources ) {
	return update_option( 'abcc_content_sources', $sources );
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
function abcc_fetch_rss_items( $url, $limit = 5 ) {
	$feed = fetch_feed( $url );

	if ( is_wp_error( $feed ) ) {
		return $feed;
	}

	$max_items = $feed->get_item_quantity( $limit );
	$items     = $feed->get_items( 0, $max_items );
	$result    = array();

	foreach ( $items as $item ) {
		$result[] = array(
			'title'       => $item->get_title(),
			'link'        => $item->get_permalink(),
			'description' => wp_strip_all_tags( $item->get_description() ),
			'content'     => wp_strip_all_tags( $item->get_content() ),
			'date'        => $item->get_date( 'Y-m-d H:i:s' ),
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
function abcc_fetch_webpage_content( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 30,
			'user-agent' => 'Mozilla/5.0 (compatible; WP-AutoInsight/' . ABCC_VERSION . ')',
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = wp_remote_retrieve_response_code( $response );
	if ( $status < 200 || $status >= 300 ) {
		return new WP_Error(
			'http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'HTTP 请求失败，状态码：%d', 'automated-blog-content-creator' ),
				$status
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return new WP_Error( 'empty_body', __( '网页内容为空。', 'automated-blog-content-creator' ) );
	}

	// Extract text from <article>, <main>, or <body>.
	$text = '';
	if ( preg_match( '/<article[^>]*>(.*?)<\/article>/si', $body, $m ) ) {
		$text = $m[1];
	} elseif ( preg_match( '/<main[^>]*>(.*?)<\/main>/si', $body, $m ) ) {
		$text = $m[1];
	} elseif ( preg_match( '/<body[^>]*>(.*?)<\/body>/si', $body, $m ) ) {
		$text = $m[1];
	} else {
		$text = $body;
	}

	// Remove scripts & styles.
	$text = preg_replace( '/<script[^>]*>.*?<\/script>/si', '', $text );
	$text = preg_replace( '/<style[^>]*>.*?<\/style>/si', '', $text );

	// Strip tags and normalize whitespace.
	$text = wp_strip_all_tags( $text );
	$text = preg_replace( '/\s+/', ' ', $text );
	$text = trim( $text );

	// Limit to ~3000 characters to stay within prompt size constraints.
	if ( mb_strlen( $text ) > 3000 ) {
		$text = mb_substr( $text, 0, 3000 ) . '...';
	}

	return $text;
}

/**
 * Fetch content from a source (RSS or webpage).
 *
 * @param array $source Source configuration.
 * @return array|WP_Error Fetched content items or error.
 */
function abcc_fetch_source_content( $source ) {
	if ( 'rss' === $source['type'] ) {
		return abcc_fetch_rss_items( $source['url'], 5 );
	}

	// Webpage: wrap single result to match RSS array format.
	$text = abcc_fetch_webpage_content( $source['url'] );
	if ( is_wp_error( $text ) ) {
		return $text;
	}

	return array(
		array(
			'title'       => $source['name'],
			'link'        => $source['url'],
			'description' => $text,
			'content'     => $text,
			'date'        => current_time( 'Y-m-d H:i:s' ),
		),
	);
}

/**
 * Build a prompt that asks the AI to write an original article based on sourced content.
 *
 * @param array  $items    Content items from the source.
 * @param string $tone     Writing tone.
 * @param string $template Custom prompt template (optional).
 * @return string The assembled prompt.
 */
function abcc_build_source_prompt( $items, $tone = 'professional', $template = '' ) {
	$source_text = '';
	$index       = 1;
	foreach ( $items as $item ) {
		$content      = ! empty( $item['content'] ) ? $item['content'] : $item['description'];
		$source_text .= sprintf( "【素材 %d】%s\n%s\n\n", $index, $item['title'], $content );
		++$index;
	}

	if ( ! empty( $template ) ) {
		$prompt = str_replace(
			array( '{source_content}', '{tone}' ),
			array( $source_text, $tone ),
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
function abcc_generate_post_from_source( $source_index, $options = array() ) {
	$sources = abcc_get_content_sources();

	if ( ! isset( $sources[ $source_index ] ) ) {
		return new WP_Error( 'invalid_source', __( '无效的内容来源。', 'automated-blog-content-creator' ) );
	}

	$source = $sources[ $source_index ];

	// Fetch content.
	$items = abcc_fetch_source_content( $source );
	if ( is_wp_error( $items ) ) {
		return $items;
	}
	if ( empty( $items ) ) {
		return new WP_Error( 'no_content', __( '未从来源采集到内容。', 'automated-blog-content-creator' ) );
	}

	// Build the prompt.
	$tone       = isset( $options['tone'] ) ? $options['tone'] : abcc_get_setting( 'openai_tone', 'default' );
	$prompt     = abcc_build_source_prompt( $items, $tone );
	$prompt    .= ABCC_CONTENT_FORMAT_REQUIREMENTS;

	// Get AI parameters.
	$model      = isset( $options['model'] ) ? $options['model'] : abcc_get_setting( 'prompt_select', 'gpt-4.1-mini-2025-04-14' );
	$char_limit = isset( $options['char_limit'] ) ? (int) $options['char_limit'] : (int) abcc_get_setting( 'openai_char_limit', 200 );
	$category   = isset( $options['category'] ) ? (int) $options['category'] : (int) ( $source['category'] ?? 0 );

	// Resolve API key.
	$provider = abcc_get_model_provider( $model );
	$api_key  = abcc_resolve_api_key( $provider );
	if ( empty( $api_key ) ) {
		return new WP_Error( 'no_api_key', __( 'API 密钥未配置。', 'automated-blog-content-creator' ) );
	}

	// Generate title first.
	$first_item    = $items[0];
	$title_prompt  = '根据以下内容，生成一个有吸引力的中文博客文章标题：' . mb_substr( $first_item['title'] . ' ' . $first_item['description'], 0, 200 );
	$title_result  = abcc_generate_content( $api_key, $title_prompt, $model, 50 );

	if ( false === $title_result || empty( $title_result ) ) {
		return new WP_Error( 'title_failed', __( '标题生成失败。', 'automated-blog-content-creator' ) );
	}

	$title = trim( $title_result[0] );
	$title = trim( $title, '"\'\`' );
	$title = preg_replace( '/\*{1,3}(.+?)\*{1,3}/', '$1', $title );
	$title = ltrim( $title, '# ' );

	// Generate content.
	$content_array = abcc_generate_content( $api_key, $prompt, $model, max( $char_limit, 800 ) );
	if ( false === $content_array || empty( $content_array ) ) {
		return new WP_Error( 'content_failed', __( '内容生成失败。', 'automated-blog-content-creator' ) );
	}

	$content_array = array_filter(
		array_map( 'trim', $content_array ),
		function ( $line ) {
			return ! empty( $line ) && false === strpos( $line, '<title>' );
		}
	);

	if ( empty( $content_array ) ) {
		return new WP_Error( 'empty_content', __( '生成的内容为空。', 'automated-blog-content-creator' ) );
	}

	$format_content = abcc_create_blocks( $content_array );
	$post_content   = abcc_gutenberg_blocks( $format_content );

	$post_data = array(
		'post_title'    => $title,
		'post_content'  => wp_kses_post( $post_content ),
		'post_status'   => abcc_get_setting( 'abcc_draft_first', true ) ? 'draft' : 'publish',
		'post_author'   => get_current_user_id() ?: 1,
		'post_type'     => 'post',
		'post_category' => $category ? array( $category ) : array(),
		'meta_input'    => array(
			'_abcc_generated'    => '1',
			'_abcc_model'        => $model,
			'_abcc_source'       => $source['name'],
			'_abcc_source_url'   => $source['url'],
			'_abcc_source_type'  => $source['type'],
		),
	);

	// Generate SEO data if enabled.
	$generate_seo = abcc_get_setting( 'openai_generate_seo', true ) && 'none' !== abcc_get_active_seo_plugin();
	if ( $generate_seo ) {
		$keywords   = array_slice( array_map( function( $i ) { return $i['title']; }, $items ), 0, 3 );
		$seo_result = abcc_generate_title_and_seo( $api_key, $keywords, $model, array(
			'site_name'        => get_bloginfo( 'name' ),
			'site_description' => get_bloginfo( 'description' ),
		) );
		if ( ! empty( $seo_result['seo_data'] ) ) {
			$post_data['meta_input'] = array_merge( $post_data['meta_input'], abcc_get_seo_meta_fields( $seo_result['seo_data'] ) );
		}
	}

	$post_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Generate featured image if enabled.
	if ( abcc_get_setting( 'openai_generate_images', true ) ) {
		try {
			$image_url = abcc_generate_featured_image( $model, array( $title ), array() );
			if ( $image_url ) {
				$alt_text = abcc_build_featured_image_alt_text( $title, '' );
				abcc_set_featured_image( $post_id, $image_url, $alt_text );
			}
		} catch ( Exception $e ) {
			// Image failures should not abort text generation.
			unset( $e );
		}
	}

	// Send notification if enabled.
	if ( abcc_get_setting( 'openai_email_notifications', false ) ) {
		abcc_send_post_notification( $post_id );
	}

	return $post_id;
}

/**
 * Generate posts from all enabled content sources (used by scheduler).
 *
 * @return array Array of results (post IDs or WP_Error objects).
 */
function abcc_generate_posts_from_all_sources() {
	$sources = abcc_get_content_sources();
	$results = array();

	foreach ( $sources as $index => $source ) {
		if ( empty( $source['enabled'] ) ) {
			continue;
		}

		$result          = abcc_generate_post_from_source( $index );
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
function abcc_ajax_save_sources() {
	check_ajax_referer( 'abcc_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( '权限不足。', 'automated-blog-content-creator' ) ) );
	}

	$raw_sources = isset( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
	$sources     = array();

	if ( is_array( $raw_sources ) ) {
		foreach ( $raw_sources as $src ) {
			$url = isset( $src['url'] ) ? esc_url_raw( trim( $src['url'] ) ) : '';
			if ( empty( $url ) ) {
				continue;
			}

			$sources[] = array(
				'name'     => isset( $src['name'] ) ? sanitize_text_field( $src['name'] ) : '',
				'url'      => $url,
				'type'     => isset( $src['type'] ) && in_array( $src['type'], array( 'rss', 'webpage' ), true ) ? $src['type'] : 'rss',
				'category' => isset( $src['category'] ) ? absint( $src['category'] ) : 0,
				'enabled'  => isset( $src['enabled'] ) ? (bool) $src['enabled'] : true,
			);
		}
	}

	abcc_save_content_sources( $sources );
	wp_send_json_success( array( 'message' => __( '来源保存成功。', 'automated-blog-content-creator' ) ) );
}
add_action( 'wp_ajax_abcc_save_sources', 'abcc_ajax_save_sources' );

/**
 * AJAX handler: Preview content from a source.
 */
function abcc_ajax_fetch_source_preview() {
	check_ajax_referer( 'abcc_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( '权限不足。', 'automated-blog-content-creator' ) ) );
	}

	$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'rss';

	if ( empty( $url ) ) {
		wp_send_json_error( array( 'message' => __( 'URL 不能为空。', 'automated-blog-content-creator' ) ) );
	}

	$source = array(
		'name' => 'Preview',
		'url'  => $url,
		'type' => $type,
	);

	$items = abcc_fetch_source_content( $source );

	if ( is_wp_error( $items ) ) {
		wp_send_json_error( array( 'message' => $items->get_error_message() ) );
	}

	wp_send_json_success( array( 'items' => $items ) );
}
add_action( 'wp_ajax_abcc_fetch_source_preview', 'abcc_ajax_fetch_source_preview' );

/**
 * AJAX handler: Generate a post from a specific source.
 */
function abcc_ajax_generate_from_source() {
	check_ajax_referer( 'abcc_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( '权限不足。', 'automated-blog-content-creator' ) ) );
	}

	$source_index = isset( $_POST['source_index'] ) ? absint( $_POST['source_index'] ) : -1;

	if ( $source_index < 0 ) {
		wp_send_json_error( array( 'message' => __( '无效的来源索引。', 'automated-blog-content-creator' ) ) );
	}

	$post_id = abcc_generate_post_from_source( $source_index );

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
	}

	wp_send_json_success(
		array(
			'message' => __( '从来源生成文章成功！', 'automated-blog-content-creator' ),
			'post_id' => $post_id,
			'edit_url' => get_edit_post_link( $post_id, '' ),
		)
	);
}
add_action( 'wp_ajax_abcc_generate_from_source', 'abcc_ajax_generate_from_source' );
