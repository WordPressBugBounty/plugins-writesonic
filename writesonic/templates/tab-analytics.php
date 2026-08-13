<?php

if (!defined('ABSPATH')) {
	exit;
}

$writesonic_legacy_active = Writesonic_Migration::legacy_plugin_active();
$writesonic_disconnected  = Writesonic_Connection::is_analytics_disconnected();
$writesonic_has_key       = '' !== Writesonic_Connection::analytics_key();
$writesonic_tracking      = Writesonic_Connection::is_tracking_enabled();
$writesonic_last_send     = Writesonic_Analytics::last_send_time();
?>
<h2><?php esc_html_e('AI Analytics', 'writesonic'); ?></h2>

<?php
$writesonic_claim = isset($_GET['writesonic_claim'])
	? sanitize_key(wp_unslash($_GET['writesonic_claim']))
	: '';
?>

<?php if ('ok' === $writesonic_claim) : ?>
	<div class="notice notice-success inline">
		<p><?php esc_html_e('Analytics key received. This site is now collecting.', 'writesonic'); ?></p>
	</div>
<?php elseif ('failed' === $writesonic_claim) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php esc_html_e('Writesonic sent this site back, but the key could not be collected — the link may have expired. Try "Check for analytics access", or paste the key shown in Writesonic.', 'writesonic'); ?>
		</p>
	</div>
<?php endif; ?>

<p>
	<?php esc_html_e('Reports requests to your site so Writesonic can show you which AI assistants and crawlers are reading your content.', 'writesonic'); ?>
</p>

<?php if ($writesonic_legacy_active) : ?>
	<div class="notice notice-info inline">
		<p>
			<strong><?php esc_html_e('Paused — the standalone plugin is still active', 'writesonic'); ?></strong><br>
			<?php esc_html_e('The Writesonic AI Analytics plugin is still installed and sending data. This plugin is holding off so your traffic is not counted twice.', 'writesonic'); ?>
		</p>
		<p>
			<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=writesonic_deactivate_legacy'), 'writesonic_deactivate_legacy')); ?>"
				class="button button-primary">
				<?php esc_html_e('Deactivate the old plugin', 'writesonic'); ?>
			</a>
		</p>
	</div>
<?php elseif (!$writesonic_has_key) : ?>
	<?php if ($writesonic_disconnected) : ?>
		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e('Disconnected', 'writesonic'); ?></strong><br>
				<?php esc_html_e('This site has stopped collecting and forgotten its key. Reconnect, or paste the key below, to start again.', 'writesonic'); ?>
			</p>
		</div>
	<?php endif; ?>
	<p>
		<?php esc_html_e('Connect this site to Writesonic and choose a project to start collecting analytics. The key is provisioned for you — there is nothing to paste.', 'writesonic'); ?>
	</p>

	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<?php wp_nonce_field('writesonic_connect'); ?>
		<input type="hidden" name="action" value="writesonic_connect">
		<?php submit_button(__('Connect and enable analytics', 'writesonic')); ?>
	</form>

	<?php if (Writesonic_Connection::is_connected()) : ?>
		<p class="description">
			<?php esc_html_e('Already selected AI Analytics in Writesonic? Fetch the key now instead of waiting.', 'writesonic'); ?>
		</p>

		<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
			<?php wp_nonce_field('writesonic_refresh_analytics'); ?>
			<input type="hidden" name="action" value="writesonic_refresh_analytics">
			<?php submit_button(__('Check for analytics access', 'writesonic'), 'secondary'); ?>
		</form>
	<?php endif; ?>

	<h3><?php esc_html_e('Or paste your key', 'writesonic'); ?></h3>
	<p class="description">
		<?php esc_html_e('Writesonic shows this key after you connect the site. Use it if this site could not pick the key up automatically.', 'writesonic'); ?>
	</p>

	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<?php wp_nonce_field('writesonic_save_analytics_key'); ?>
		<input type="hidden" name="action" value="writesonic_save_analytics_key">
		<input
			type="text"
			name="analytics_key"
			class="regular-text"
			autocomplete="off"
			placeholder="<?php esc_attr_e('Analytics key', 'writesonic'); ?>"
		>
		<?php submit_button(__('Save key', 'writesonic'), 'secondary'); ?>
	</form>
<?php else : ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e('Status', 'writesonic'); ?></th>
			<td>
				<?php if (Writesonic_Connection::is_paused()) : ?>
					<strong><?php esc_html_e('Paused', 'writesonic'); ?></strong><br>
					<span class="description">
						<?php esc_html_e('Collection is paused on this site. Your connection and key are unchanged.', 'writesonic'); ?>
					</span>
				<?php elseif ($writesonic_tracking) : ?>
					<strong><?php esc_html_e('Collecting', 'writesonic'); ?></strong>
				<?php else : ?>
					<strong><?php esc_html_e('Not collecting', 'writesonic'); ?></strong><br>
					<span class="description">
						<?php esc_html_e('This site has an analytics key but Writesonic has not enabled collection for it. Use "Check for analytics access" below, or reconnect the site.', 'writesonic'); ?>
					</span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e('Last event sent', 'writesonic'); ?></th>
			<td>
				<?php
				if ($writesonic_last_send) {
					printf(
						/* translators: %s: human-readable time difference, e.g. "5 mins". */
						esc_html__('%s ago', 'writesonic'),
						esc_html(human_time_diff($writesonic_last_send))
					);
				} else {
					esc_html_e('Nothing sent yet', 'writesonic');
				}
				?>
			</td>
		</tr>
	</table>

	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<?php wp_nonce_field('writesonic_toggle_analytics'); ?>
		<input type="hidden" name="action" value="writesonic_toggle_analytics">
		<?php
		submit_button(
			Writesonic_Connection::is_paused()
				? __('Resume collecting', 'writesonic')
				: __('Pause collecting', 'writesonic'),
			'secondary'
		);
		?>
	</form>

	<?php if (Writesonic_Analytics::is_backing_off()) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e('Writesonic could not reach the analytics service from this server, so sending is paused for a few minutes. If this persists, check whether outbound HTTP requests are blocked on your host.', 'writesonic'); ?>
			</p>
		</div>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e('Pages served from a full-page cache do not run WordPress, so cached hits are not counted. If your site is behind a CDN, ask Writesonic about connecting at the CDN instead.', 'writesonic'); ?>
	</p>

	<hr>

	<h3><?php esc_html_e('Disconnect AI Analytics', 'writesonic'); ?></h3>
	<p class="description">
		<?php esc_html_e('Stops collection and forgets the key on this site. Publishing is unaffected, and you can reconnect or paste the key again at any time.', 'writesonic'); ?>
	</p>

	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<?php wp_nonce_field('writesonic_disconnect_analytics'); ?>
		<input type="hidden" name="action" value="writesonic_disconnect_analytics">
		<?php submit_button(__('Disconnect AI Analytics', 'writesonic'), 'delete'); ?>
	</form>
<?php endif; ?>
