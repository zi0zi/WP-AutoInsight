<?php

/**
 * Tab: Brand Kit — 品牌推广关键词配置
 *
 * @package WP-AutoInsight
 * @since 3.9.0
 */

if (! defined('ABSPATH')) {
	exit;
}

$brand_cfg = abcc_get_brand_config();

?>
<div class="tab-pane active">
	<div class="notice notice-info inline" style="margin:10px 0 20px;">
		<p>
			<strong><?php esc_html_e('品牌推广关键词（辅助广告位）', 'automated-blog-content-creator'); ?></strong> ——
			<?php esc_html_e('这些关键词与正文主题词是解耦的。它们不会作为文章主题，而是作为品牌广告辅助出现在文章中，用于提升品牌搜索收录与内链锚文本曝光。', 'automated-blog-content-creator'); ?>
		</p>
	</div>

	<form method="post" action="">
		<?php wp_nonce_field('abcc_openai_generate_post', 'abcc_openai_nonce'); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e('启用 Brand Kit', 'automated-blog-content-creator'); ?></th>
				<td>
					<label>
						<input type="checkbox" name="abcc_brand_enabled" value="1" <?php checked($brand_cfg['enabled']); ?>>
						<?php esc_html_e('在生成的文章中自动插入品牌提及、品牌内链与文末品牌卡片', 'automated-blog-content-creator'); ?>
					</label>
					<p class="description"><?php esc_html_e('关闭后所有品牌注入流程跳过，不影响现有文章生成。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="abcc_brand_name"><?php esc_html_e('品牌名称', 'automated-blog-content-creator'); ?></label></th>
				<td>
					<input type="text" name="abcc_brand_name" id="abcc_brand_name" value="<?php echo esc_attr($brand_cfg['name']); ?>" class="regular-text" placeholder="<?php esc_attr_e('例如：XX科技', 'automated-blog-content-creator'); ?>">
					<p class="description"><?php esc_html_e('文章中出现此名称时会自动转成带链接的 <strong> 锚文本。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="abcc_brand_url"><?php esc_html_e('品牌链接', 'automated-blog-content-creator'); ?></label></th>
				<td>
					<input type="url" name="abcc_brand_url" id="abcc_brand_url" value="<?php echo esc_attr($brand_cfg['url']); ?>" class="regular-text" placeholder="https://">
					<p class="description"><?php esc_html_e('品牌名首次出现处的锚链接目标（品牌官网 / 落地页）。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="abcc_brand_blurb"><?php esc_html_e('品牌简介（一句话）', 'automated-blog-content-creator'); ?></label></th>
				<td>
					<input type="text" name="abcc_brand_blurb" id="abcc_brand_blurb" value="<?php echo esc_attr($brand_cfg['blurb']); ?>" class="large-text" placeholder="<?php esc_attr_e('例如：专注于企业级 AI 内容自动化方案的服务商', 'automated-blog-content-creator'); ?>">
					<p class="description"><?php esc_html_e('会作为 AI 提及品牌时的背景参考，并出现在文末品牌卡片中。建议 40 字以内。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="abcc_brand_keywords"><?php esc_html_e('品牌相关词（每行一个）', 'automated-blog-content-creator'); ?></label></th>
				<td>
					<textarea name="abcc_brand_keywords" id="abcc_brand_keywords" rows="4" class="large-text" placeholder="<?php esc_attr_e("产品名\n旗舰服务\n招牌方案", 'automated-blog-content-creator'); ?>"><?php echo esc_textarea(implode("\n", (array) $brand_cfg['keywords'])); ?></textarea>
					<p class="description"><?php esc_html_e('这些词会作为品牌语料喂给 AI，引导其在自然上下文中提及。不用于主题生成。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="abcc_brand_body_mentions"><?php esc_html_e('正文品牌提及次数', 'automated-blog-content-creator'); ?></label></th>
				<td>
					<input type="number" name="abcc_brand_body_mentions" id="abcc_brand_body_mentions" value="<?php echo esc_attr((int) $brand_cfg['body_mentions']); ?>" min="0" max="10" step="1" class="small-text">
					<p class="description"><?php esc_html_e('建议 1–3 次，过多会被搜索引擎判定为关键词堆砌。设 0 则仅靠文末卡片曝光。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e('链接策略', 'automated-blog-content-creator'); ?></th>
				<td>
					<select name="abcc_brand_link_rel">
						<option value="dofollow" <?php selected($brand_cfg['link_rel'], 'dofollow'); ?>><?php esc_html_e('dofollow（传递权重，自有站点推荐）', 'automated-blog-content-creator'); ?></option>
						<option value="nofollow" <?php selected($brand_cfg['link_rel'], 'nofollow'); ?>><?php esc_html_e('nofollow（不传递权重）', 'automated-blog-content-creator'); ?></option>
						<option value="sponsored" <?php selected($brand_cfg['link_rel'], 'sponsored'); ?>><?php esc_html_e('sponsored（广告/推广，合规）', 'automated-blog-content-creator'); ?></option>
						<option value="ugc" <?php selected($brand_cfg['link_rel'], 'ugc'); ?>><?php esc_html_e('ugc（用户生成内容）', 'automated-blog-content-creator'); ?></option>
					</select>
					<p class="description"><?php esc_html_e('若是给自己站点做品牌内链，选 dofollow；若是合作推广，建议 sponsored。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e('锚文本数量', 'automated-blog-content-creator'); ?></th>
				<td>
					<label>
						<input type="checkbox" name="abcc_brand_first_link_only" value="1" <?php checked($brand_cfg['first_link_only']); ?>>
						<?php esc_html_e('只给第一次出现的品牌名加链接（推荐）', 'automated-blog-content-creator'); ?>
					</label>
					<p class="description"><?php esc_html_e('关闭则每次出现品牌名都会加链接，容易被判定为过度优化。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e('文末品牌卡片', 'automated-blog-content-creator'); ?></th>
				<td>
					<label>
						<input type="checkbox" name="abcc_brand_footer_card" value="1" <?php checked($brand_cfg['footer_card']); ?>>
						<?php esc_html_e('在文章末尾追加品牌卡片（含简介 + CTA 按钮）', 'automated-blog-content-creator'); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="abcc_brand_cta_text"><?php esc_html_e('CTA 按钮文案', 'automated-blog-content-creator'); ?></label></th>
				<td>
					<input type="text" name="abcc_brand_cta_text" id="abcc_brand_cta_text" value="<?php echo esc_attr($brand_cfg['cta_text']); ?>" class="regular-text" placeholder="<?php esc_attr_e('了解更多 / 免费试用 / 立即咨询', 'automated-blog-content-creator'); ?>">
					<p class="description"><?php esc_html_e('留空则默认为「了解更多关于 {品牌名}」。', 'automated-blog-content-creator'); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(__('保存 Brand Kit 设置', 'automated-blog-content-creator')); ?>
	</form>

	<?php if ($brand_cfg['enabled'] && '' !== $brand_cfg['name']) : ?>
		<hr style="margin:30px 0;">
		<h2><?php esc_html_e('预览：文末品牌卡片', 'automated-blog-content-creator'); ?></h2>
		<div style="max-width:720px;">
			<?php
			// 允许安全 HTML 渲染预览。
			echo wp_kses_post(abcc_build_brand_footer_card($brand_cfg));
			?>
		</div>
	<?php endif; ?>
</div>
