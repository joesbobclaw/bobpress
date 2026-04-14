<?php
/**
 * Plugin Name: Agent Loop
 * Plugin URI: https://wearebob.blog
 * Description: WordPress as AI middleware — puts a brain inside the loop. Every hook is a potential agent gate.
 * Version: 0.3.1
 * Author: Bob & Joe
 * Text Domain: agent-loop
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Settings ────────────────────────────────────────────────────────────────

add_action( 'admin_menu', 'agentloop_admin_menu' );
function agentloop_admin_menu() {
    add_options_page( 'Agent Loop', 'Agent Loop', 'manage_options', 'agent-loop', 'agentloop_settings_page' );
}

add_action( 'admin_init', 'agentloop_register_settings' );
function agentloop_register_settings() {
    register_setting( 'agentloop_settings', 'agentloop_endpoint' );
    register_setting( 'agentloop_settings', 'agentloop_channel' );
    register_setting( 'agentloop_settings', 'agentloop_username' );
    register_setting( 'agentloop_settings', 'agentloop_app_password' );
    register_setting( 'agentloop_settings', 'agentloop_site_label' );
}

function agentloop_settings_page() {
    ?>
    <div class="wrap">
        <h1>Agent Loop Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'agentloop_settings' ); ?>
            <table class="form-table">
                <tr><th>Agent Chat Endpoint</th><td>
                    <input type="url" name="agentloop_endpoint" value="<?php echo esc_attr( get_option('agentloop_endpoint') ); ?>" class="regular-text" placeholder="https://clawpress.blog/wp-json/agent-chat/v1/send" />
                </td></tr>
                <tr><th>Channel</th><td>
                    <input type="text" name="agentloop_channel" value="<?php echo esc_attr( get_option('agentloop_channel', 'ops') ); ?>" class="regular-text" />
                </td></tr>
                <tr><th>Username</th><td>
                    <input type="text" name="agentloop_username" value="<?php echo esc_attr( get_option('agentloop_username') ); ?>" class="regular-text" />
                </td></tr>
                <tr><th>App Password</th><td>
                    <input type="password" name="agentloop_app_password" value="<?php echo esc_attr( get_option('agentloop_app_password') ); ?>" class="regular-text" />
                </td></tr>
                <tr><th>Site Label</th><td>
                    <input type="text" name="agentloop_site_label" value="<?php echo esc_attr( get_option('agentloop_site_label', get_bloginfo('name')) ); ?>" class="regular-text" />
                    <p class="description">Label shown in event messages (e.g. "wearebob.blog")</p>
                </td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// ─── Core send function ───────────────────────────────────────────────────────

function agentloop_send( string $message, string $sender = 'WordPress' ) {
    $endpoint = get_option( 'agentloop_endpoint' );
    $username = get_option( 'agentloop_username' );
    $password = get_option( 'agentloop_app_password' );
    $channel  = get_option( 'agentloop_channel', 'ops' );

    if ( ! $endpoint || ! $username || ! $password ) return;

    $label = get_option( 'agentloop_site_label', get_bloginfo('name') );
    $full_message = "[Agent Loop — {$label}] " . $message;

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

function agentloop_detect_source(): string {
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

// ─── Visitor identity (cookie-based) ─────────────────────────────────────────

/**
 * Get or create a visitor UUID cookie.
 * Returns the UUID string (anonymous visitors) or null if headers already sent.
 */
