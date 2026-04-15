<?php
/**
 * Plugin Name: Agent Loop
 * Plugin URI: https://wearebob.blog
 * Description: WordPress as AI middleware — puts a brain inside the loop. Every hook is a potential agent gate.
 * Version: 0.3.3
 * Author: Bob & Joe
 * Text Domain: agent-loop
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'AL_COOKIE',   'al_visitor' );
define( 'AL_CPT',      'al_reader' );
define( 'AL_VERSION',  '0.3.2' );

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

// ─── Reader Profile CPT ───────────────────────────────────────────────────────

add_action( 'init', 'agentloop_register_cpt' );
function agentloop_register_cpt() {
    register_post_type( AL_CPT, [
        'label'           => 'Reader Profiles',
        'public'          => false,
        'show_ui'         => false,
        'capability_type' => 'post',
        'supports'        => [ 'title', 'custom-fields' ],
    ]);
}

/**
 * Get or create a reader profile post for the given UUID.
 * Returns the WP_Post object.
 */
function agentloop_get_or_create_profile( string $uuid ): ?WP_Post {
    $existing = get_posts([
        'post_type'      => AL_CPT,
        'post_status'    => 'publish',
        'title'          => $uuid,
        'posts_per_page' => 1,
        'no_found_rows'  => true,
    ]);

    if ( ! empty( $existing ) ) {
        return $existing[0];
    }

    $post_id = wp_insert_post([
        'post_type'   => AL_CPT,
        'post_status' => 'publish',
        'post_title'  => $uuid,
        'meta_input'  => [
            'al_uuid'               => $uuid,
            'al_wp_user_id'         => null,
            'al_first_seen'         => gmdate( 'c' ),
            'al_last_seen'          => gmdate( 'c' ),
            'al_visit_count'        => 0,
            'al_post_count'         => 0,
            'al_posts_read'         => wp_json_encode( [] ),
            'al_category_affinity'  => wp_json_encode( [] ),
            'al_tag_affinity'       => wp_json_encode( [] ),
            'al_first_source'       => '',
            'al_last_source'        => '',
            'al_sources_seen'       => wp_json_encode( [] ),
            'al_utm_campaigns'      => wp_json_encode( [] ),
            'al_searches'           => wp_json_encode( [] ),
            'al_no_result_searches' => wp_json_encode( [] ),
            'al_subscribed'         => false,
            'al_subscribe_post_id'  => null,
            'al_commented'          => false,
            'al_registered'         => false,
        ],
    ]);

    return $post_id ? get_post( $post_id ) : null;
}

/**
 * Get full profile array for a UUID.
 */
function agentloop_get_profile( string $uuid ): array {
    $post = agentloop_get_or_create_profile( $uuid );
    if ( ! $post ) return [];

    return [
        'post_id'              => $post->ID,
        'uuid'                 => get_post_meta( $post->ID, 'al_uuid', true ),
        'wp_user_id'           => get_post_meta( $post->ID, 'al_wp_user_id', true ),
        'first_seen'           => get_post_meta( $post->ID, 'al_first_seen', true ),
        'last_seen'            => get_post_meta( $post->ID, 'al_last_seen', true ),
        'visit_count'          => (int) get_post_meta( $post->ID, 'al_visit_count', true ),
        'post_count'           => (int) get_post_meta( $post->ID, 'al_post_count', true ),
        'posts_read'           => json_decode( get_post_meta( $post->ID, 'al_posts_read', true ) ?: '[]', true ),
        'category_affinity'    => json_decode( get_post_meta( $post->ID, 'al_category_affinity', true ) ?: '[]', true ),
        'tag_affinity'         => json_decode( get_post_meta( $post->ID, 'al_tag_affinity', true ) ?: '[]', true ),
        'first_source'         => get_post_meta( $post->ID, 'al_first_source', true ),
        'last_source'          => get_post_meta( $post->ID, 'al_last_source', true ),
        'sources_seen'         => json_decode( get_post_meta( $post->ID, 'al_sources_seen', true ) ?: '[]', true ),
        'utm_campaigns'        => json_decode( get_post_meta( $post->ID, 'al_utm_campaigns', true ) ?: '[]', true ),
        'searches'             => json_decode( get_post_meta( $post->ID, 'al_searches', true ) ?: '[]', true ),
        'no_result_searches'   => json_decode( get_post_meta( $post->ID, 'al_no_result_searches', true ) ?: '[]', true ),
        'subscribed'           => (bool) get_post_meta( $post->ID, 'al_subscribed', true ),
        'subscribe_post_id'    => get_post_meta( $post->ID, 'al_subscribe_post_id', true ),
        'commented'            => (bool) get_post_meta( $post->ID, 'al_commented', true ),
        'registered'           => (bool) get_post_meta( $post->ID, 'al_registered', true ),
    ];
}

