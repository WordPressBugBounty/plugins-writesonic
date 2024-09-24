<style>
    .writesonic input[type="text"] {
        width: 250px;
    }
</style>
<?php
$domain = home_url();

if (isset($_POST['connect'])) {
    //Get current user email
    $user       = wp_get_current_user();
    $user_email = $user->user_email;
    //Generate hash
    $user_token = bin2hex(openssl_random_pseudo_bytes(16));
    //Get stored passwords
    $writesonic_tokens = get_option(WRITESONIC_API_KEY_OPTION);

    if (is_array($writesonic_passwords)) {
        $writesonic_tokens[$user_email] = $user_token;
    } else {
        $writesonic_tokens = array(
            $user_email => $user_token
        );
    }
    //Update or add new passwords
    update_option(WRITESONIC_API_KEY_OPTION, $writesonic_tokens);
    //Create writesonic redirect url
    $redirect_url = sprintf('%s?domain=%s&user=%s&token=%s', WRITESONIC_CONNECT_URL, $domain, $user_email, $user_token);
}

if (isset($_POST['disconnect']) && isset($_POST['token'])) {
    $writesonic_tokens = get_option(WRITESONIC_API_KEY_OPTION, array());
    $token            = sanitize_text_field($_POST['token']);
    $email            = array_search($token, $writesonic_tokens);
    if ($email && is_array($writesonic_tokens) && !empty($writesonic_tokens)) {
        unset($writesonic_tokens[$email]);
        update_option(WRITESONIC_API_KEY_OPTION, $writesonic_tokens);
    }
}

$current_user     = wp_get_current_user();
$writesonic_tokens = get_option(WRITESONIC_API_KEY_OPTION, array());
$user_connected   = false;
$user_token       = '';

if (is_array($writesonic_tokens) && array_key_exists($current_user->user_email, $writesonic_tokens)) {
    $user_connected = true;
    $user_token     = $writesonic_tokens[$current_user->user_email];
    $writesonic_domain_authorized = WPM_Writesonic_Integration::checkAuthorization($user_token, $domain);
}


?>
<style>
.form-wrapper, span {
	font-size: 14px;
}
</style>
<div class="form-wrapper">
    <br><img height="40px" alt="Writesonic logo" src="<?php echo esc_attr(plugin_dir_url(__DIR__) . '/images/logo.png'); ?>" /><br><br>
    <?php if (!$user_connected): ?>
        <?php _e('New to Writesonic?', 'writesonic'); ?>
        <a href="https://app.writesonic.com/signup?utm_source=wordpress-plugin" target="_blank"><?php _e('Sign up', 'writesonic'); ?></a> <br> <br>
    <?php elseif ($user_connected && $writesonic_domain_authorized): ?>
        <div class="form-text"><span class="bold"><?php _e( 'Website connected', 'writesonic' ); ?></span></div>
    <?php else: ?>
        <div class="form-text"><span class="bold"><?php _e( 'Waiting for your authorization', 'writesonic' ); ?></span></div>
    <?php endif; ?>
    <form action="" method="post" class="writesonic">
        <?php if (!$user_connected) : ?>
            <input type="hidden" name="connect" value="true">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr(__('Connect', 'writesonic')); ?>">
        <?php elseif ($user_connected && !isset($_POST['connect'])) : ?>
            <!-- <label for="writesonic_api_key"><?php _e('Integration API Key', 'writesonic'); ?></label> -->
            <input type="hidden" name="token" id="writesonic_api_key" value="<?php echo esc_attr($user_token); ?>"><br>
            <input type="hidden" name="disconnect" value="true">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="<?php echo esc_attr(__('Disconnect', 'writesonic')); ?>">
        <?php endif; ?>
    </form>
</div>

<?php if (isset($_POST['connect'])) : ?>
    <br><span><?php _e('Redirecting to', 'writesonic'); ?> <a href="<?php echo esc_attr($redirect_url); ?>">Writesonic</a>...</span><br><br>
    <script>
        setTimeout(function() {
            window.location = '<?php echo esc_attr(WRITESONIC_CONNECT_URL) ?>?domain=<?php echo esc_attr($domain) ?>&user=<?php echo esc_attr($user_email) ?>&token=<?php echo esc_attr($user_token) ?>';
        }, 2000);
    </script>
<?php endif; ?>