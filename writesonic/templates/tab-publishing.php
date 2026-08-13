<?php

if (!defined('ABSPATH')) {
	exit;
}

$writesonic_connected = Writesonic_Connection::is_connected();
$writesonic_verified  = $writesonic_state && !empty($writesonic_state['connected']);
$writesonic_token     = Writesonic_Connection::site_token();
?>
<h2><?php esc_html_e('Publishing', 'writesonic'); ?></h2>

<p>
	<?php esc_html_e('Lets Writesonic create and update posts, upload media, and read your categories, tags and authors.', 'writesonic'); ?>
</p>

<?php if (!$writesonic_connected) : ?>
	<p>
		<?php esc_html_e('New to Writesonic?', 'writesonic'); ?>
		<a href="https://app.writesonic.com/signup?utm_source=wordpress-plugin" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e('Sign up', 'writesonic'); ?>
		</a>
	</p>

	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<?php wp_nonce_field('writesonic_connect'); ?>
		<input type="hidden" name="action" value="writesonic_connect">
		<?php submit_button(__('Connect', 'writesonic')); ?>
	</form>
<?php else : ?>
	<p>
		<strong>
			<?php
			if ($writesonic_verified) {
				esc_html_e('Website connected', 'writesonic');
			} else {
				esc_html_e('Waiting for you to finish connecting in Writesonic', 'writesonic');
			}
			?>
		</strong>
	</p>

	<?php if (!$writesonic_verified) : ?>
		<p class="description">
			<?php esc_html_e('Finish the connection in the Writesonic tab that opened, then reload this page.', 'writesonic'); ?>
		</p>
	<?php endif; ?>

	<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
		<?php wp_nonce_field('writesonic_disconnect'); ?>
		<input type="hidden" name="action" value="writesonic_disconnect">
		<input type="hidden" name="token" value="<?php echo esc_attr($writesonic_token); ?>">
		<?php submit_button(__('Disconnect', 'writesonic'), 'secondary'); ?>
	</form>
<?php endif; ?>