/**
 * Update specific fields on a reader profile.
 */
function agentloop_update_profile( string $uuid, array $updates ) {
    $post = agentloop_get_or_create_profile( $uuid );
    if ( ! $post ) return;

    foreach ( $updates as $key => $value ) {
        $meta_key = 'al_' . $key;
        $stored   = is_array( $value ) ? wp_json_encode( $value ) : $value;
        update_post_meta( $post->ID, $meta_key, $stored );
    }
}

/**
 * Classify referrer into a human-readable source label.
 */
function agentloop_classify_source( string $referrer, array $utm ): string {
    if ( ! empty( $utm['utm_source'] ) ) {
        $s = strtolower( $utm['utm_source'] );
        if ( $s === 'newsletter' || $s === 'email' ) return 'newsletter';
        return $s;
    }

    if ( empty( $referrer ) || $referrer === 'direct' ) return 'direct';

    $host = strtolower( parse_url( $referrer, PHP_URL_HOST ) ?? '' );
    $host = preg_replace( '/^www\./', '', $host );

    if ( str_contains( $host, 'google' ) )    return 'google';
    if ( str_contains( $host, 'bing' ) )      return 'bing';
    if ( str_contains( $host, 'twitter' ) || str_contains( $host, 'x.com' ) ) return 'twitter';
    if ( str_contains( $host, 'facebook' ) )  return 'facebook';
    if ( str_contains( $host, 'linkedin' ) )  return 'linkedin';
    if ( str_contains( $host, 'reddit' ) )    return 'reddit';
    if ( str_contains( $host, 'flipboard' ) ) return 'flipboard';
    if ( str_contains( $host, 'apple' ) )     return 'apple-news';

    return $host ?: 'direct';
}

/**
 * Build a readable profile summary for agent messages.
 */
function agentloop_profile_summary( array $profile ): string {
    $parts = [];

    $parts[] = "visit #{$profile['visit_count']} | {$profile['post_count']} posts read";

    if ( $profile['first_source'] ) {
        $src = $profile['first_source'] === $profile['last_source']
            ? $profile['first_source']
            : "first:{$profile['first_source']} last:{$profile['last_source']}";
        $parts[] = "src:{$src}";
    }

    if ( ! empty( $profile['category_affinity'] ) ) {
        arsort( $profile['category_affinity'] );
        $top = array_slice( $profile['category_affinity'], 0, 3, true );
        $cats = implode( ', ', array_map( fn($k,$v) => "{$k}({$v})", array_keys($top), $top ) );
        $parts[] = "affinity:{$cats}";
    }

    if ( ! empty( $profile['searches'] ) ) {
        $parts[] = 'searched:"' . implode( '", "', array_slice( $profile['searches'], -3 ) ) . '"';
    }

    if ( ! empty( $profile['no_result_searches'] ) ) {
        $parts[] = 'no-results:"' . implode( '", "', array_slice( $profile['no_result_searches'], -3 ) ) . '"';
    }

    $flags = [];
    if ( $profile['subscribed'] )  $flags[] = 'subscriber';
    if ( $profile['commented'] )   $flags[] = 'commenter';
    if ( $profile['registered'] )  $flags[] = 'registered';
    if ( $profile['wp_user_id'] )  $flags[] = "WP#{$profile['wp_user_id']}";
    if ( ! empty( $flags ) ) $parts[] = implode( ' ', $flags );

    return implode( ' | ', $parts );
}

// ─── Core send function ───────────────────────────────────────────────────────

