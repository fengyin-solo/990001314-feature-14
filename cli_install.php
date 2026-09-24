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
        `role` VARCHAR(20) NOT NULL DEFAULT 'super_admin' COMMENT '角色: super_admin超级管理员, auditor审核员, viewer只读账号',
        `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 1启用, 0停用',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表'");

    // 后台登录会话表（每个设备一行，支持远程退出）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admin_sessions` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `token` CHAR(64) NOT NULL COMMENT '会话令牌',
        `admin_id` INT UNSIGNED NOT NULL COMMENT '管理员ID',
        `login_ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '登录IP',
        `last_ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '最近请求IP',
        `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '浏览器UA',
        `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登录时间',
        `last_active_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最后活跃时间',
        `expires_at` DATETIME NOT NULL COMMENT '绝对过期时间',
        `revoked_at` DATETIME DEFAULT NULL COMMENT '失效时间',
        `revoke_reason` VARCHAR(30) DEFAULT NULL COMMENT '失效原因',
        UNIQUE KEY `uk_token` (`token`),
        INDEX `idx_admin_id` (`admin_id`),
        INDEX `idx_active` (`admin_id`, `revoked_at`, `expires_at`),
        INDEX `idx_last_active` (`last_active_at`),
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台登录会话表'");

    // 后台登录失败记录表（连续失败锁定）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `login_failures` (
        `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL COMMENT '尝试登录的用户名',
        `ip` VARCHAR(45) NOT NULL COMMENT '来源IP',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '失败时间',
        INDEX `idx_account_ip_time` (`username`, `ip`, `created_at`),
        INDEX `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台登录失败记录'");

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

    // 默认账号：超级管理员 / 审核员 / 只读账号
    $pdo->prepare("INSERT IGNORE INTO `admins` (`username`, `password`, `role`, `status`)
                   VALUES ('admin', ?, 'super_admin', 1)")
        ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT IGNORE INTO `admins` (`username`, `password`, `role`, `status`)
                   VALUES ('auditor', ?, 'auditor', 1)")
        ->execute([password_hash('auditor123', PASSWORD_DEFAULT)]);
    $pdo->prepare("INSERT IGNORE INTO `admins` (`username`, `password`, `role`, `status`)
                   VALUES ('viewer', ?, 'viewer', 1)")
        ->execute([password_hash('viewer123', PASSWORD_DEFAULT)]);

    echo "数据库表创建成功！\n";
    echo "默认账号：admin/admin123（超管）、auditor/auditor123（审核员）、viewer/viewer123（只读）\n";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage() . "\n");
}
