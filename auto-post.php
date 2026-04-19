<?php

/**
 * Plugin Name:       WP-AutoInsight
 * Description:       使用 OpenAI / Gemini / Claude / Perplexity 自动生成中文博客文章，支持热点采集、品牌推广、内容查重与质量闸门。
 * Version:           4.1.3
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
define('ABCC_VERSION', '4.1.3');

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
require_once __DIR__ . '/includes/brand-kit.php';
require_once __DIR__ . '/includes/content-quality.php';
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

/**
 * 阻断上游更新检查 —— 本插件是独立分叉，不与任何上游仓库同步版本。
 *
 * WordPress 在对比 wp.org 插件库或其他来源时，会把当前激活插件的 slug
 * 发送出去换取更新信息。这里强制把本插件从 update_plugins transient
 * 的 response 与 no_update 中移除，让"有新版本可用"提示不会再出现。
 *
 * @since 4.1.0
 */
add_filter(
	'site_transient_update_plugins',
	function ($value) {
		if (! is_object($value)) {
			return $value;
		}
		$plugin_file = plugin_basename(__FILE__);
		if (isset($value->response) && isset($value->response[$plugin_file])) {
			unset($value->response[$plugin_file]);
		}
		if (isset($value->no_update) && isset($value->no_update[$plugin_file])) {
			unset($value->no_update[$plugin_file]);
		}
		return $value;
	},
	20
);

/**
 * 不把本插件的信息提交给 api.wordpress.org 的 plugins 更新接口。
 *
 * @since 4.1.0
 */
add_filter(
	'http_request_args',
	function ($args, $url) {
		if (false === strpos($url, '//api.wordpress.org/plugins/update-check/')) {
			return $args;
		}
		if (empty($args['body']['plugins'])) {
			return $args;
		}
		$plugins = json_decode($args['body']['plugins'], true);
		if (empty($plugins) || ! is_array($plugins)) {
			return $args;
		}
		$plugin_file = plugin_basename(__FILE__);
		if (isset($plugins['plugins'][$plugin_file])) {
			unset($plugins['plugins'][$plugin_file]);
		}
		if (isset($plugins['active']) && is_array($plugins['active'])) {
			$plugins['active'] = array_values(array_diff($plugins['active'], array($plugin_file)));
		}
		$args['body']['plugins'] = wp_json_encode($plugins);
		return $args;
	},
	10,
	2
);
