<?php

/**
 * Admin tab: Content Sources management.
 *
 * @package WP-AutoInsight
 * @since   4.0.0
 */

if (! defined('ABSPATH')) {
    exit;
}

$sources = abcc_get_content_sources();
?>

<div class="abcc-sources-wrap">
    <h2><?php esc_html_e('内容来源管理', 'automated-blog-content-creator'); ?></h2>
    <p class="description"><?php esc_html_e('添加 RSS 订阅源或网页地址，插件将自动采集内容并通过 AI 生成原创文章。', 'automated-blog-content-creator'); ?></p>

    <div id="abcc-sources-list">
        <?php if (! empty($sources)) : ?>
            <?php foreach ($sources as $i => $source) : ?>
                <div class="abcc-source-item" data-index="<?php echo esc_attr($i); ?>">
                    <div class="abcc-source-header">
                        <strong class="abcc-source-name-display"><?php echo esc_html($source['name'] ?: '未命名来源'); ?></strong>
                        <span class="abcc-source-type-badge abcc-type-<?php echo esc_attr($source['type']); ?>">
                            <?php
                            if ('rss' === $source['type']) echo 'RSS';
                            elseif ('trending' === $source['type']) esc_html_e('热点新闻', 'automated-blog-content-creator');
                            elseif ('news_search' === $source['type']) esc_html_e('Google 新闻搜索', 'automated-blog-content-creator');
                            elseif ('ai_search' === $source['type']) esc_html_e('Perplexity AI 研究', 'automated-blog-content-creator');
                            else esc_html_e('网页', 'automated-blog-content-creator');
                            ?>
                        </span>
                        <span class="abcc-source-status <?php echo ! empty($source['enabled']) ? 'enabled' : 'disabled'; ?>">
                            <?php echo ! empty($source['enabled']) ? esc_html__('已启用', 'automated-blog-content-creator') : esc_html__('已禁用', 'automated-blog-content-creator'); ?>
                        </span>
                    </div>
                    <div class="abcc-source-fields">
                        <label>
                            <?php esc_html_e('来源名称', 'automated-blog-content-creator'); ?>
                            <input type="text" class="abcc-source-name" value="<?php echo esc_attr($source['name']); ?>" placeholder="<?php esc_attr_e('例如：36氪科技', 'automated-blog-content-creator'); ?>">
                        </label>
                        <label class="abcc-source-url-label" style="<?php echo in_array($source['type'], array('trending', 'news_search', 'ai_search'), true) ? 'display:none;' : ''; ?>">
                            <?php esc_html_e('来源地址', 'automated-blog-content-creator'); ?>
                            <input type="url" class="abcc-source-url" value="<?php echo esc_attr($source['url']); ?>" placeholder="https://">
                        </label>
                        <label>
                            <?php esc_html_e('类型', 'automated-blog-content-creator'); ?>
                            <select class="abcc-source-type">
                                <option value="rss" <?php selected($source['type'], 'rss'); ?>>RSS 订阅</option>
                                <option value="news_search" <?php selected($source['type'], 'news_search'); ?>><?php esc_html_e('Google 新闻搜索（关键词）', 'automated-blog-content-creator'); ?></option>
                                <option value="ai_search" <?php selected($source['type'], 'ai_search'); ?>><?php esc_html_e('Perplexity AI 研究（主题）', 'automated-blog-content-creator'); ?></option>
                                <option value="webpage" <?php selected($source['type'], 'webpage'); ?>><?php esc_html_e('网页', 'automated-blog-content-creator'); ?></option>
                                <option value="trending" <?php selected($source['type'], 'trending'); ?>><?php esc_html_e('热点新闻', 'automated-blog-content-creator'); ?></option>
                            </select>
                        </label>
                        <label class="abcc-source-query-label" style="<?php echo in_array($source['type'], array('news_search', 'ai_search'), true) ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('搜索关键词/主题', 'automated-blog-content-creator'); ?>
                            <input type="text" class="abcc-source-query" value="<?php echo esc_attr($source['query'] ?? ''); ?>" placeholder="<?php esc_attr_e('例如：NBA、英超、世界杯', 'automated-blog-content-creator'); ?>">
                        </label>
                        <label class="abcc-source-ai-model-label" style="<?php echo 'ai_search' === $source['type'] ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('Perplexity 模型', 'automated-blog-content-creator'); ?>
                            <select class="abcc-source-ai-model">
                                <option value="sonar" <?php selected($source['ai_model'] ?? 'sonar', 'sonar'); ?>>Sonar（快 · 便宜）</option>
                                <option value="sonar-pro" <?php selected($source['ai_model'] ?? 'sonar', 'sonar-pro'); ?>>Sonar Pro（更多检索结果）</option>
                                <option value="sonar-reasoning-pro" <?php selected($source['ai_model'] ?? 'sonar', 'sonar-reasoning-pro'); ?>>Sonar Reasoning Pro（深度）</option>
                            </select>
                        </label>
                        <label class="abcc-source-language-label" style="<?php echo 'news_search' === $source['type'] ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('语言', 'automated-blog-content-creator'); ?>
                            <select class="abcc-source-language">
                                <option value="zh-CN" <?php selected($source['language'] ?? 'zh-CN', 'zh-CN'); ?>>简体中文 (zh-CN)</option>
                                <option value="zh-TW" <?php selected($source['language'] ?? 'zh-CN', 'zh-TW'); ?>>繁体中文 (zh-TW)</option>
                                <option value="en-US" <?php selected($source['language'] ?? 'zh-CN', 'en-US'); ?>>English (en-US)</option>
                                <option value="ja-JP" <?php selected($source['language'] ?? 'zh-CN', 'ja-JP'); ?>>日本語 (ja-JP)</option>
                            </select>
                        </label>
                        <label class="abcc-source-region-label" style="<?php echo 'news_search' === $source['type'] ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('地区', 'automated-blog-content-creator'); ?>
                            <select class="abcc-source-region">
                                <option value="CN" <?php selected($source['region'] ?? 'CN', 'CN'); ?>>中国大陆 (CN)</option>
                                <option value="HK" <?php selected($source['region'] ?? 'CN', 'HK'); ?>>香港 (HK)</option>
                                <option value="TW" <?php selected($source['region'] ?? 'CN', 'TW'); ?>>台湾 (TW)</option>
                                <option value="US" <?php selected($source['region'] ?? 'CN', 'US'); ?>>United States (US)</option>
                                <option value="JP" <?php selected($source['region'] ?? 'CN', 'JP'); ?>>日本 (JP)</option>
                            </select>
                        </label>
                        <label class="abcc-source-platform-label" style="<?php echo 'trending' === $source['type'] ? '' : 'display:none;'; ?>">
                            <?php esc_html_e('热点平台', 'automated-blog-content-creator'); ?>
                            <select class="abcc-source-platform">
                                <option value="baidu" <?php selected($source['platform'] ?? 'baidu', 'baidu'); ?>><?php esc_html_e('百度热搜', 'automated-blog-content-creator'); ?></option>
                                <option value="toutiao" <?php selected($source['platform'] ?? 'baidu', 'toutiao'); ?>><?php esc_html_e('今日头条', 'automated-blog-content-creator'); ?></option>
                                <option value="zhihu" <?php selected($source['platform'] ?? 'baidu', 'zhihu'); ?>><?php esc_html_e('知乎热榜', 'automated-blog-content-creator'); ?></option>
                                <option value="merged" <?php selected($source['platform'] ?? 'baidu', 'merged'); ?>><?php esc_html_e('跨源合并（多平台交叉热点）', 'automated-blog-content-creator'); ?></option>
                            </select>
                        </label>
                        <label>
                            <?php esc_html_e('目标分类', 'automated-blog-content-creator'); ?>
                            <?php abcc_category_dropdown_single($source['category'] ?? 0, 'abcc_source_category_' . $i); ?>
                        </label>
                        <label class="abcc-source-enabled-label">
                            <input type="checkbox" class="abcc-source-enabled" <?php checked(! empty($source['enabled'])); ?>>
                            <?php esc_html_e('启用此来源', 'automated-blog-content-creator'); ?>
                        </label>
                    </div>
                    <div class="abcc-source-actions">
                        <button type="button" class="button abcc-preview-source" data-index="<?php echo esc_attr($i); ?>"><?php esc_html_e('预览采集', 'automated-blog-content-creator'); ?></button>
                        <button type="button" class="button button-primary abcc-generate-from-source" data-index="<?php echo esc_attr($i); ?>"><?php esc_html_e('立即采集并生成', 'automated-blog-content-creator'); ?></button>
                        <button type="button" class="button abcc-remove-source"><?php esc_html_e('删除', 'automated-blog-content-creator'); ?></button>
                    </div>
                    <div class="abcc-source-preview-area" style="display:none;"></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <button type="button" class="button" id="abcc-add-source"><?php esc_html_e('+ 添加内容来源', 'automated-blog-content-creator'); ?></button>

    <p class="submit">
        <button type="button" class="button button-primary" id="abcc-save-sources"><?php esc_html_e('保存所有来源', 'automated-blog-content-creator'); ?></button>
    </p>
