<?php
/**
 * Plugin Name:       Anime Sync Pro
 * Plugin URI:        https://github.com/your-repo/anime-sync-pro
 * Description:       從 AniList、MyAnimeList（Jikan）、Bangumi、AnimeThemes 自動同步動畫資料至 WordPress 自訂文章類型，支援繁體中文轉換、三層 ID 映射、批次排程與審核佇列。
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Your Name
 * Author URI:        https://yoursite.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       anime-sync-pro
 * Domain Path:       /languages
 *
 * @package Anime_Sync_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ═══════════════════════════════════════════════════════════
   0. VERSION GATE — PHP 8.0+ required
══════════════════════════════════════════════════════════ */
if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p>'
             . sprintf(
                 /* translators: %s: minimum PHP version */
                 esc_html__( 'Anime Sync Pro 需要 PHP %s 或以上版本，請聯絡主機商升級。', 'anime-sync-pro' ),
                 '8.0'
               )
             . '</p></div>';
    } );
    return;
}

/* ═══════════════════════════════════════════════════════════
   1. CONSTANTS
══════════════════════════════════════════════════════════ */
define( 'ANIME_SYNC_PRO_VERSION',  '1.0.0' );
define( 'ANIME_SYNC_PRO_FILE',     __FILE__ );
define( 'ANIME_SYNC_PRO_DIR',      plugin_dir_path( __FILE__ ) );
define( 'ANIME_SYNC_PRO_URL',      plugin_dir_url( __FILE__ ) );
define( 'ANIME_SYNC_PRO_BASENAME', plugin_basename( __FILE__ ) );

/* ═══════════════════════════════════════════════════════════
   2. AUTOLOADER
   Loads classes from includes/ and admin/ by convention:
     Anime_Sync_Foo_Bar  →  includes/class-foo-bar.php
     Anime_Sync_Admin*   →  admin/class-*.php
     Anime_Sync_Frontend →  public/class-frontend.php
══════════════════════════════════════════════════════════ */
spl_autoload_register( function ( string $class_name ) {
    if ( strpos( $class_name, 'Anime_Sync_' ) !== 0 ) {
        return;
    }

    // Convert class name → file name
    // e.g. Anime_Sync_ID_Mapper   → class-id-mapper.php
    //      Anime_Sync_CN_Converter → class-cn-converter.php
    $without_prefix = substr( $class_name, strlen( 'Anime_Sync_' ) );
    $file_base      = 'class-' . strtolower( str_replace( '_', '-', $without_prefix ) ) . '.php';

    $candidates = [
        ANIME_SYNC_PRO_DIR . 'includes/' . $file_base,
        ANIME_SYNC_PRO_DIR . 'admin/'    . $file_base,
        ANIME_SYNC_PRO_DIR . 'public/'   . $file_base,
    ];

    foreach ( $candidates as $path ) {
        if ( file_exists( $path ) ) {
            require_once $path;
            return;
        }
    }
} );

/* ═══════════════════════════════════════════════════════════
   3. ACTIVATION / DEACTIVATION / UNINSTALL HOOKS
══════════════════════════════════════════════════════════ */
register_activation_hook(   __FILE__, [ 'Anime_Sync_Admin', 'on_activate'   ] );
register_deactivation_hook( __FILE__, [ 'Anime_Sync_Admin', 'on_deactivate' ] );

/* Uninstall: remove all plugin data */
register_uninstall_hook( __FILE__, 'anime_sync_pro_uninstall' );

/**
 * Full clean-up on plugin deletion.
 * Removes: CPT posts + meta, plugin options, upload directory, cron events.
 */
