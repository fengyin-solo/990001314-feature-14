<?php
/**
 * 命令行数据库初始化脚本 - 用于创建表结构
 * 用法: php cli_install.php
 */

$host = 'localhost';
$user = 'root';
$pass = '123456';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `community_board` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `community_board`");

    // 留言表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `nickname` VARCHAR(50) NOT NULL COMMENT '昵称',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
        `type` ENUM('help','suggest','lost') NOT NULL DEFAULT 'help' COMMENT '类型: help求助, suggest建议, lost失物招领',
        `title` VARCHAR(100) NOT NULL COMMENT '标题',
        `content` TEXT NOT NULL COMMENT '内容',
        `image` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待审核, 1已通过, 2已拒绝',
        `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_type` (`type`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言表'");

    // 管理员表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(20) NOT NULL DEFAULT 'super_admin' COMMENT '角色: super_admin超级管理员, auditor审核员, viewer只读访客',
        `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 1启用, 0停用',
        `last_login_at` DATETIME DEFAULT NULL COMMENT '最近登录时间',
        `last_login_ip` VARCHAR(45) DEFAULT NULL COMMENT '最近登录IP',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表'");

    // 管理员活跃会话表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_sessions` (
        `token_hash` CHAR(64) NOT NULL COMMENT '会话令牌的SHA256哈希',
        `admin_id` INT UNSIGNED NOT NULL COMMENT '管理员ID',
        `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '登录IP',
        `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '浏览器UA',
        `login_at` DATETIME NOT NULL COMMENT '登录时间',
        `last_active_at` DATETIME NOT NULL COMMENT '最后活跃时间',
        `expires_at` DATETIME NOT NULL COMMENT '绝对过期时间',
        PRIMARY KEY (`token_hash`),
        INDEX `idx_admin_id` (`admin_id`),
        INDEX `idx_expires_at` (`expires_at`),
        INDEX `idx_last_active` (`last_active_at`),
        CONSTRAINT `fk_session_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员活跃会话表'");

    // 管理员登录日志表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `login_logs` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL COMMENT '登录用户名（含不存在的账号）',
        `admin_id` INT UNSIGNED DEFAULT NULL COMMENT '匹配到的管理员ID',
        `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '登录IP',
        `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '浏览器UA',
        `success` TINYINT NOT NULL DEFAULT 0 COMMENT '1成功 0失败',
        `reason` VARCHAR(50) NOT NULL DEFAULT '' COMMENT '失败原因: wrong_password/disabled/locked',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '记录时间',
        INDEX `idx_username_time` (`username`, `created_at`),
        INDEX `idx_ip_time` (`ip`, `created_at`),
        INDEX `idx_admin_time` (`admin_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员登录日志表'");

    // 收藏表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `favorites` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
        `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '收藏时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_message_id` (`message_id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表'");

    // 举报表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT UNSIGNED NOT NULL COMMENT '被举报的留言ID',
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '举报人访客标识',
        `report_type` VARCHAR(50) NOT NULL COMMENT '举报类型: spam垃圾信息, abuse辱骂攻击, illegal违法违规, porn色情低俗, other其他',
        `description` TEXT COMMENT '补充说明',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待处理, 1已处理-已删除, 2已处理-已忽略, 3已驳回',
        `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
        `processed_at` DATETIME DEFAULT NULL COMMENT '处理时间',
        `process_note` VARCHAR(500) DEFAULT NULL COMMENT '处理备注',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '举报时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`processed_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表'");

    echo "数据库表创建成功！\n";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage() . "\n");
}