</div>

<template id="abcc-source-template">
    <div class="abcc-source-item" data-index="__INDEX__">
        <div class="abcc-source-header">
            <strong class="abcc-source-name-display"><?php esc_html_e('新来源', 'automated-blog-content-creator'); ?></strong>
            <span class="abcc-source-type-badge abcc-type-rss">RSS</span>
            <span class="abcc-source-status enabled"><?php esc_html_e('已启用', 'automated-blog-content-creator'); ?></span>
        </div>
        <div class="abcc-source-fields">
            <label>
                <?php esc_html_e('来源名称', 'automated-blog-content-creator'); ?>
                <input type="text" class="abcc-source-name" value="" placeholder="<?php esc_attr_e('例如：36氪科技', 'automated-blog-content-creator'); ?>">
            </label>
            <label class="abcc-source-url-label">
                <?php esc_html_e('来源地址', 'automated-blog-content-creator'); ?>
                <input type="url" class="abcc-source-url" value="" placeholder="https://">
            </label>
            <label>
                <?php esc_html_e('类型', 'automated-blog-content-creator'); ?>
                <select class="abcc-source-type">
                    <option value="rss">RSS 订阅</option>
                    <option value="news_search"><?php esc_html_e('Google 新闻搜索（关键词）', 'automated-blog-content-creator'); ?></option>
                    <option value="ai_search"><?php esc_html_e('Perplexity AI 研究（主题）', 'automated-blog-content-creator'); ?></option>
                    <option value="webpage"><?php esc_html_e('网页', 'automated-blog-content-creator'); ?></option>
                    <option value="trending"><?php esc_html_e('热点新闻', 'automated-blog-content-creator'); ?></option>
                </select>
            </label>
            <label class="abcc-source-query-label" style="display:none;">
                <?php esc_html_e('搜索关键词/主题', 'automated-blog-content-creator'); ?>
                <input type="text" class="abcc-source-query" value="" placeholder="<?php esc_attr_e('例如：NBA、英超、世界杯', 'automated-blog-content-creator'); ?>">
            </label>
            <label class="abcc-source-ai-model-label" style="display:none;">
                <?php esc_html_e('Perplexity 模型', 'automated-blog-content-creator'); ?>
                <select class="abcc-source-ai-model">
                    <option value="sonar">Sonar（快 · 便宜）</option>
                    <option value="sonar-pro">Sonar Pro（更多检索结果）</option>
                    <option value="sonar-reasoning-pro">Sonar Reasoning Pro（深度）</option>
                </select>
            </label>
            <label class="abcc-source-language-label" style="display:none;">
                <?php esc_html_e('语言', 'automated-blog-content-creator'); ?>
                <select class="abcc-source-language">
                    <option value="zh-CN">简体中文 (zh-CN)</option>
                    <option value="zh-TW">繁体中文 (zh-TW)</option>
                    <option value="en-US">English (en-US)</option>
                    <option value="ja-JP">日本語 (ja-JP)</option>
                </select>
            </label>
            <label class="abcc-source-region-label" style="display:none;">
                <?php esc_html_e('地区', 'automated-blog-content-creator'); ?>
                <select class="abcc-source-region">
                    <option value="CN">中国大陆 (CN)</option>
                    <option value="HK">香港 (HK)</option>
                    <option value="TW">台湾 (TW)</option>
                    <option value="US">United States (US)</option>
                    <option value="JP">日本 (JP)</option>
                </select>
            </label>
            <label class="abcc-source-platform-label" style="display:none;">
                <?php esc_html_e('热点平台', 'automated-blog-content-creator'); ?>
                <select class="abcc-source-platform">
                    <option value="baidu"><?php esc_html_e('百度热搜', 'automated-blog-content-creator'); ?></option>
                    <option value="toutiao"><?php esc_html_e('今日头条', 'automated-blog-content-creator'); ?></option>
                    <option value="zhihu"><?php esc_html_e('知乎热榜', 'automated-blog-content-creator'); ?></option>
                    <option value="merged"><?php esc_html_e('跨源合并（多平台交叉热点）', 'automated-blog-content-creator'); ?></option>
                </select>
            </label>
            <label>
                <?php esc_html_e('目标分类', 'automated-blog-content-creator'); ?>
                <?php abcc_category_dropdown_single(0, 'abcc_source_category___INDEX__'); ?>
            </label>
            <label class="abcc-source-enabled-label">
                <input type="checkbox" class="abcc-source-enabled" checked>
                <?php esc_html_e('启用此来源', 'automated-blog-content-creator'); ?>
            </label>
        </div>
        <div class="abcc-source-actions">
            <button type="button" class="button abcc-preview-source" data-index="__INDEX__"><?php esc_html_e('预览采集', 'automated-blog-content-creator'); ?></button>
            <button type="button" class="button button-primary abcc-generate-from-source" data-index="__INDEX__"><?php esc_html_e('立即采集并生成', 'automated-blog-content-creator'); ?></button>
            <button type="button" class="button abcc-remove-source"><?php esc_html_e('删除', 'automated-blog-content-creator'); ?></button>
        </div>
        <div class="abcc-source-preview-area" style="display:none;"></div>
    </div>
