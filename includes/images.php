<?php

/**
 * Image generation functions.
 *
 * @package WP-AutoInsight
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Generates a featured image using AI services.
 *
 * @param string $text_model The text model being used.
 * @param array  $keywords Keywords for image generation.
 * @param array  $category_names Category names for context.
 * @return string|false Image URL on success, false on failure.
 */
function abcc_generate_featured_image($text_model, $keywords, $category_names = array())
{
	try {
		// Check if image generation is enabled.
		if (! get_option('openai_generate_images', true)) {
			return false;
		}

		$image_source = abcc_get_setting('abcc_image_source', 'ai');

		// Media library only mode.
		if ('media_library' === $image_source) {
			return abcc_get_random_media_library_image();
		}

		// AI generation (used in both 'ai' and 'ai_with_fallback' modes).
		$ai_result = abcc_generate_ai_image($text_model, $keywords, $category_names);

		if ($ai_result) {
			return $ai_result;
		}

		// Fallback to media library if in ai_with_fallback mode.
		if ('ai_with_fallback' === $image_source) {
			error_log('AI image generation failed, falling back to media library');
			return abcc_get_random_media_library_image();
		}

		return false;
	} catch (Exception $e) {
		error_log('Image Generation Error: ' . $e->getMessage());

		// Attempt media library fallback on exception too.
		if ('ai_with_fallback' === ($image_source ?? 'ai')) {
			return abcc_get_random_media_library_image();
		}

		return false;
	}
}

/**
 * Perform the actual AI image generation call.
 *
 * @since 3.9.0
 * @param string $text_model The text model being used.
 * @param array  $keywords Keywords for image generation.
 * @param array  $category_names Category names for context.
 * @return string|false Image URL on success, false on failure.
 */
function abcc_generate_ai_image($text_model, $keywords, $category_names = array())
{
	// Build image prompt.
	$prompt = abcc_build_image_prompt($keywords, $category_names);

	// Determine which service to use.
	$image_service = abcc_determine_image_service($text_model);

	if (empty($image_service['service'])) {
		error_log('No available image generation service');
		return false;
	}

	// Generate image using determined service.
	switch ($image_service['service']) {
		case 'openai':
			$openai_size    = get_option('abcc_openai_image_size', '1024x1024');
			$openai_quality = get_option('abcc_openai_image_quality', 'standard');
			$images         = abcc_openai_generate_images($image_service['api_key'], $prompt, 1, $openai_size, $openai_quality);
			if (! empty($images) && is_array($images)) {
				return $images[0];
			}
			break;

		case 'stability':
			$stability_size = get_option('abcc_stability_image_size', '1024x1024');
			$result         = abcc_stability_generate_images($prompt, 1, $image_service['api_key'], $stability_size);
			if (false !== $result) {
				return $result;
			}
			break;

		case 'gemini':
			$gemini_image_model = get_option('abcc_gemini_image_model', 'gemini-2.5-flash-image');
			$gemini_image_size  = get_option('abcc_gemini_image_size', '2K');
			$result             = abcc_gemini_generate_images($image_service['api_key'], $prompt, $gemini_image_model, $gemini_image_size);
			if (false !== $result) {
				return $result;
			}
			break;
	}

	return false;
}

/**
 * Get a random image from the WordPress media library.
 *
 * Returns the attachment ID directly (not a URL), so callers must
 * use set_post_thumbnail() with the ID instead of downloading a URL.
 *
 * @since 3.9.0
 * @return array|false Array with 'attachment_id' and 'url' keys, or false if no images found.
 */
function abcc_get_random_media_library_image()
{
	$attachments = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => 50,
			'orderby'        => 'rand',
			'fields'         => 'ids',
		)
	);

	if (empty($attachments)) {
		error_log('No images found in the media library for fallback.');
		return false;
	}

	$attachment_id = $attachments[array_rand($attachments)];

	return array(
		'attachment_id' => $attachment_id,
		'url'           => wp_get_attachment_url($attachment_id),
	);
}

/**
 * Build featured image alt text.
 *
 * @param string $title           Post title.
 * @param string $primary_keyword Primary keyword.
 * @return string
 */
function abcc_build_featured_image_alt_text($title, $primary_keyword)
{
	$title           = trim((string) $title);
	$primary_keyword = trim((string) $primary_keyword);

	if ('' === $title || '' === $primary_keyword) {
		return '';
	}

	return $title . ' - ' . $primary_keyword;
}

/**
 * Sets the featured image for a post.
 *
 * @param int    $post_id Post ID.
 * @param string $image_url Image URL.
 * @param string $alt_text Optional alt text.
 * @return int|false Attachment ID on success, false on failure.
 */
function abcc_set_featured_image($post_id, $image_url, $alt_text = '')
{
	try {
		if (! function_exists('media_sideload_image')) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// Download and attach the image.
		$attachment_id = media_sideload_image($image_url, $post_id, null, 'id');

		if (is_wp_error($attachment_id)) {
			throw new Exception($attachment_id->get_error_message());
		}

		// Set alt text if provided.
		if (! empty($alt_text)) {
			update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
		}

		// Set as featured image.
		set_post_thumbnail($post_id, $attachment_id);
		return $attachment_id;
	} catch (Exception $e) {
		error_log('Featured Image Error: ' . $e->getMessage());
		return false;
	}
}

/**
 * Builds the image generation prompt.
 *
 * @param array $keywords Keywords for the image.
 * @param array $category_names Category names for context.
 * @return string The generated prompt.
 */
function abcc_build_image_prompt($keywords, $category_names)
{
	$prompt_parts = array();

	// Add keywords.
	if (! empty($keywords)) {
		$prompt_parts[] = implode(', ', array_map('sanitize_text_field', $keywords));
	}

	// Add categories for context.
	if (! empty($category_names)) {
		$prompt_parts[] = 'Related to: ' . implode(', ', $category_names);
	}

	// Add style guidance.
	$prompt_parts[] = 'Create a high-quality, professional image suitable for a blog post';

	return implode('. ', $prompt_parts);
}