function agentloop_get_visitor_id(): ?string {
    if ( is_admin() ) return null;

    $cookie_name = 'al_visitor';

    if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
        $uuid = sanitize_text_field( $_COOKIE[ $cookie_name ] );
        // Validate it looks like a UUID
        if ( preg_match( '/^[0-9a-f\-]{36}$/', $uuid ) ) {
            return $uuid;
        }
    }

    // Generate and set a new UUID
    if ( ! headers_sent() ) {
        $uuid = wp_generate_uuid4();
        setcookie( $cookie_name, $uuid, [
            'expires'  => time() + YEAR_IN_SECONDS,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[ $cookie_name ] = $uuid; // make available in same request
        return $uuid;
    }

    return null;
}

/**
 * Build visitor context string for event messages.
 * If logged in, returns WP user info. Otherwise returns cookie UUID.
 */
function agentloop_visitor_context(): string {
    $user_id = get_current_user_id();
    if ( $user_id ) {
        $user = get_userdata( $user_id );
        return "👤 {$user->user_login} (WP user #{$user_id})";
    }

    $uuid = agentloop_get_visitor_id();
    if ( $uuid ) {
        return "🍪 visitor:{$uuid}";
    }

    return '👤 anonymous';
}

// ─── Hook: pageview + identity stitching ─────────────────────────────────────

add_action( 'wp', 'agentloop_on_pageview' );
function agentloop_on_pageview() {
    if ( is_admin() || is_feed() || is_robots() ) return;
    if ( is_404() || is_search() ) return; // handled separately

    // Only track singular content (posts/pages) — not archives, to reduce noise
    if ( ! is_singular() ) return;

    $visitor  = agentloop_visitor_context();
    $post     = get_post();
    $title    = $post ? $post->post_title : 'unknown';
    $url      = home_url( $_SERVER['REQUEST_URI'] ?? '' );
    $referer  = $_SERVER['HTTP_REFERER'] ?? 'direct';
    $referer_label = ( $referer !== 'direct' ) ? parse_url( $referer, PHP_URL_HOST ) : 'direct';

    // Stitch cookie → WP user on login
    $user_id = get_current_user_id();
    $uuid    = $_COOKIE['al_visitor'] ?? null;
    $stitch  = ( $user_id && $uuid ) ? "\n🔗 Identity stitch: visitor:{$uuid} → WP user #{$user_id}" : '';

    agentloop_send( "📄 Pageview: \"{$title}\"\n{$visitor} | via {$referer_label}{$stitch}\n🔗 {$url}" );
}

// ─── Hook: save_post ─────────────────────────────────────────────────────────

add_action( 'save_post', 'agentloop_on_save_post', 10, 3 );
function agentloop_on_save_post( int $post_id, WP_Post $post, bool $update ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) ) return;
    if ( in_array( $post->post_type, ['revision', 'auto-draft', 'nav_menu_item', 'attachment'], true ) ) return;

    $action   = $update ? 'Post updated' : 'Post created';
    $source   = agentloop_detect_source();
    $author   = get_the_author_meta( 'display_name', $post->post_author );
    $edit_url = admin_url( "post.php?post={$post_id}&action=edit" );

    $msg  = "{$action}: {$post->post_title} ({$post->post_status})\n";
    $msg .= "👤 Author: {$author} | 📝 Type: {$post->post_type} | 🔌 Source: {$source}\n";
    $msg .= "🔗 {$edit_url}\n";
    $msg .= "Review: APPROVE, FLAG: <reason>, or NOTES: <notes>";

    agentloop_send( $msg );
}

// ─── Hook: transition_post_status ────────────────────────────────────────────

add_action( 'transition_post_status', 'agentloop_on_status_transition', 10, 3 );
function agentloop_on_status_transition( string $new_status, string $old_status, WP_Post $post ) {
    if ( $new_status === $old_status ) return;
    if ( in_array( $post->post_type, ['revision', 'auto-draft', 'nav_menu_item'], true ) ) return;
    $notable = [ 'publish', 'pending', 'private', 'trash' ];
    if ( ! in_array( $new_status, $notable, true ) ) return;

    $title = $post->post_title ?: "(untitled {$post->post_type})";
    agentloop_send( "Status change: \"{$title}\" → {$old_status} → {$new_status}" );
}

// ─── Hook: wp_insert_comment ─────────────────────────────────────────────────

add_action( 'wp_insert_comment', 'agentloop_on_new_comment', 10, 2 );
function agentloop_on_new_comment( int $comment_id, WP_Comment $comment ) {
    $post    = get_post( $comment->comment_post_ID );
    $title   = $post ? $post->post_title : 'unknown post';
    $author  = $comment->comment_author ?: 'Anonymous';
    $preview = mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, 100 );
    $status  = $comment->comment_approved === '1' ? 'approved' : ( $comment->comment_approved === 'spam' ? 'spam' : 'pending' );
    $visitor = agentloop_visitor_context();

    agentloop_send( "New comment on \"{$title}\" by {$author} ({$status})\n{$visitor}\n💬 \"{$preview}\"" );
}