</template>

<style>
    .abcc-sources-wrap {
        max-width: 900px;
    }

    .abcc-source-item {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 6px;
        padding: 16px;
        margin-bottom: 12px;
    }

    .abcc-source-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 12px;
    }

    .abcc-source-type-badge {
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 3px;
        font-weight: 600;
    }

    .abcc-type-rss {
        background: #fff3cd;
        color: #856404;
    }

    .abcc-type-webpage {
        background: #d1ecf1;
        color: #0c5460;
    }

    .abcc-type-trending {
        background: #f8d7da;
        color: #721c24;
    }

    .abcc-type-news_search {
        background: #e2d6f7;
        color: #4a148c;
    }

    .abcc-type-ai_search {
        background: #d4edda;
        color: #155724;
    }

    .abcc-source-status.enabled {
        color: #28a745;
        font-size: 12px;
    }

    .abcc-source-status.disabled {
        color: #999;
        font-size: 12px;
    }

    .abcc-source-fields {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 12px;
    }

    .abcc-source-fields label {
        display: flex;
        flex-direction: column;
        gap: 4px;
        font-weight: 500;
        font-size: 13px;
    }

    .abcc-source-fields input[type="text"],
    .abcc-source-fields input[type="url"],
    .abcc-source-fields select {
        width: 100%;
        padding: 6px 8px;
    }

    .abcc-source-enabled-label {
        flex-direction: row !important;
        align-items: center !important;
    }

    .abcc-source-actions {
        display: flex;
        gap: 8px;
    }

    .abcc-source-preview-area {
        margin-top: 12px;
        padding: 12px;
        background: #f8f9fa;
        border-radius: 4px;
        font-size: 13px;
        max-height: 300px;
        overflow-y: auto;
    }

    .abcc-source-preview-area .preview-item {
        padding: 8px 0;
        border-bottom: 1px solid #eee;
    }

    .abcc-source-preview-area .preview-item:last-child {
        border-bottom: none;
    }

    .abcc-source-preview-area .preview-title {
        font-weight: 600;
        margin-bottom: 4px;
    }

    .abcc-source-preview-area .preview-desc {
        color: #666;
        font-size: 12px;
    }

    .abcc-source-preview-area .preview-date {
        color: #999;
        font-size: 11px;
    }

    #abcc-add-source {
        margin-top: 8px;
    }
