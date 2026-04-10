<?php
/**
 * Uninstall Script
 * 刪除插件時執行，清除所有插件資料
 * 
 * @package Anime_Sync_Pro
 */

// 安全檢查：確保由 WordPress 呼叫
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// ========================================
// 刪除自訂資料表
// ========================================
$tables = array(
    $wpdb->prefix . 'anime_review_queue',
    $wpdb->prefix . 'anime_sync_logs',
);

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// ========================================
// 刪除插件選項
// ========================================
$options = array(
    'anime_sync_version',
    'anime_sync_activated_at',
    'anime_sync_cn_method',
    'anime_sync_image_method',
    'anime_sync_cdn_provider',
    'anime_sync_cdn_base_url',
    'anime_sync_ecpay_enabled',
    'anime_sync_ecpay_merchant_id',
    'anime_sync_api_delay',
    'anime_sync_batch_size',
    'anime_sync_log_email_notify',
);

foreach ($options as $option) {
    delete_option($option);
}

// ========================================
// 刪除所有 Transient 快取
// ========================================
$wpdb->query(
    "DELETE FROM {$wpdb->options}
    WHERE option_name LIKE '_transient_anime_sync%'
    OR option_name LIKE '_transient_timeout_anime_sync%'"
);

// ========================================
// 刪除 Anime 文章 Meta（可選：保留文章本體）
// ========================================
// 注意：以下程式碼會刪除所有 anime 文章的自訂欄位
// 若希望保留文章內容，請將此區塊保持註解
/*
$anime_posts = get_posts(array(
    'post_type'      => 'anime',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
));

foreach ($anime_posts as $post_id) {
    $meta_keys = array(
        'anime_id_anilist', 'anime_id_mal', 'anime_id_bangumi',
        'anime_title_romaji', 'anime_title_english', 'anime_title_native',
        'anime_title_chinese_traditional', 'anime_season', 'anime_year',
        'anime_episodes', 'anime_duration', 'anime_status', 'anime_format',
        'anime_source', 'anime_score_anilist', 'anime_score_mal',
        'anime_score_bangumi', 'anime_popularity', 'anime_synopsis_tw',
        'anime_synopsis_en', 'anime_cover_url', 'anime_banner_url',
        'anime_studios', 'anime_genres', 'anime_openings', 'anime_endings',
        'anime_characters', 'anime_streaming_platforms', 'anime_last_sync',
        'anime_sync_status', 'anime_locked_fields',
    );

    foreach ($meta_keys as $key) {
        delete_post_meta($post_id, $key);
    }
}
*/

// ========================================
// 清除上傳目錄（可選）
// ========================================
// 注意：以下程式碼會刪除插件建立的上傳目錄
// 預設不執行，避免誤刪圖片
/*
$upload_dir = wp_upload_dir();
$dirs_to_remove = array(
    $upload_dir['basedir'] . '/anime-covers',
    $upload_dir['basedir'] . '/anime-sync-cache',
);

function anime_sync_remove_dir($dir) {
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? anime_sync_remove_dir($path) : unlink($path);
    }
    rmdir($dir);
}

foreach ($dirs_to_remove as $dir) {
    anime_sync_remove_dir($dir);
}
*/

// 清除 WP 物件快取
wp_cache_flush();
