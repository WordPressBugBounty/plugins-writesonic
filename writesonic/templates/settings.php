<?php

if (!defined('ABSPATH')) {
	exit;
}

$writesonic_tab   = Writesonic_Admin::current_tab();
$writesonic_state = Writesonic_Connection::is_connected() ? Writesonic_Connection::get_state() : null;
?>
<div class="wrap writesonic-admin">
	<h1>
		<img
			height="32"
			alt="<?php esc_attr_e('Writesonic', 'writesonic'); ?>"
			src="<?php echo esc_url(WRITESONIC_URL . 'images/logo.png'); ?>"
		/>
	</h1>

	<?php if (Writesonic_Connection::is_site_moved()) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e('This site address has changed', 'writesonic'); ?></strong><br>
				<?php esc_html_e('This looks like a copy of a connected site. Publishing and analytics are paused so a staging copy cannot write to the live site or skew its traffic. Reconnect to resume.', 'writesonic'); ?>
			</p>
		</div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url(Writesonic_Admin::tab_url('publishing')); ?>"
			class="nav-tab <?php echo 'publishing' === $writesonic_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e('Publishing', 'writesonic'); ?>
		</a>
		<a href="<?php echo esc_url(Writesonic_Admin::tab_url('analytics')); ?>"
			class="nav-tab <?php echo 'analytics' === $writesonic_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e('AI Analytics', 'writesonic'); ?>
		</a>
	</h2>

	<?php
	if ('analytics' === $writesonic_tab) {
		include WRITESONIC_DIR . 'templates/tab-analytics.php';
	} else {
		include WRITESONIC_DIR . 'templates/tab-publishing.php';
	}
	?>
</div>