</style>

<script>
    jQuery(function($) {
        var $list = $('#abcc-sources-list');
        var nonce = '<?php echo esc_js(wp_create_nonce('abcc_nonce')); ?>';

        // Add new source.
        $('#abcc-add-source').on('click', function() {
            var tmpl = $('#abcc-source-template').html();
            var idx = $list.find('.abcc-source-item').length;
            $list.append(tmpl.replace(/__INDEX__/g, idx));
        });

        // Remove source.
        $list.on('click', '.abcc-remove-source', function() {
            $(this).closest('.abcc-source-item').remove();
        });

        // Save all sources.
        $('#abcc-save-sources').on('click', function() {
            var $btn = $(this);
            $btn.prop('disabled', true).text('保存中...');

            var sources = [];
            $list.find('.abcc-source-item').each(function() {
                var $item = $(this);
                sources.push({
                    name: $item.find('.abcc-source-name').val(),
                    url: $item.find('.abcc-source-url').val(),
                    type: $item.find('.abcc-source-type').val(),
                    platform: $item.find('.abcc-source-platform').val() || 'baidu',
                    query: $item.find('.abcc-source-query').val() || '',
                    language: $item.find('.abcc-source-language').val() || 'zh-CN',
                    region: $item.find('.abcc-source-region').val() || 'CN',
                    ai_model: $item.find('.abcc-source-ai-model').val() || 'sonar',
                    category: $item.find('select[name^="abcc_source_category"]').val() || 0,
                    enabled: $item.find('.abcc-source-enabled').is(':checked') ? 1 : 0
                });
            });

            $.post(ajaxurl, {
                action: 'abcc_save_sources',
                nonce: nonce,
                sources: sources
            }, function(resp) {
                $btn.prop('disabled', false).text('保存所有来源');
                if (resp.success) {
                    alert(resp.data.message || '保存成功！');
                } else {
                    alert(resp.data.message || '保存失败');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('保存所有来源');
                alert('网络错误');
            });
        });

        // Preview source content.
        $list.on('click', '.abcc-preview-source', function() {
            var $item = $(this).closest('.abcc-source-item');
            var $preview = $item.find('.abcc-source-preview-area');
            var $btn = $(this);

            $btn.prop('disabled', true).text('采集中...');
            $preview.show().html('<p>正在采集内容...</p>');

            $.post(ajaxurl, {
                action: 'abcc_fetch_source_preview',
                nonce: nonce,
                url: $item.find('.abcc-source-url').val(),
                type: $item.find('.abcc-source-type').val(),
                platform: $item.find('.abcc-source-platform').val() || 'baidu',
                query: $item.find('.abcc-source-query').val() || '',
                language: $item.find('.abcc-source-language').val() || 'zh-CN',
                region: $item.find('.abcc-source-region').val() || 'CN',
                ai_model: $item.find('.abcc-source-ai-model').val() || 'sonar'
            }, function(resp) {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('预览采集', 'automated-blog-content-creator')); ?>');
                if (resp.success && resp.data.items) {
                    var html = '';
                    $.each(resp.data.items, function(i, item) {
                        html += '<div class="preview-item">';
                        html += '<div class="preview-title">' + (item.title || '无标题') + '</div>';
                        html += '<div class="preview-desc">' + (item.description || '').substring(0, 200) + '</div>';
                        if (item.date) html += '<div class="preview-date">' + item.date + '</div>';
                        html += '</div>';
                    });
                    $preview.html(html || '<p>未获取到内容</p>');
                } else {
                    $preview.html('<p style="color:red;">' + (resp.data.message || '采集失败') + '</p>');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('预览采集', 'automated-blog-content-creator')); ?>');
                $preview.html('<p style="color:red;">网络错误</p>');
            });
        });

        // Generate from source.
        $list.on('click', '.abcc-generate-from-source', function() {
            var $btn = $(this);
            var idx = $btn.data('index');

            if (!confirm('确定要从此来源采集内容并通过 AI 生成文章吗？')) return;

            $btn.prop('disabled', true).text('生成中...');

            $.post(ajaxurl, {
                action: 'abcc_generate_from_source',
                nonce: nonce,
                source_index: idx
            }, function(resp) {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('立即采集并生成', 'automated-blog-content-creator')); ?>');
                if (resp.success) {
                    if (resp.data.edit_url) {
                        if (confirm(resp.data.message + '\n\n是否立即编辑？')) {
                            window.location.href = resp.data.edit_url;
                        }
                    } else {
                        alert(resp.data.message);
                    }
                } else {
                    alert(resp.data.message || '生成失败');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('<?php echo esc_js(__('立即采集并生成', 'automated-blog-content-creator')); ?>');
                alert('网络错误');
            });
        });

        // Toggle URL / platform / news_search / ai_search fields based on source type.
        $list.on('change', '.abcc-source-type', function() {
            var $item = $(this).closest('.abcc-source-item');
            var type = $(this).val();
            var isTrending = type === 'trending';
            var isNewsSearch = type === 'news_search';
            var isAiSearch = type === 'ai_search';
            var needsUrl = !isTrending && !isNewsSearch && !isAiSearch;
            var needsQuery = isNewsSearch || isAiSearch;
            $item.find('.abcc-source-url-label').toggle(needsUrl);
            $item.find('.abcc-source-platform-label').toggle(isTrending);
            $item.find('.abcc-source-query-label').toggle(needsQuery);
            $item.find('.abcc-source-language-label').toggle(isNewsSearch);
            $item.find('.abcc-source-region-label').toggle(isNewsSearch);
            $item.find('.abcc-source-ai-model-label').toggle(isAiSearch);
            // Update badge.
            var $badge = $item.find('.abcc-source-type-badge');
            $badge.removeClass('abcc-type-rss abcc-type-webpage abcc-type-trending abcc-type-news_search abcc-type-ai_search').addClass('abcc-type-' + type);
            if (type === 'rss') $badge.text('RSS');
            else if (type === 'trending') $badge.text('热点新闻');
            else if (type === 'news_search') $badge.text('Google 新闻搜索');
            else if (type === 'ai_search') $badge.text('Perplexity AI 研究');
            else $badge.text('网页');
        });
    });
</script>