<?php
/**
 * Plugin Name: BobPress
 * Plugin URI: https://wearebob.blog
 * Description: WordPress as AI middleware — semantic event logging routed through an AI agent.
 * Version: 0.3.0
 * Author: Bob & Joe
 * Text Domain: bobpress
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Settings ────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'bobpress_admin_menu' );
function bobpress_admin_menu() {
    add_options_page( 'BobPress', 'BobPress', 'manage_options', 'bobpress', 'bobpress_settings_page' );
}

add_action( 'admin_init', 'bobpress_register_settings' );
function bobpress_register_settings() {
    register_setting( 'bobpress_settings', 'bobpress_endpoint' );
    register_setting( 'bobpress_settings', 'bobpress_channel' );
    register_setting( 'bobpress_settings', 'bobpress_username' );
    register_setting( 'bobpress_settings', 'bobpress_app_password' );
    register_setting( 'bobpress_settings', 'bobpress_site_label' );
}

function bobpress_settings_page() {
    ?>
    <div class="wrap">
        <h1>BobPress Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'bobpress_settings' ); ?>
            <table class="form-table">
                <tr><th>Agent Chat Endpoint</th><td>
                    <input type="url" name="bobpress_endpoint" value="<?php echo esc_attr( get_option('bobpress_endpoint') ); ?>" class="regular-text" placeholder="https://clawpress.blog/wp-json/agent-chat/v1/send" />
                </td></tr>
                <tr><th>Channel</th><td>
                    <input type="text" name="bobpress_channel" value="<?php echo esc_attr( get_option('bobpress_channel', 'ops') ); ?>" class="regular-text" />
                </td></tr>
                <tr><th>Username</th><td>
                    <input type="text" name="bobpress_username" value="<?php echo esc_attr( get_option('bobpress_username') ); ?>" class="regular-text" />
                </td></tr>
                <tr><th>App Password</th><td>
                    <input type="password" name="bobpress_app_password" value="<?php echo esc_attr( get_option('bobpress_app_password') ); ?>" class="regular-text" />
                </td></tr>
                <tr><th>Site Label</th><td>
                    <input type="text" name="bobpress_site_label" value="<?php echo esc_attr( get_option('bobpress_site_label', get_bloginfo('name')) ); ?>" class="regular-text" />
                    <p class="description">Label shown in event messages (e.g. "wearebob.blog")</p>
                </td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// ─── Core send function ───────────────────────────────────────────────────────

function bobpress_send( string $message, string $sender = 'WordPress' ) {
    $endpoint = get_option( 'bobpress_endpoint' );
    $username = get_option( 'bobpress_username' );
    $password = get_option( 'bobpress_app_password' );
    $channel  = get_option( 'bobpress_channel', 'ops' );

    if ( ! $endpoint || ! $username || ! $password ) return;

    $label = get_option( 'bobpress_site_label', get_bloginfo('name') );
    $full_message = "[BobPress — {$label}] " . $message;

    wp_remote_post( $endpoint, [
        'blocking'  => false,
        'timeout'   => 5,
        'headers'   => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
        ],
        'body' => wp_json_encode([
            'channel'     => $channel,
            'sender'      => $sender,
            'sender_type' => 'agent',
            'message'     => $full_message,
        ]),
    ]);
}

// ─── Source detection ─────────────────────────────────────────────────────────

function bobpress_detect_source(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ( defined('REST_REQUEST') && REST_REQUEST ) {
        if ( stripos( $ua, 'OpenClaw' ) !== false || stripos( $ua, 'bob' ) !== false ) return 'Agent (OC)';
        if ( stripos( $ua, 'Jetpack' ) !== false ) return 'Jetpack app';
        if ( stripos( $ua, 'MCP' ) !== false ) return 'MCP';
        return 'REST API';
    }
    if ( defined('XMLRPC_REQUEST') && XMLRPC_REQUEST ) return 'XML-RPC';
    return 'wp-admin';
}

// ─── Hook: save_post ─────────────────────────────────────────────────────────

add_action( 'save_post', 'bobpress_on_save_post', 10, 3 );
function bobpress_on_save_post( int $post_id, WP_Post $post, bool $update ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) ) return;
    if ( in_array( $post->post_type, ['revision', 'auto-draft', 'nav_menu_item', 'attachment'], true ) ) return;

    $action  = $update ? 'Post updated' : 'Post created';
    $source  = bobpress_detect_source();
    $author  = get_the_author_meta( 'display_name', $post->post_author );
    $edit_url = admin_url( "post.php?post={$post_id}&action=edit" );

    $msg  = "{$action}: {$post->post_title} ({$post->post_status})\n";
    $msg .= "👤 Author: {$author} | 📝 Type: {$post->post_type} | 🔌 Source: {$source}\n";
    $msg .= "🔗 {$edit_url}\n";
    $msg .= "Review: APPROVE, FLAG: <reason>, or NOTES: <notes>";

    bobpress_send( $msg );
}

// ─── Hook: transition_post_status ────────────────────────────────────────────

add_action( 'transition_post_status', 'bobpress_on_status_transition', 10, 3 );
function bobpress_on_status_transition( string $new_status, string $old_status, WP_Post $post ) {
    if ( $new_status === $old_status ) return;
    if ( in_array( $post->post_type, ['revision', 'auto-draft', 'nav_menu_item'], true ) ) return;
    // skip draft→draft, etc — only report meaningful transitions
    $notable = [ 'publish', 'pending', 'private', 'trash' ];
    if ( ! in_array( $new_status, $notable, true ) ) return;

    $title = $post->post_title ?: "(untitled {$post->post_type})";
    bobpress_send( "Status change: \"{$title}\" → {$old_status} → {$new_status}" );
}

// ─── Hook: wp_insert_comment ─────────────────────────────────────────────────

add_action( 'wp_insert_comment', 'bobpress_on_new_comment', 10, 2 );
function bobpress_on_new_comment( int $comment_id, WP_Comment $comment ) {
    $post  = get_post( $comment->comment_post_ID );
    $title = $post ? $post->post_title : 'unknown post';
    $author = $comment->comment_author ?: 'Anonymous';
    $preview = mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, 100 );
    $status = $comment->comment_approved === '1' ? 'approved' : ( $comment->comment_approved === 'spam' ? 'spam' : 'pending' );

    bobpress_send( "New comment on \"{$title}\" by {$author} ({$status})\n💬 \"{$preview}\"" );
}

// ─── Hook: comment status transitions ────────────────────────────────────────

add_action( 'transition_comment_status', 'bobpress_on_comment_status', 10, 3 );
function bobpress_on_comment_status( string $new_status, string $old_status, WP_Comment $comment ) {
    if ( $new_status === $old_status ) return;
    $post  = get_post( $comment->comment_post_ID );
    $title = $post ? $post->post_title : 'unknown post';
    $author = $comment->comment_author ?: 'Anonymous';
    bobpress_send( "Comment by {$author} on \"{$title}\": {$old_status} → {$new_status}" );
}

// ─── Hook: user_register ─────────────────────────────────────────────────────

add_action( 'user_register', 'bobpress_on_user_register', 10, 1 );
function bobpress_on_user_register( int $user_id ) {
    $user   = get_userdata( $user_id );
    $source = bobpress_detect_source();
    bobpress_send( "New user registered: {$user->user_login} ({$user->user_email}) via {$source}" );
}

// ─── Hook: wp_login ──────────────────────────────────────────────────────────

add_action( 'wp_login', 'bobpress_on_login', 10, 2 );
function bobpress_on_login( string $user_login, WP_User $user ) {
    // Only log first-time logins or admin/editor logins to reduce noise
    if ( user_can( $user, 'edit_posts' ) ) {
        bobpress_send( "Login: {$user_login} (" . implode(', ', $user->roles) . ")" );
    }
}

// ─── Hook: wp_login_failed ───────────────────────────────────────────────────

add_action( 'wp_login_failed', 'bobpress_on_login_failed', 10, 1 );
function bobpress_on_login_failed( string $username ) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    bobpress_send( "⚠️ Failed login attempt for \"{$username}\" from {$ip}" );
}

// ─── Hook: Jetpack subscription ──────────────────────────────────────────────

add_action( 'jetpack_subscriptions_post_subscribe', 'bobpress_on_subscription', 10, 3 );
function bobpress_on_subscription( $email, $post_id, $first_time ) {
    $post  = $post_id ? get_post( $post_id ) : null;
    $title = $post ? "\"{$post->post_title}\"" : 'site-wide';
    $type  = $first_time ? 'new subscriber' : 'existing subscriber re-subscribed';
    bobpress_send( "📧 Newsletter: {$type} from {$email} via {$title}" );
}

// ─── Hook: activated_plugin / deactivated_plugin ─────────────────────────────

add_action( 'activated_plugin', 'bobpress_on_plugin_activate', 10, 1 );
function bobpress_on_plugin_activate( string $plugin ) {
    $current_user = wp_get_current_user();
    bobpress_send( "🔌 Plugin activated: {$plugin} by {$current_user->user_login}" );
}

add_action( 'deactivated_plugin', 'bobpress_on_plugin_deactivate', 10, 1 );
function bobpress_on_plugin_deactivate( string $plugin ) {
    $current_user = wp_get_current_user();
    bobpress_send( "🔌 Plugin deactivated: {$plugin} by {$current_user->user_login}" );
}

// ─── Hook: Search with no results (v0.3 new) ─────────────────────────────────

add_action( 'template_redirect', 'bobpress_on_search_no_results' );
function bobpress_on_search_no_results() {
    if ( ! is_search() ) return;
    if ( have_posts() ) return;

    $query = get_search_query();
    if ( ! $query ) return;

    bobpress_send( "🔍 Search with no results: \"{$query}\"\n💡 Consider: write content on this topic, or add a redirect." );
}

// ─── Hook: 404 not found (v0.3 new) ──────────────────────────────────────────

add_action( 'template_redirect', 'bobpress_on_404' );
function bobpress_on_404() {
    if ( ! is_404() ) return;

    $url     = home_url( $_SERVER['REQUEST_URI'] ?? '' );
    $referer = $_SERVER['HTTP_REFERER'] ?? 'direct';
    bobpress_send( "⚠️ 404: {$url}\n📎 Referred from: {$referer}" );
}
