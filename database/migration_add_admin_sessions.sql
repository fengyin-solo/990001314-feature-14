-- 会话管理 / 登录日志 / 角色权限 迁移脚本
-- 适用于已安装的旧版本库，执行一次即可：
--   mysql -u root -p community_board < migration_add_admin_sessions.sql
--
-- 注意：MySQL 5.7 的 ALTER TABLE 不支持 ADD COLUMN IF NOT EXISTS，
-- 若 admins 表已存在以下列，请忽略对应的 "Duplicate column" 报错。

USE `community_board`;

-- 1) 管理员表增加角色、状态、最近登录信息
ALTER TABLE `admins`
    ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'super_admin'
        COMMENT '角色: super_admin超级管理员, auditor审核员, viewer只读访客' AFTER `password`,
    ADD COLUMN `status` TINYINT NOT NULL DEFAULT 1
        COMMENT '状态: 1启用, 0停用' AFTER `role`,
    ADD COLUMN `last_login_at` DATETIME DEFAULT NULL COMMENT '最近登录时间' AFTER `status`,
    ADD COLUMN `last_login_ip` VARCHAR(45) DEFAULT NULL COMMENT '最近登录IP' AFTER `last_login_at`;

-- 已有管理员默认获得超级管理员角色（由列默认值保证），此处显式兜底
UPDATE `admins` SET `role` = 'super_admin' WHERE `role` IS NULL OR `role` = '';

-- 2) 活跃会话表：每个设备登录后产生一条记录，删除即代表入口立即失效
CREATE TABLE IF NOT EXISTS `admin_sessions` (
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
    CONSTRAINT `fk_session_admin` FOREIGN KEY (`admin_id`)
        REFERENCES `admins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员活跃会话表';

-- 3) 登录日志表：记录成功/失败，用于连续失败锁定与异常登录提示
CREATE TABLE IF NOT EXISTS `login_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员登录日志表';

-- 验证：
-- DESCRIBE admins;
-- SHOW TABLES LIKE 'admin_sessions';
-- SHOW TABLES LIKE 'login_logs';