function anime_sync_pro_uninstall(): void {
    if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
        return;
    }

    // Only run if "delete data on uninstall" option is enabled
    if ( ! get_option( 'anime_sync_delete_on_uninstall', false ) ) {
        return;
    }

    global $wpdb;

    /* ── Delete all anime posts + their meta ── */
    $post_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'anime'"
    );
    foreach ( $post_ids as $pid ) {
        wp_delete_post( (int) $pid, true );
    }

    /* ── Remove plugin options ── */
    $option_keys = [
        'anime_sync_site_name',
        'anime_sync_site_url',
        'anime_sync_daily_hour_taipei',
        'anime_sync_weekly_day',
        'anime_sync_weekly_hour_taipei',
        'anime_sync_rating_batch_size',
        'anime_sync_log_retention_days',
        'anime_sync_debug_mode',
        'anime_sync_cache_ttl_hours',
        'anime_sync_delete_on_uninstall',
        'anime_map_last_updated',
        'anime_map_entry_count',
        'anime_sync_rating_queue',
        'anime_sync_last_daily_run',
        'anime_sync_last_weekly_run',
    ];
    foreach ( $option_keys as $key ) {
        delete_option( $key );
    }

    /* ── Remove upload directory ── */
    $upload_dir = wp_upload_dir();
    $plugin_dir = $upload_dir['basedir'] . '/anime-sync-pro/';
    if ( is_dir( $plugin_dir ) ) {
        anime_sync_pro_rmdir_recursive( $plugin_dir );
    }

    /* ── Flush rewrite rules ── */
    flush_rewrite_rules();
}

/**
 * Recursively remove a directory (used during uninstall only).
 *
 * @param string $dir
 */
function anime_sync_pro_rmdir_recursive( string $dir ): void {
    if ( ! is_dir( $dir ) ) {
        return;
    }
    $items = array_diff( scandir( $dir ), [ '.', '..' ] );
    foreach ( $items as $item ) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir( $path ) ? anime_sync_pro_rmdir_recursive( $path ) : unlink( $path );
    }
    rmdir( $dir );
}

/* ═══════════════════════════════════════════════════════════
   4. CUSTOM POST TYPE — 'anime'
══════════════════════════════════════════════════════════ */
add_action( 'init', 'anime_sync_pro_register_cpt', 0 );

/**
 * Register the 'anime' Custom Post Type.
 * Priority 0 ensures it runs before ACF field registration.
 */
function anime_sync_pro_register_cpt(): void {
    $labels = [
        'name'                  => _x( '動畫',          'post type general name', 'anime-sync-pro' ),
        'singular_name'         => _x( '動畫',          'post type singular name', 'anime-sync-pro' ),
        'menu_name'             => _x( '動畫庫',         'admin menu',             'anime-sync-pro' ),
        'name_admin_bar'        => _x( '動畫',          'add new on admin bar',   'anime-sync-pro' ),
        'add_new'               => __( '新增動畫',       'anime-sync-pro' ),
        'add_new_item'          => __( '新增動畫',       'anime-sync-pro' ),
        'new_item'              => __( '新動畫',         'anime-sync-pro' ),
        'edit_item'             => __( '編輯動畫',       'anime-sync-pro' ),
        'view_item'             => __( '查看動畫',       'anime-sync-pro' ),
        'all_items'             => __( '所有動畫',       'anime-sync-pro' ),
        'search_items'          => __( '搜尋動畫',       'anime-sync-pro' ),
        'parent_item_colon'     => __( '上層動畫：',     'anime-sync-pro' ),
        'not_found'             => __( '找不到動畫。',   'anime-sync-pro' ),
        'not_found_in_trash'    => __( '回收桶中沒有動畫。', 'anime-sync-pro' ),
        'featured_image'        => __( '封面圖片',       'anime-sync-pro' ),
        'set_featured_image'    => __( '設定封面圖片',   'anime-sync-pro' ),
        'remove_featured_image' => __( '移除封面圖片',   'anime-sync-pro' ),
        'use_featured_image'    => __( '使用封面圖片',   'anime-sync-pro' ),
        'archives'              => __( '動畫庫',         'anime-sync-pro' ),
        'insert_into_item'      => __( '插入至動畫',     'anime-sync-pro' ),
        'uploaded_to_this_item' => __( '上傳至此動畫',   'anime-sync-pro' ),
        'items_list'            => __( '動畫列表',       'anime-sync-pro' ),
        'items_list_navigation' => __( '動畫列表導覽',   'anime-sync-pro' ),
        'filter_items_list'     => __( '篩選動畫列表',   'anime-sync-pro' ),
    ];

    $args = [
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => true,
        'show_in_rest'       => true,   // Gutenberg + REST API support
        'query_var'          => true,
        'rewrite'            => [
            'slug'       => 'anime',
            'with_front' => false,
            'feeds'      => true,
            'pages'      => true,
        ],
        'capability_type'    => [ 'anime', 'animes' ],
        'map_meta_cap'       => true,
        'has_archive'        => 'anime',
        'hierarchical'       => false,
        'menu_position'      => 5,
        'menu_icon'          => 'dashicons-format-video',
        'supports'           => [
            'title',
            'editor',       // for manual notes
            'thumbnail',    // featured image fallback
            'revisions',
            'custom-fields',
        ],
        'taxonomies'         => [ 'anime_genre', 'anime_tag' ],
        'delete_with_user'   => false,
    ];

    register_post_type( 'anime', $args );
}