// ─── Hook: comment status transitions ────────────────────────────────────────

add_action( 'transition_comment_status', 'agentloop_on_comment_status', 10, 3 );
function agentloop_on_comment_status( string $new_status, string $old_status, WP_Comment $comment ) {
    if ( $new_status === $old_status ) return;
    $post   = get_post( $comment->comment_post_ID );
    $title  = $post ? $post->post_title : 'unknown post';
    $author = $comment->comment_author ?: 'Anonymous';
    agentloop_send( "Comment by {$author} on \"{$title}\": {$old_status} → {$new_status}" );
}

// ─── Hook: user_register ─────────────────────────────────────────────────────

add_action( 'user_register', 'agentloop_on_user_register', 10, 1 );
function agentloop_on_user_register( int $user_id ) {
    $user    = get_userdata( $user_id );
    $source  = agentloop_detect_source();
    $uuid    = $_COOKIE['al_visitor'] ?? null;
    $stitch  = $uuid ? "\n🔗 Cookie stitch: visitor:{$uuid} → WP user #{$user_id}" : '';

    agentloop_send( "New user registered: {$user->user_login} ({$user->user_email}) via {$source}{$stitch}" );
}

// ─── Hook: wp_login ──────────────────────────────────────────────────────────

add_action( 'wp_login', 'agentloop_on_login', 10, 2 );
function agentloop_on_login( string $user_login, WP_User $user ) {
    if ( user_can( $user, 'edit_posts' ) ) {
        agentloop_send( "Login: {$user_login} (" . implode(', ', $user->roles) . ")" );
    }
}

// ─── Hook: wp_login_failed ───────────────────────────────────────────────────

add_action( 'wp_login_failed', 'agentloop_on_login_failed', 10, 1 );
function agentloop_on_login_failed( string $username ) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    agentloop_send( "⚠️ Failed login attempt for \"{$username}\" from {$ip}" );
}

// ─── Hook: Jetpack subscription ──────────────────────────────────────────────

add_action( 'jetpack_subscriptions_post_subscribe', 'agentloop_on_subscription', 10, 3 );
function agentloop_on_subscription( $email, $post_id, $first_time ) {
    $post    = $post_id ? get_post( $post_id ) : null;
    $title   = $post ? "\"{$post->post_title}\"" : 'site-wide';
    $type    = $first_time ? 'new subscriber' : 'existing subscriber re-subscribed';
    $visitor = agentloop_visitor_context();

    agentloop_send( "📧 Newsletter: {$type} from {$email} via {$title}\n{$visitor}" );
}

// ─── Hook: activated_plugin / deactivated_plugin ─────────────────────────────

add_action( 'activated_plugin', 'agentloop_on_plugin_activate', 10, 1 );
function agentloop_on_plugin_activate( string $plugin ) {
    $current_user = wp_get_current_user();
    agentloop_send( "🔌 Plugin activated: {$plugin} by {$current_user->user_login}" );
}

add_action( 'deactivated_plugin', 'agentloop_on_plugin_deactivate', 10, 1 );
function agentloop_on_plugin_deactivate( string $plugin ) {
    $current_user = wp_get_current_user();
    agentloop_send( "🔌 Plugin deactivated: {$plugin} by {$current_user->user_login}" );
}

// ─── Hook: Search with no results ────────────────────────────────────────────

add_action( 'template_redirect', 'agentloop_on_search_no_results' );
function agentloop_on_search_no_results() {
    if ( ! is_search() ) return;
    if ( have_posts() ) return;

    $query   = get_search_query();
    if ( ! $query ) return;
    $visitor = agentloop_visitor_context();

    agentloop_send( "🔍 Search: no results for \"{$query}\"\n{$visitor}\n💡 Consider writing content on this topic." );
}

// ─── Hook: 404 ───────────────────────────────────────────────────────────────

add_action( 'template_redirect', 'agentloop_on_404' );
function agentloop_on_404() {
    if ( ! is_404() ) return;

    $url     = home_url( $_SERVER['REQUEST_URI'] ?? '' );
    $referer = $_SERVER['HTTP_REFERER'] ?? 'direct';
    $visitor = agentloop_visitor_context();

    agentloop_send( "⚠️ 404: {$url}\n{$visitor} | referred from: {$referer}" );
}
