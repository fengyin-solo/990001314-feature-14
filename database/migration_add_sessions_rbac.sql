-- 后台会话管理与角色权限迁移脚本
-- 适用已有数据库：执行后支持多角色、只读账号、活跃会话管理、远程退出设备、登录失败锁定
-- 用法: mysql -u root -p community_board < database/migration_add_sessions_rbac.sql

USE `community_board`;

-- 1. 管理员表增加角色与状态
-- MySQL 5.7 的 ALTER TABLE 不支持 ADD COLUMN IF NOT EXISTS，下面用存储过程幂等添加，可安全重复执行
DROP PROCEDURE IF EXISTS `add_admin_rbac_columns`;
DELIMITER //
CREATE PROCEDURE `add_admin_rbac_columns`()
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'role') THEN
        ALTER TABLE `admins`
            ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'super_admin' COMMENT '角色: super_admin超级管理员, auditor审核员, viewer只读账号' AFTER `password`;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE `admins`
            ADD COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 1启用, 0停用' AFTER `role`;
    END IF;
END //
DELIMITER ;
CALL `add_admin_rbac_columns`();
DROP PROCEDURE IF EXISTS `add_admin_rbac_columns`;

-- 存量管理员统一为超级管理员
UPDATE `admins` SET `role` = 'super_admin' WHERE `role` = '' OR `role` IS NULL;

-- 2. 后台活跃会话表（一行 = 一个设备/浏览器的登录入口）
CREATE TABLE IF NOT EXISTS `admin_sessions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `token` CHAR(64) NOT NULL COMMENT '会话令牌哈希值(实际为随机令牌)',
    `admin_id` INT UNSIGNED NOT NULL COMMENT '管理员ID',
    `login_ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '登录IP',
    `last_ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '最近请求IP',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '浏览器UA',
    `login_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登录时间',
    `last_active_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '最后活跃时间',
    `expires_at` DATETIME NOT NULL COMMENT '绝对过期时间',
    `revoked_at` DATETIME DEFAULT NULL COMMENT '失效时间',
    `revoke_reason` VARCHAR(30) DEFAULT NULL COMMENT '失效原因: manual/logout/replaced/idle/expired/disabled/role_changed',
    UNIQUE KEY `uk_token` (`token`),
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_active` (`admin_id`, `revoked_at`, `expires_at`),
    INDEX `idx_last_active` (`last_active_at`),
    CONSTRAINT `fk_sessions_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台登录会话表';

-- 3. 登录失败记录表（连续失败锁定）
CREATE TABLE IF NOT EXISTS `login_failures` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL COMMENT '尝试登录的用户名',
    `ip` VARCHAR(45) NOT NULL COMMENT '来源IP',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '失败时间',
    INDEX `idx_account_ip_time` (`username`, `ip`, `created_at`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台登录失败记录';

-- 4. （可选）追加两个测试账号：审核员 / 只读账号，便于体验不同角色
-- INSERT IGNORE INTO `admins` (`username`, `password`, `role`, `status`) VALUES
--   ('auditor', '<password_hash_for_auditor123>', 'auditor', 1),
--   ('viewer',  '<password_hash_for_viewer123>',  'viewer',  1);