/* ═══════════════════════════════════════════════════════════
   5. TAXONOMIES
══════════════════════════════════════════════════════════ */
add_action( 'init', 'anime_sync_pro_register_taxonomies', 1 );

function anime_sync_pro_register_taxonomies(): void {

    /* ── Genre ── */
    register_taxonomy( 'anime_genre', 'anime', [
        'labels'            => [
            'name'          => _x( '類型', 'taxonomy general name', 'anime-sync-pro' ),
            'singular_name' => _x( '類型', 'taxonomy singular name', 'anime-sync-pro' ),
            'search_items'  => __( '搜尋類型', 'anime-sync-pro' ),
            'all_items'     => __( '所有類型', 'anime-sync-pro' ),
            'edit_item'     => __( '編輯類型', 'anime-sync-pro' ),
            'update_item'   => __( '更新類型', 'anime-sync-pro' ),
            'add_new_item'  => __( '新增類型', 'anime-sync-pro' ),
            'new_item_name' => __( '新類型名稱', 'anime-sync-pro' ),
            'menu_name'     => __( '類型', 'anime-sync-pro' ),
        ],
        'hierarchical'      => true,
        'public'            => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'anime-genre', 'with_front' => false ],
    ] );

    /* ── Tag ── */
    register_taxonomy( 'anime_tag', 'anime', [
        'labels'            => [
            'name'          => _x( '標籤', 'taxonomy general name', 'anime-sync-pro' ),
            'singular_name' => _x( '標籤', 'taxonomy singular name', 'anime-sync-pro' ),
            'search_items'  => __( '搜尋標籤', 'anime-sync-pro' ),
            'all_items'     => __( '所有標籤', 'anime-sync-pro' ),
            'edit_item'     => __( '編輯標籤', 'anime-sync-pro' ),
            'update_item'   => __( '更新標籤', 'anime-sync-pro' ),
            'add_new_item'  => __( '新增標籤', 'anime-sync-pro' ),
            'new_item_name' => __( '新標籤名稱', 'anime-sync-pro' ),
            'menu_name'     => __( '標籤', 'anime-sync-pro' ),
        ],
        'hierarchical'      => false,
        'public'            => true,
        'show_ui'           => true,
        'show_in_rest'      => true,
        'show_admin_column' => true,
        'rewrite'           => [ 'slug' => 'anime-tag', 'with_front' => false ],
    ] );
}

/* ═══════════════════════════════════════════════════════════
   6. CAPABILITY MAPPING
   Ensures 'manage_anime' cap works for anime CPT.
══════════════════════════════════════════════════════════ */
add_filter( 'user_has_cap', 'anime_sync_pro_map_caps', 10, 4 );

/**
 * Map generic 'manage_anime' to granular anime CPT caps.
 *
 * @param array   $allcaps All capabilities of the user.
 * @param array   $caps    Required primitive capabilities.
 * @param array   $args    Arguments (0 = cap requested, 1 = user ID, 2 = post ID).
 * @param WP_User $user
 * @return array
 */
function anime_sync_pro_map_caps( array $allcaps, array $caps, array $args, WP_User $user ): array {
    if ( isset( $allcaps['manage_anime'] ) && $allcaps['manage_anime'] ) {
        $anime_caps = [
            'edit_anime', 'read_anime', 'delete_anime',
            'edit_animes', 'edit_others_animes', 'publish_animes',
            'read_private_animes', 'delete_animes', 'delete_others_animes',
            'delete_published_animes', 'delete_private_animes',
            'edit_published_animes', 'edit_private_animes',
        ];
        foreach ( $anime_caps as $cap ) {
            $allcaps[ $cap ] = true;
        }
    }
    return $allcaps;
}

