<?php
declare(strict_types=1);

/**
 * Migration 001 – Grundschema SwipeGroove.
 * Alle Tabellen InnoDB/utf8mb4. Zeiten in UTC.
 */
$t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    "CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `value` MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL
    )$t",

    "CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        spotify_user_id VARCHAR(191) NOT NULL,
        username VARCHAR(32) NOT NULL,
        display_name VARCHAR(60) NOT NULL,
        bio VARCHAR(500) NULL,
        avatar_path VARCHAR(255) NULL,
        banner_path VARCHAR(255) NULL,
        background_path VARCHAR(255) NULL,
        is_private TINYINT(1) NOT NULL DEFAULT 0,
        lang CHAR(2) NOT NULL DEFAULT 'de',
        role ENUM('user','admin') NOT NULL DEFAULT 'user',
        status ENUM('active','suspended','deleted') NOT NULL DEFAULT 'active',
        suspended_reason VARCHAR(255) NULL,
        msg_from_friends TINYINT(1) NOT NULL DEFAULT 1,
        msg_requests_allowed TINYINT(1) NOT NULL DEFAULT 1,
        playback_enabled TINYINT(1) NOT NULL DEFAULT 0,
        premium_manual_until DATETIME NULL,
        onboarding_done TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        last_login_at DATETIME NULL,
        deleted_at DATETIME NULL,
        UNIQUE KEY uq_users_spotify (spotify_user_id),
        UNIQUE KEY uq_users_username (username),
        KEY ix_users_display (display_name),
        KEY ix_users_status (status)
    )$t",

    "CREATE TABLE IF NOT EXISTS platform_owner (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_owner_user (user_id),
        CONSTRAINT fk_owner_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
    )$t",

    "CREATE TABLE IF NOT EXISTS spotify_accounts (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        spotify_user_id VARCHAR(191) NOT NULL,
        spotify_display_name VARCHAR(191) NULL,
        spotify_avatar_url VARCHAR(500) NULL,
        access_token_enc TEXT NULL,
        refresh_token_enc TEXT NULL,
        token_expires_at DATETIME NULL,
        scopes VARCHAR(500) NULL,
        authorized_at DATETIME NULL,
        status ENUM('connected','expired','revoked','disconnected') NOT NULL DEFAULT 'connected',
        last_error VARCHAR(255) NULL,
        last_refresh_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT fk_spotify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS sessions (
        id CHAR(64) NOT NULL PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        csrf_token CHAR(43) NOT NULL,
        user_agent VARCHAR(255) NULL,
        ip_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        KEY ix_sessions_user (user_id),
        KEY ix_sessions_expires (expires_at),
        CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS oauth_states (
        state CHAR(43) NOT NULL PRIMARY KEY,
        code_verifier VARCHAR(128) NOT NULL,
        browser_hash CHAR(64) NOT NULL,
        purpose ENUM('login','setup','reconnect') NOT NULL DEFAULT 'login',
        user_id INT UNSIGNED NULL,
        setup_key_ok TINYINT(1) NOT NULL DEFAULT 0,
        lang CHAR(2) NOT NULL DEFAULT 'de',
        redirect_to VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        KEY ix_oauth_expires (expires_at)
    )$t",

    "CREATE TABLE IF NOT EXISTS rate_limits (
        bucket VARCHAR(80) NOT NULL PRIMARY KEY,
        window_start INT UNSIGNED NOT NULL,
        hits INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at INT UNSIGNED NOT NULL,
        KEY ix_rl_expires (expires_at)
    )$t",

    "CREATE TABLE IF NOT EXISTS playlist_cache (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        spotify_playlist_id VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        image_url VARCHAR(500) NULL,
        owner_spotify_id VARCHAR(191) NULL,
        owner_name VARCHAR(191) NULL,
        is_owner TINYINT(1) NOT NULL DEFAULT 0,
        is_collaborative TINYINT(1) NOT NULL DEFAULT 0,
        spotify_public TINYINT(1) NULL,
        track_count INT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_id VARCHAR(191) NULL,
        can_edit TINYINT(1) NOT NULL DEFAULT 0,
        items_synced_at DATETIME NULL,
        items_complete TINYINT(1) NOT NULL DEFAULT 0,
        synced_at DATETIME NOT NULL,
        UNIQUE KEY uq_plcache (user_id, spotify_playlist_id),
        CONSTRAINT fk_plcache_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS playlist_items_cache (
        playlist_cache_id INT UNSIGNED NOT NULL,
        position INT UNSIGNED NOT NULL,
        track_id VARCHAR(64) NULL,
        uri VARCHAR(255) NULL,
        name VARCHAR(255) NULL,
        artists TEXT NULL,
        artist_ids TEXT NULL,
        album_name VARCHAR(255) NULL,
        album_id VARCHAR(64) NULL,
        image_url VARCHAR(500) NULL,
        duration_ms INT UNSIGNED NULL,
        is_local TINYINT(1) NOT NULL DEFAULT 0,
        added_at DATETIME NULL,
        PRIMARY KEY (playlist_cache_id, position),
        KEY ix_items_track (track_id),
        CONSTRAINT fk_items_cache FOREIGN KEY (playlist_cache_id) REFERENCES playlist_cache(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS published_playlists (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        spotify_playlist_id VARCHAR(64) NOT NULL,
        name VARCHAR(255) NOT NULL,
        description TEXT NULL,
        image_url VARCHAR(500) NULL,
        track_count INT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_id VARCHAR(191) NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        like_count INT UNSIGNED NOT NULL DEFAULT 0,
        comment_count INT UNSIGNED NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_pub (user_id, spotify_playlist_id),
        KEY ix_pub_feed (is_published, published_at),
        CONSTRAINT fk_pub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS published_playlist_tracks (
        published_playlist_id INT UNSIGNED NOT NULL,
        position INT UNSIGNED NOT NULL,
        track_id VARCHAR(64) NULL,
        name VARCHAR(255) NULL,
        artists VARCHAR(500) NULL,
        album_name VARCHAR(255) NULL,
        image_url VARCHAR(500) NULL,
        duration_ms INT UNSIGNED NULL,
        PRIMARY KEY (published_playlist_id, position),
        CONSTRAINT fk_pubtracks FOREIGN KEY (published_playlist_id) REFERENCES published_playlists(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS playlist_likes (
        published_playlist_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (published_playlist_id, user_id),
        KEY ix_likes_user (user_id),
        CONSTRAINT fk_likes_pl FOREIGN KEY (published_playlist_id) REFERENCES published_playlists(id) ON DELETE CASCADE,
        CONSTRAINT fk_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS playlist_comments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        published_playlist_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        body VARCHAR(1000) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NULL,
        deleted_at DATETIME NULL,
        deleted_by INT UNSIGNED NULL,
        KEY ix_comments_pl (published_playlist_id, created_at),
        KEY ix_comments_user (user_id),
        CONSTRAINT fk_comments_pl FOREIGN KEY (published_playlist_id) REFERENCES published_playlists(id) ON DELETE CASCADE,
        CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS follows (
        follower_id INT UNSIGNED NOT NULL,
        followed_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (follower_id, followed_id),
        KEY ix_follows_followed (followed_id),
        CONSTRAINT fk_follows_a FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_follows_b FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS friendships (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_low INT UNSIGNED NOT NULL,
        user_high INT UNSIGNED NOT NULL,
        requester_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        responded_at DATETIME NULL,
        UNIQUE KEY uq_friend_pair (user_low, user_high),
        KEY ix_friend_high (user_high, status),
        CONSTRAINT fk_friend_low FOREIGN KEY (user_low) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_friend_high FOREIGN KEY (user_high) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS blocks (
        blocker_id INT UNSIGNED NOT NULL,
        blocked_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (blocker_id, blocked_id),
        KEY ix_blocks_blocked (blocked_id),
        CONSTRAINT fk_blocks_a FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_blocks_b FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS reports (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT UNSIGNED NOT NULL,
        target_type ENUM('user','comment','message','playlist') NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        target_user_id INT UNSIGNED NULL,
        reason VARCHAR(50) NOT NULL,
        details VARCHAR(1000) NULL,
        status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
        resolved_by INT UNSIGNED NULL,
        resolved_at DATETIME NULL,
        resolution_note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        KEY ix_reports_status (status, created_at),
        CONSTRAINT fk_reports_user FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS conversations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_low INT UNSIGNED NOT NULL,
        user_high INT UNSIGNED NOT NULL,
        initiator_id INT UNSIGNED NOT NULL,
        status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'accepted',
        last_message_id BIGINT UNSIGNED NULL,
        last_message_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_conv_pair (user_low, user_high),
        KEY ix_conv_last (last_message_at),
        CONSTRAINT fk_conv_low FOREIGN KEY (user_low) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_conv_high FOREIGN KEY (user_high) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS conversation_members (
        conversation_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        unread_count INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (conversation_id, user_id),
        KEY ix_cm_user (user_id),
        CONSTRAINT fk_cm_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        CONSTRAINT fk_cm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT UNSIGNED NOT NULL,
        sender_id INT UNSIGNED NOT NULL,
        body VARCHAR(2000) NOT NULL,
        link_type VARCHAR(20) NULL,
        link_ref VARCHAR(191) NULL,
        created_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        KEY ix_msg_conv (conversation_id, id),
        CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
        CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(40) NOT NULL,
        actor_id INT UNSIGNED NULL,
        ref_type VARCHAR(30) NULL,
        ref_id INT UNSIGNED NULL,
        data TEXT NULL,
        read_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY ix_notif_user (user_id, read_at, id),
        CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS activities (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        type VARCHAR(30) NOT NULL,
        published_playlist_id INT UNSIGNED NULL,
        target_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        KEY ix_act_time (created_at),
        KEY ix_act_user (user_id, created_at),
        CONSTRAINT fk_act_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS swipe_hidden (
        user_id INT UNSIGNED NOT NULL,
        spotify_playlist_id VARCHAR(64) NOT NULL,
        track_id VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, spotify_playlist_id, track_id),
        CONSTRAINT fk_hidden_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS swipe_actions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        action_uuid CHAR(36) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        spotify_playlist_id VARCHAR(64) NOT NULL,
        track_id VARCHAR(64) NOT NULL,
        track_uri VARCHAR(100) NOT NULL,
        action ENUM('add','hide','skip') NOT NULL,
        status ENUM('pending','done','undone','failed') NOT NULL DEFAULT 'pending',
        snapshot_before VARCHAR(191) NULL,
        snapshot_after VARCHAR(191) NULL,
        position_added INT UNSIGNED NULL,
        occurrences_before INT UNSIGNED NULL,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_action_uuid (action_uuid),
        KEY ix_actions_user (user_id, spotify_playlist_id, id),
        CONSTRAINT fk_actions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS feed_candidates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        spotify_playlist_id VARCHAR(64) NOT NULL,
        mode VARCHAR(20) NOT NULL,
        filter_hash CHAR(32) NOT NULL,
        track_id VARCHAR(64) NOT NULL,
        payload TEXT NOT NULL,
        served TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_cand (user_id, spotify_playlist_id, mode, filter_hash, track_id),
        KEY ix_cand_created (created_at),
        CONSTRAINT fk_cand_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS playlist_operations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        op_uuid CHAR(36) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        type ENUM('move','copy','merge','duplicate','remove_duplicates') NOT NULL,
        source_playlist_id VARCHAR(64) NULL,
        target_playlist_id VARCHAR(64) NULL,
        payload MEDIUMTEXT NOT NULL,
        status ENUM('pending','added','done','partial','failed') NOT NULL DEFAULT 'pending',
        progress MEDIUMTEXT NULL,
        error VARCHAR(500) NULL,
        locked_until DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_op_uuid (op_uuid),
        KEY ix_ops_user (user_id, status),
        CONSTRAINT fk_ops_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS cover_jobs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_uuid CHAR(36) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        prompt VARCHAR(2000) NOT NULL,
        style VARCHAR(100) NULL,
        provider VARCHAR(30) NOT NULL,
        model VARCHAR(60) NOT NULL,
        quality VARCHAR(20) NOT NULL,
        status ENUM('reserved','generating','done','failed','applied') NOT NULL DEFAULT 'reserved',
        period_key VARCHAR(40) NOT NULL,
        counts_against_quota TINYINT(1) NOT NULL DEFAULT 1,
        cost_estimate_usd DECIMAL(8,4) NULL,
        file_path VARCHAR(255) NULL,
        error VARCHAR(500) NULL,
        applied_playlist_id VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        UNIQUE KEY uq_job_uuid (job_uuid),
        KEY ix_jobs_user_period (user_id, period_key, status),
        KEY ix_jobs_status (status, created_at),
        CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS subscriptions (
        user_id INT UNSIGNED NOT NULL PRIMARY KEY,
        stripe_customer_id VARCHAR(100) NULL,
        stripe_subscription_id VARCHAR(100) NULL,
        price_id VARCHAR(100) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'none',
        livemode TINYINT(1) NOT NULL DEFAULT 0,
        current_period_start DATETIME NULL,
        current_period_end DATETIME NULL,
        cancel_at_period_end TINYINT(1) NOT NULL DEFAULT 0,
        last_event_at INT UNSIGNED NOT NULL DEFAULT 0,
        last_payment_error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY ix_sub_customer (stripe_customer_id),
        KEY ix_sub_subscription (stripe_subscription_id),
        CONSTRAINT fk_sub_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",

    "CREATE TABLE IF NOT EXISTS stripe_events (
        event_id VARCHAR(100) NOT NULL PRIMARY KEY,
        type VARCHAR(80) NOT NULL,
        livemode TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('processing','processed','ignored','failed') NOT NULL,
        error VARCHAR(500) NULL,
        received_at DATETIME NOT NULL,
        processed_at DATETIME NULL
    )$t",

    "CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        admin_id INT UNSIGNED NULL,
        action VARCHAR(60) NOT NULL,
        target_type VARCHAR(30) NULL,
        target_id VARCHAR(64) NULL,
        details TEXT NULL,
        ip_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        KEY ix_audit_time (created_at)
    )$t",

    "CREATE TABLE IF NOT EXISTS integration_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        service VARCHAR(20) NOT NULL,
        method VARCHAR(8) NOT NULL,
        endpoint VARCHAR(191) NOT NULL,
        status SMALLINT NOT NULL,
        duration_ms INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        error VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        KEY ix_int_service_time (service, created_at),
        KEY ix_int_status (status)
    )$t",

    "CREATE TABLE IF NOT EXISTS error_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        scope VARCHAR(30) NOT NULL,
        message VARCHAR(1000) NOT NULL,
        location VARCHAR(255) NULL,
        path VARCHAR(255) NULL,
        user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        KEY ix_err_time (created_at)
    )$t",

    "CREATE TABLE IF NOT EXISTS user_images (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        kind ENUM('avatar','banner','background') NOT NULL,
        path VARCHAR(255) NOT NULL,
        width INT UNSIGNED NOT NULL,
        height INT UNSIGNED NOT NULL,
        bytes INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        KEY ix_img_user (user_id, kind),
        CONSTRAINT fk_img_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$t",
];