function agentloop_send( string $message, string $sender = 'WordPress' ) {
    $endpoint = get_option( 'agentloop_endpoint' );
    $username = get_option( 'agentloop_username' );
    $password = get_option( 'agentloop_app_password' );
    $channel  = get_option( 'agentloop_channel', 'ops' );

    if ( ! $endpoint || ! $username || ! $password ) return;

    $label        = get_option( 'agentloop_site_label', get_bloginfo('name') );
    $full_message = "[Agent Loop — {$label}] " . $message;

    wp_remote_post( $endpoint, [
        'blocking' => false,
        'timeout'  => 5,
        'headers'  => [
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

// ─── Source / visitor helpers ─────────────────────────────────────────────────

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

function agentloop_get_uuid(): ?string {
    if ( ! empty( $_COOKIE[ AL_COOKIE ] ) ) {
        $uuid = sanitize_text_field( $_COOKIE[ AL_COOKIE ] );
        if ( preg_match( '/^[0-9a-f\-]{36}$/', $uuid ) ) return $uuid;
    }
    return null;
}

function agentloop_visitor_context(): string {
    $user_id = get_current_user_id();
    if ( $user_id ) {
        $user = get_userdata( $user_id );
        return "👤 {$user->user_login} (WP#{$user_id})";
    }
    $uuid = agentloop_get_uuid();
    return $uuid ? "🍪 visitor:{$uuid}" : '👤 anonymous';
}

// ─── JS Beacon (cache-busting pageview tracker) ───────────────────────────────

add_action( 'wp_enqueue_scripts', 'agentloop_enqueue_beacon' );
function agentloop_enqueue_beacon() {
    if ( is_admin() ) return;

    // Inline beacon script — no external file needed
    $post_id   = is_singular() ? get_the_ID() : 0;
    $referrer  = ''; // captured client-side from document.referrer
    $rest_url  = esc_url( rest_url( 'agent-loop/v1/beacon' ) );
    $nonce     = wp_create_nonce( 'al_beacon' );

    $script = "
(function() {
    var data = {
        post_id:  " . (int) $post_id . ",
        referrer: document.referrer || '',
        utm:      Object.fromEntries(new URLSearchParams(window.location.search)),
        path:     window.location.pathname,
        title:    document.title
    };
    fetch('" . $rest_url . "', {
        method:      'POST',
        credentials: 'include',
        headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': '" . $nonce . "' },
        body:         JSON.stringify(data),
        keepalive:    true
    });
})();
";
    wp_add_inline_script( 'jquery-core', $script );

    // Fallback if jQuery not loaded
    wp_add_inline_script( 'wp-hooks', $script );
}

// ─── REST Beacon Endpoint ─────────────────────────────────────────────────────

add_action( 'rest_api_init', 'agentloop_register_beacon_endpoint' );
function agentloop_register_beacon_endpoint() {
    register_rest_route( 'agent-loop/v1', '/beacon', [
        'methods'             => 'POST',
        'callback'            => 'agentloop_handle_beacon',
        'permission_callback' => '__return_true', // public — cookie is auth
    ]);
}

function agentloop_handle_beacon( WP_REST_Request $request ): WP_REST_Response {
    $post_id  = (int) $request->get_param('post_id');
    $referrer = sanitize_text_field( $request->get_param('referrer') ?? '' );
    $utm      = (array) $request->get_param('utm');
    $path     = sanitize_text_field( $request->get_param('path') ?? '' );

    // Get or create UUID
    $uuid = agentloop_get_uuid();
    $is_new = false;
    if ( ! $uuid ) {
        $uuid   = wp_generate_uuid4();
        $is_new = true;
    }

    // Classify source
    $source = agentloop_classify_source( $referrer, $utm );

    // Load profile
    $profile = agentloop_get_profile( $uuid );

    // Update fields
    $visits   = $profile['visit_count'] + 1;
    $sources  = $profile['sources_seen'];
    if ( ! in_array( $source, $sources, true ) ) $sources[] = $source;

    $utm_campaigns = $profile['utm_campaigns'];
    if ( ! empty( $utm['utm_campaign'] ) && ! in_array( $utm['utm_campaign'], $utm_campaigns, true ) ) {
        $utm_campaigns[] = sanitize_text_field( $utm['utm_campaign'] );
    }

    $posts_read      = $profile['posts_read'];
    $cat_affinity    = $profile['category_affinity'];
    $tag_affinity    = $profile['tag_affinity'];
    $post_count      = $profile['post_count'];

    if ( $post_id && ! in_array( $post_id, $posts_read, true ) ) {
        $posts_read[] = $post_id;
        $post_count++;

        // Category affinity
        $cats = get_the_category( $post_id );
        foreach ( $cats as $cat ) {
            $slug = $cat->slug;
            $cat_affinity[ $slug ] = ( $cat_affinity[ $slug ] ?? 0 ) + 1;
        }

        // Tag affinity
        $tags = get_the_tags( $post_id );
        if ( $tags ) {
            foreach ( $tags as $tag ) {
                $slug = $tag->slug;
                $tag_affinity[ $slug ] = ( $tag_affinity[ $slug ] ?? 0 ) + 1;
            }
        }
    }

    $updates = [
        'last_seen'          => gmdate( 'c' ),
        'visit_count'        => $visits,
        'post_count'         => $post_count,
        'posts_read'         => $posts_read,
        'category_affinity'  => $cat_affinity,
        'tag_affinity'       => $tag_affinity,
        'last_source'        => $source,
        'sources_seen'       => $sources,
        'utm_campaigns'      => $utm_campaigns,
    ];

    if ( ! $profile['first_source'] ) {
        $updates['first_source'] = $source;
    }

    // Stitch to WP user if logged in
    $wp_user_id = get_current_user_id();
    if ( $wp_user_id && ! $profile['wp_user_id'] ) {
        $updates['wp_user_id'] = $wp_user_id;
        agentloop_send( "🔗 Identity stitch: visitor:{$uuid} → WP#{$wp_user_id} (pageview)" );
    }

    agentloop_update_profile( $uuid, $updates );

    // Refresh profile for summary
    $profile = agentloop_get_profile( $uuid );

    // Send notable events to agent (not every pageview — only on new visitors or milestones)
    if ( $is_new ) {
        $post_title = $post_id ? get_the_title( $post_id ) : $path;
        agentloop_send( "👋 New visitor on \"{$post_title}\"\n🍪 visitor:{$uuid} | src:{$source}" );
    } elseif ( $post_id && $visits % 5 === 0 ) {
        // Every 5th visit — send a profile update
        $post_title = get_the_title( $post_id );
        $summary    = agentloop_profile_summary( $profile );
        agentloop_send( "📊 Reader milestone: {$visits} visits\n🍪 visitor:{$uuid}\n{$summary}" );
    }

    // Set cookie in response
    $response = new WP_REST_Response( [ 'ok' => true, 'uuid' => $uuid ], 200 );
    if ( $is_new ) {
        $response->header( 'Set-Cookie',
            AL_COOKIE . '=' . $uuid .
            '; Max-Age=' . YEAR_IN_SECONDS .
            '; Path=/' .
            '; SameSite=Lax' .
            ( is_ssl() ? '; Secure' : '' ) .
            '; HttpOnly'
        );
    }

    return $response;
}

// ─── Hook: save_post ─────────────────────────────────────────────────────────

add_action( 'save_post', 'agentloop_on_save_post', 10, 3 );
function agentloop_on_save_post( int $post_id, WP_Post $post, bool $update ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    if ( wp_is_post_autosave( $post_id ) ) return;
    if ( in_array( $post->post_type, ['revision', 'auto-draft', 'nav_menu_item', 'attachment', AL_CPT], true ) ) return;

    $action   = $update ? 'Post updated' : 'Post created';
    $source   = agentloop_detect_source();
    $author   = get_the_author_meta( 'display_name', $post->post_author );
    $edit_url = admin_url( "post.php?post={$post_id}&action=edit" );

    $msg  = "{$action}: {$post->post_title} ({$post->post_status})\n";
    $msg .= "👤 {$author} | 📝 {$post->post_type} | 🔌 {$source}\n";
    $msg .= "🔗 {$edit_url}";

    agentloop_send( $msg );
}

// ─── Hook: transition_post_status ────────────────────────────────────────────

add_action( 'transition_post_status', 'agentloop_on_status_transition', 10, 3 );
function agentloop_on_status_transition( string $new_status, string $old_status, WP_Post $post ) {
    if ( $new_status === $old_status ) return;
    if ( in_array( $post->post_type, ['revision', 'auto-draft', 'nav_menu_item', AL_CPT], true ) ) return;
    $notable = [ 'publish', 'pending', 'private', 'trash' ];
    if ( ! in_array( $new_status, $notable, true ) ) return;

    $title = $post->post_title ?: "(untitled {$post->post_type})";
    agentloop_send( "Status: \"{$title}\" → {$old_status} → {$new_status}" );
}

// ─── Hook: wp_insert_comment ─────────────────────────────────────────────────

add_action( 'wp_insert_comment', 'agentloop_on_new_comment', 10, 2 );
function agentloop_on_new_comment( int $comment_id, WP_Comment $comment ) {
    $post    = get_post( $comment->comment_post_ID );
    $title   = $post ? $post->post_title : 'unknown post';
    $author  = $comment->comment_author ?: 'Anonymous';
    $preview = mb_substr( wp_strip_all_tags( $comment->comment_content ), 0, 100 );
    $status  = $comment->comment_approved === '1' ? 'approved' : ( $comment->comment_approved === 'spam' ? 'spam' : 'pending' );

    $uuid    = agentloop_get_uuid();
    $profile_line = '';
    if ( $uuid ) {
        $profile = agentloop_get_profile( $uuid );
        agentloop_update_profile( $uuid, [ 'commented' => true ] );
        $profile_line = "\n📊 " . agentloop_profile_summary( $profile );
    }

    agentloop_send( "💬 Comment on \"{$title}\" by {$author} ({$status})\n\"{$preview}\"{$profile_line}" );
}

// ─── Hook: comment status transitions ────────────────────────────────────────

add_action( 'transition_comment_status', 'agentloop_on_comment_status', 10, 3 );
function agentloop_on_comment_status( string $new_status, string $old_status, WP_Comment $comment ) {
    if ( $new_status === $old_status ) return;
    $post   = get_post( $comment->comment_post_ID );
    $title  = $post ? $post->post_title : 'unknown post';
    agentloop_send( "Comment by {$comment->comment_author} on \"{$title}\": {$old_status} → {$new_status}" );
}

// ─── Hook: user_register ─────────────────────────────────────────────────────

add_action( 'user_register', 'agentloop_on_user_register', 10, 1 );
function agentloop_on_user_register( int $user_id ) {
    $user   = get_userdata( $user_id );
    $source = agentloop_detect_source();
    $uuid   = agentloop_get_uuid();

    $stitch = '';
    if ( $uuid ) {
        $profile = agentloop_get_profile( $uuid );
        agentloop_update_profile( $uuid, [ 'wp_user_id' => $user_id, 'registered' => true ] );
        $summary = agentloop_profile_summary( $profile );
        $stitch  = "\n🔗 Stitch: visitor:{$uuid} → WP#{$user_id}\n📊 {$summary}";
    }

    agentloop_send( "👤 New user: {$user->user_login} ({$user->user_email}) via {$source}{$stitch}" );
}

// ─── Hook: wp_login ──────────────────────────────────────────────────────────

add_action( 'wp_login', 'agentloop_on_login', 10, 2 );
function agentloop_on_login( string $user_login, WP_User $user ) {
    if ( ! user_can( $user, 'edit_posts' ) ) return;
    agentloop_send( "🔑 Login: {$user_login} (" . implode( ', ', $user->roles ) . ")" );
}

// ─── Hook: wp_login_failed ───────────────────────────────────────────────────

add_action( 'wp_login_failed', 'agentloop_on_login_failed', 10, 1 );
function agentloop_on_login_failed( string $username ) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    agentloop_send( "⚠️ Failed login: \"{$username}\" from {$ip}" );
}

// ─── Hook: Jetpack subscription ──────────────────────────────────────────────

add_action( 'jetpack_subscriptions_post_subscribe', 'agentloop_on_subscription', 10, 3 );
function agentloop_on_subscription( $email, $post_id, $first_time ) {
    $post  = $post_id ? get_post( $post_id ) : null;
    $title = $post ? "\"{$post->post_title}\"" : 'site-wide';
    $type  = $first_time ? 'new subscriber' : 're-subscribed';

    $uuid         = agentloop_get_uuid();
    $profile_line = '';
    if ( $uuid ) {
        $profile = agentloop_get_profile( $uuid );
        agentloop_update_profile( $uuid, [ 'subscribed' => true, 'subscribe_post_id' => $post_id ] );
        $profile_line = "\n📊 " . agentloop_profile_summary( $profile );
    }

    agentloop_send( "📧 {$type}: {$email} via {$title}{$profile_line}" );
}

// ─── Hook: activated_plugin / deactivated_plugin ─────────────────────────────

add_action( 'activated_plugin', 'agentloop_on_plugin_activate', 10, 1 );
function agentloop_on_plugin_activate( string $plugin ) {
    agentloop_send( "🔌 Plugin activated: {$plugin} by " . wp_get_current_user()->user_login );
}

add_action( 'deactivated_plugin', 'agentloop_on_plugin_deactivate', 10, 1 );
function agentloop_on_plugin_deactivate( string $plugin ) {
    agentloop_send( "🔌 Plugin deactivated: {$plugin} by " . wp_get_current_user()->user_login );
}

// ─── Hook: Search with no results ────────────────────────────────────────────

add_action( 'template_redirect', 'agentloop_on_search_no_results' );
function agentloop_on_search_no_results() {
    if ( ! is_search() || have_posts() ) return;
    $query = get_search_query();
    if ( ! $query ) return;

    $uuid = agentloop_get_uuid();
    if ( $uuid ) {
        $profile  = agentloop_get_profile( $uuid );
        $searches = $profile['no_result_searches'];
        if ( ! in_array( $query, $searches, true ) ) {
            $searches[] = $query;
            agentloop_update_profile( $uuid, [ 'no_result_searches' => $searches ] );
        }
        $summary = agentloop_profile_summary( agentloop_get_profile( $uuid ) );
        agentloop_send( "🔍 No results: \"{$query}\"\n🍪 visitor:{$uuid}\n📊 {$summary}" );
    } else {
        agentloop_send( "🔍 No results: \"{$query}\" (anonymous)" );
    }
}

// ─── Hook: 404 ───────────────────────────────────────────────────────────────

add_action( 'template_redirect', 'agentloop_on_404' );
function agentloop_on_404() {
    if ( ! is_404() ) return;
    $url     = home_url( $_SERVER['REQUEST_URI'] ?? '' );
    $referer = $_SERVER['HTTP_REFERER'] ?? 'direct';
    $visitor = agentloop_visitor_context();
    agentloop_send( "⚠️ 404: {$url}\n{$visitor} | from: {$referer}" );
}

// ─── Hook: Menus ─────────────────────────────────────────────────────────────

add_action( 'wp_update_nav_menu', 'agentloop_on_menu_update', 10, 1 );
function agentloop_on_menu_update( int $menu_id ) {
    $menu = wp_get_nav_menu_object( $menu_id );
    $name = $menu ? $menu->name : "menu #{$menu_id}";
    $user = wp_get_current_user()->user_login;
    agentloop_send( "🗂️ Menu updated: \"{$name}\" by {$user}" );
}

add_action( 'wp_delete_nav_menu', 'agentloop_on_menu_delete', 10, 1 );
function agentloop_on_menu_delete( int $menu_id ) {
    $user = wp_get_current_user()->user_login;
    agentloop_send( "🗂️ Menu deleted: #{$menu_id} by {$user}" );
}

// ─── Hook: Widgets ───────────────────────────────────────────────────────────

add_action( 'update_option_sidebars_widgets', 'agentloop_on_sidebars_update', 10, 2 );
function agentloop_on_sidebars_update( $old, $new ) {
    $user = wp_get_current_user()->user_login;
    agentloop_send( "🧩 Sidebar/widget layout changed by {$user}" );
}

// ─── Hook: Option watchlist ───────────────────────────────────────────────────

define( 'AL_WATCHED_OPTIONS', [
    'blogname'             => 'Site title',
    'blogdescription'      => 'Tagline',
    'siteurl'              => 'Site URL',
    'home'                 => 'Home URL',
    'admin_email'          => 'Admin email',
    'default_role'         => 'Default user role',
    'permalink_structure'  => 'Permalink structure',
    'users_can_register'   => 'Open registration',
    'default_comment_status' => 'Default comment status',
    'comment_moderation'   => 'Comment moderation',
] );

add_action( 'updated_option', 'agentloop_on_option_update', 10, 3 );
function agentloop_on_option_update( string $option, $old_value, $new_value ) {
    $watched = AL_WATCHED_OPTIONS;
    if ( ! isset( $watched[ $option ] ) ) return;
    if ( $old_value === $new_value ) return;

    $label    = $watched[ $option ];
    $old_str  = is_scalar( $old_value ) ? (string) $old_value : wp_json_encode( $old_value );
    $new_str  = is_scalar( $new_value ) ? (string) $new_value : wp_json_encode( $new_value );
    $user     = wp_get_current_user()->user_login ?: 'system';

    agentloop_send( "⚙️ Setting changed: {$label}\n\"{$old_str}\" → \"{$new_str}\"\n👤 {$user}" );
}

// ─── Hook: Theme switch ───────────────────────────────────────────────────────

add_action( 'switch_theme', 'agentloop_on_theme_switch', 10, 3 );
function agentloop_on_theme_switch( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ) {
    $user = wp_get_current_user()->user_login;
    agentloop_send( "🎨 Theme switched: \"{$old_theme->get('Name')}\" → \"{$new_name}\" by {$user}" );
}

add_action( 'customize_save_after', 'agentloop_on_customizer_save', 10, 1 );
function agentloop_on_customizer_save( $manager ) {
    $user = wp_get_current_user()->user_login;
    agentloop_send( "🎨 Customizer saved by {$user}" );
}

// ─── Hook: Media ─────────────────────────────────────────────────────────────

add_action( 'add_attachment', 'agentloop_on_attachment_add', 10, 1 );
function agentloop_on_attachment_add( int $post_id ) {
    $post     = get_post( $post_id );
    $filename = basename( get_attached_file( $post_id ) ?: '' );
    $mime     = $post->post_mime_type ?? 'unknown';
    $user     = wp_get_current_user()->user_login;
    agentloop_send( "📎 File uploaded: {$filename} ({$mime}) by {$user}" );
}

add_action( 'delete_attachment', 'agentloop_on_attachment_delete', 10, 1 );
function agentloop_on_attachment_delete( int $post_id ) {
    $filename = basename( get_attached_file( $post_id ) ?: "attachment #{$post_id}" );
    $user     = wp_get_current_user()->user_login;
    agentloop_send( "🗑️ File deleted: {$filename} by {$user}" );
}

// ─── Hook: Updates ───────────────────────────────────────────────────────────

add_action( 'upgrader_process_complete', 'agentloop_on_upgrade', 10, 2 );
function agentloop_on_upgrade( $upgrader, array $options ) {
    $type   = $options['type'] ?? 'unknown';
    $action = $options['action'] ?? 'update';
    $user   = wp_get_current_user()->user_login ?: 'auto-update';

    if ( $type === 'plugin' ) {
        $plugins = $options['plugins'] ?? [];
        if ( empty( $plugins ) && isset( $options['plugin'] ) ) {
            $plugins = [ $options['plugin'] ];
        }
        $list = implode( ', ', array_map( fn($p) => dirname($p) ?: $p, $plugins ) );
        agentloop_send( "🔄 Plugin {$action}: {$list} by {$user}" );
    } elseif ( $type === 'theme' ) {
        $themes = $options['themes'] ?? [];
        $list   = implode( ', ', $themes );
        agentloop_send( "🔄 Theme {$action}: {$list} by {$user}" );
    } elseif ( $type === 'core' ) {
        global $wp_version;
        agentloop_send( "🔄 WordPress core {$action} to {$wp_version} by {$user}" );
    }
}

// ─── Hook: User / role changes ────────────────────────────────────────────────

add_action( 'set_user_role', 'agentloop_on_role_change', 10, 3 );
function agentloop_on_role_change( int $user_id, string $role, array $old_roles ) {
    $user     = get_userdata( $user_id );
    $old      = implode( ', ', $old_roles ) ?: 'none';
    $changer  = wp_get_current_user()->user_login ?: 'system';
    agentloop_send( "👤 Role change: {$user->user_login} → {$role} (was: {$old}) by {$changer}" );
}

add_action( 'delete_user', 'agentloop_on_user_delete', 10, 1 );
function agentloop_on_user_delete( int $user_id ) {
    $user    = get_userdata( $user_id );
    $login   = $user ? $user->user_login : "#{$user_id}";
    $changer = wp_get_current_user()->user_login ?: 'system';
    agentloop_send( "🗑️ User deleted: {$login} by {$changer}" );
}

add_action( 'profile_update', 'agentloop_on_profile_update', 10, 2 );
function agentloop_on_profile_update( int $user_id, WP_User $old_user ) {
    $new_user = get_userdata( $user_id );
    $changer  = wp_get_current_user()->user_login ?: 'self';

    // Only report security-relevant changes
    $changes = [];
    if ( $old_user->user_email !== $new_user->user_email ) {
        $changes[] = "email: {$old_user->user_email} → {$new_user->user_email}";
    }
    if ( $old_user->user_pass !== $new_user->user_pass ) {
        $changes[] = 'password changed';
    }

    if ( empty( $changes ) ) return;
    agentloop_send( "👤 Profile update: {$new_user->user_login} — " . implode( ', ', $changes ) . " by {$changer}" );
}