/* ═══════════════════════════════════════════════════════════
   7. LOAD PLUGIN COMPONENTS
══════════════════════════════════════════════════════════ */
add_action( 'plugins_loaded', 'anime_sync_pro_load', 10 );

function anime_sync_pro_load(): void {

    /* ── i18n ── */
    load_plugin_textdomain(
        'anime-sync-pro',
        false,
        dirname( ANIME_SYNC_PRO_BASENAME ) . '/languages/'
    );

    /* ── ACF check ── */
    if ( ! class_exists( 'ACF' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                 . wp_kses(
                     sprintf(
                         /* translators: %s: ACF plugin link */
                         __( 'Anime Sync Pro 需要 <a href="%s" target="_blank">Advanced Custom Fields</a> 外掛才能正常運作。', 'anime-sync-pro' ),
                         'https://wordpress.org/plugins/advanced-custom-fields/'
                     ),
                     [ 'a' => [ 'href' => [], 'target' => [] ] ]
                   )
                 . '</p></div>';
        } );
        // Continue loading non-ACF components
    }

    /* ── Core components (always loaded) ── */
    new Anime_Sync_ACF_Fields();   // registers ACF field groups
    new Anime_Sync_Frontend();     // public template, SEO, REST API

    /* ── Admin-only components ── */
    if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
        new Anime_Sync_Admin();    // admin menu, AJAX, cron scheduling
    }
}

/* ═══════════════════════════════════════════════════════════
   8. CRON RESCHEDULE HOOK
   Fired by Settings page after time changes.
══════════════════════════════════════════════════════════ */
add_action( 'anime_sync_reschedule_cron', 'anime_sync_pro_reschedule_cron' );

function anime_sync_pro_reschedule_cron(): void {
    // Clear existing scheduled events
    $hooks = [
        'anime_sync_daily_update',
        'anime_sync_weekly_update',
    ];
    foreach ( $hooks as $hook ) {
        $ts = wp_next_scheduled( $hook );
        if ( $ts ) {
            wp_unschedule_event( $ts, $hook );
        }
    }

    // Reschedule via Admin class helper
    if ( class_exists( 'Anime_Sync_Admin' ) ) {
        Anime_Sync_Admin::schedule_cron_events();
    }
}

/* ═══════════════════════════════════════════════════════════
   9. CUSTOM CRON INTERVALS
══════════════════════════════════════════════════════════ */
add_filter( 'cron_schedules', 'anime_sync_pro_cron_intervals' );

/**
 * Register custom WP-Cron recurrence intervals.
 *
 * @param array $schedules
 * @return array
 */
function anime_sync_pro_cron_intervals( array $schedules ): array {
    $schedules['anime_sync_every_minute'] = [
        'interval' => 60,
        'display'  => __( '每分鐘（Anime Sync）', 'anime-sync-pro' ),
    ];
    $schedules['anime_sync_every_5min'] = [
        'interval' => 300,
        'display'  => __( '每 5 分鐘（Anime Sync）', 'anime-sync-pro' ),
    ];
    $schedules['anime_sync_every_hour'] = [
        'interval' => 3600,
        'display'  => __( '每小時（Anime Sync）', 'anime-sync-pro' ),
    ];
    $schedules['anime_sync_weekly'] = [
        'interval' => WEEK_IN_SECONDS,
        'display'  => __( '每週（Anime Sync）', 'anime-sync-pro' ),
    ];
    return $schedules;
}

/* ═══════════════════════════════════════════════════════════
   10. REWRITE RULES FLUSH GUARD
   Flushes rewrite rules once after activation sets the flag.
══════════════════════════════════════════════════════════ */
add_action( 'init', 'anime_sync_pro_maybe_flush_rewrite', 99 );

function anime_sync_pro_maybe_flush_rewrite(): void {
    if ( get_option( 'anime_sync_flush_rewrite' ) ) {
        flush_rewrite_rules();
        delete_option( 'anime_sync_flush_rewrite' );
    }
}

/* ═══════════════════════════════════════════════════════════
   11. PLUGIN ACTION LINKS  (Plugins list page)
══════════════════════════════════════════════════════════ */
add_filter(
    'plugin_action_links_' . ANIME_SYNC_PRO_BASENAME,
    'anime_sync_pro_action_links'
);

