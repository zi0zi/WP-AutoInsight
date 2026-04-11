<?php

/**
 * Plugin Name:       WP-AutoInsight
 * Plugin URI:        https://phalkmin.me/
 * Description:       Create blog posts automatically using the OpenAI and Gemini APIs!
 * Version:           3.8.0
 * Author:            Paulo H. Alkmin
 * Author URI:        https://phalkmin.me/
 * Text Domain:       automated-blog-content-creator
 * Domain Path:       /languages
 * Requires at least: 6.8
 * Requires PHP:      7.4
 *
 * @package WP-AutoInsight
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Polyfills for servers without the mbstring extension.
if (! function_exists('mb_strlen')) {
	function mb_strlen($str, $encoding = null)
	{
		return strlen($str);
	}
}
if (! function_exists('mb_substr')) {
	function mb_substr($str, $start, $length = null, $encoding = null)
	{
		return null === $length ? substr($str, $start) : substr($str, $start, $length);
	}
}
if (! function_exists('mb_strpos')) {
	function mb_strpos($haystack, $needle, $offset = 0, $encoding = null)
	{
		return strpos($haystack, $needle, $offset);
	}
}

// Define plugin version.
define('ABCC_VERSION', '3.8.0');

// Format requirements appended to every AI content generation prompt.
// Defined here so they are enforced regardless of which template is active.
define(
	'ABCC_CONTENT_FORMAT_REQUIREMENTS',
	"\n\n格式要求：\n- 使用 <h2>标题</h2> 作为主要段落标题\n- 使用 <h3>标题</h3> 作为子标题\n- 每个段落用独立的 <p> 标签包裹\n- 不要在内容中包含文章标题\n- 每个段落独占一行\n- 不要包含空行或空段落\n- 确保 HTML 格式整洁，没有多余的空格或换行\n- 在结束回复之前确保所有 HTML 标签已正确闭合"
);

// Include required files.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/class-abcc-plugin.php';
require_once __DIR__ . '/includes/class-abcc-job.php';
require_once __DIR__ . '/includes/class-abcc-openai-client.php';
require_once __DIR__ . '/includes/token-handling.php';
require_once __DIR__ . '/includes/providers.php';
require_once __DIR__ . '/includes/api-keys.php';
require_once __DIR__ . '/includes/blocks.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/images.php';
require_once __DIR__ . '/includes/content-generation.php';
require_once __DIR__ . '/includes/scheduling.php';
require_once __DIR__ . '/includes/ajax-handlers.php';
require_once __DIR__ . '/includes/admin-buttons.php';
require_once __DIR__ . '/includes/audio.php';
require_once __DIR__ . '/includes/infographic.php';
require_once __DIR__ . '/includes/content-sources.php';
require_once __DIR__ . '/includes/onboarding.php';
require_once __DIR__ . '/includes/meta-boxes.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/gpt.php';

/**
 * Handle API request errors.
 *
 * @since 1.0.0
 *
 * @param mixed  $response The API response.
 * @param string $api      The API name.
 * @return void
 */
function handle_api_request_error($response, $api)
{
	if (! ($response instanceof WP_Error) && ! empty($response)) {
		$response = new WP_Error('api_error', $response);
	}

	// Error logging.
	$error_message = 'Unknown error occurred.';
	if (is_wp_error($response)) {
		$error_message = $response->get_error_message();
	}

	error_log(sprintf('%s API Request Error: %s', $api, $error_message));

	// add_settings_error() is only loaded in wp-admin. Background jobs (cron/AJAX)
	// still need to log provider failures without fatalling the whole request.
	if (function_exists('add_settings_error')) {
		add_settings_error(
			'openai-settings',
			'api-request-error',
			// Translators: %1$s is the API name, %2$s is the error message.
			sprintf(esc_html__('Error in %1$s API request: %2$s', 'automated-blog-content-creator'), $api, $error_message),
			'error'
		);
	}
}

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 *
 * @return ABCC_Plugin Plugin instance.
 */
function abcc_init()
{
	return ABCC_Plugin::instance();
}

// Start the plugin.
abcc_init();