/**
 * Add "設定" and "匯入" quick links on the Plugins page.
 *
 * @param array $links
 * @return array
 */
function anime_sync_pro_action_links( array $links ): array {
    $custom = [
        '<a href="' . esc_url( admin_url( 'admin.php?page=anime-sync-settings' ) ) . '">'
            . esc_html__( '設定', 'anime-sync-pro' ) . '</a>',
        '<a href="' . esc_url( admin_url( 'admin.php?page=anime-sync-import' ) ) . '">'
            . esc_html__( '匯入', 'anime-sync-pro' ) . '</a>',
    ];
    return array_merge( $custom, $links );
}

/* ═══════════════════════════════════════════════════════════
   12. PLUGIN META LINKS  (row meta: docs / support)
══════════════════════════════════════════════════════════ */
add_filter( 'plugin_row_meta', 'anime_sync_pro_row_meta', 10, 2 );

/**
 * @param array  $links
 * @param string $file
 * @return array
 */
function anime_sync_pro_row_meta( array $links, string $file ): array {
    if ( $file !== ANIME_SYNC_PRO_BASENAME ) {
        return $links;
    }
    $links[] = '<a href="https://github.com/your-repo/anime-sync-pro/wiki" target="_blank" rel="noopener">'
               . esc_html__( '說明文件', 'anime-sync-pro' ) . '</a>';
    $links[] = '<a href="https://github.com/your-repo/anime-sync-pro/issues" target="_blank" rel="noopener">'
               . esc_html__( '回報問題', 'anime-sync-pro' ) . '</a>';
    return $links;
}

/* ═══════════════════════════════════════════════════════════
   13. REST API: expose anime meta in default WP endpoint
══════════════════════════════════════════════════════════ */
add_action( 'rest_api_init', 'anime_sync_pro_register_rest_fields' );

function anime_sync_pro_register_rest_fields(): void {
    $expose_keys = [
        'anime_anilist_id', 'anime_mal_id', 'anime_bangumi_id',
        'anime_title_chinese', 'anime_title_native', 'anime_title_romaji',
        'anime_title_english', 'anime_format', 'anime_status',
        'anime_season', 'anime_season_year', 'anime_episodes',
        'anime_score_anilist', 'anime_score_mal', 'anime_score_bangumi',
        'anime_popularity', 'anime_cover_image', 'anime_banner_image',
    ];

    foreach ( $expose_keys as $key ) {
        register_rest_field( 'anime', $key, [
            'get_callback'    => fn( $post ) => get_post_meta( $post['id'], $key, true ),
            'update_callback' => null,
            'schema'          => null,
        ] );
    }
}

/* ═══════════════════════════════════════════════════════════
   14. ADMIN BAR: quick link to Import Tool
══════════════════════════════════════════════════════════ */
add_action( 'admin_bar_menu', 'anime_sync_pro_admin_bar_link', 100 );

/**
 * @param WP_Admin_Bar $wp_admin_bar
 */
function anime_sync_pro_admin_bar_link( WP_Admin_Bar $wp_admin_bar ): void {
    if ( ! current_user_can( 'manage_anime' ) && ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $wp_admin_bar->add_node( [
        'id'    => 'anime-sync-import',
        'title' => '🎬 ' . esc_html__( '匯入動畫', 'anime-sync-pro' ),
        'href'  => admin_url( 'admin.php?page=anime-sync-import' ),
        'meta'  => [ 'title' => esc_html__( 'Anime Sync Pro 匯入工具', 'anime-sync-pro' ) ],
    ] );
}

/* ═══════════════════════════════════════════════════════════
   15. HEARTBEAT: prevent WP Heartbeat on anime admin pages
   (reduces server load during long batch imports)
══════════════════════════════════════════════════════════ */
add_filter( 'heartbeat_settings', 'anime_sync_pro_heartbeat_settings' );

/**
 * @param array $settings
 * @return array
 */
function anime_sync_pro_heartbeat_settings( array $settings ): array {
    $screen = get_current_screen();
    if (
        $screen &&
        strpos( $screen->id ?? '', 'anime-sync' ) !== false
    ) {
        $settings['interval'] = 120; // slow down to 2 min on plugin pages
    }
    return $settings;
}
