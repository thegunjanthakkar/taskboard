-- ============================================================
--  TasksBoard — Full MySQL Production Schema
-- ============================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  `name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `boards` (
  `id`          VARCHAR(64)  NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `name`        VARCHAR(255) NOT NULL,
  `color`       VARCHAR(20)  NOT NULL DEFAULT '#4f8ef7',
  `icon`        VARCHAR(50)  NOT NULL DEFAULT 'grid',
  `position`    INT          NOT NULL DEFAULT 0,
  `is_archived` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_boards_user_id` (`user_id`),
  KEY `idx_boards_archived` (`is_archived`),
  CONSTRAINT `fk_boards_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `board_lists` (
  `id`         VARCHAR(64)  NOT NULL,
  `board_id`   VARCHAR(64)  NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `color`      VARCHAR(20)  NULL,
  `position`   INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_board_lists_board_id` (`board_id`),
  CONSTRAINT `fk_board_lists_board`
    FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id`                  VARCHAR(64)                          NOT NULL,
  `list_id`             VARCHAR(64)                          NOT NULL,
  `title`               VARCHAR(255)                         NOT NULL,
  `description`         TEXT                                 NULL,
  `start_date`          DATE                                 NULL,
  `due_date`            DATE                                 NULL,
  `due_time`            TIME                                 NULL,
  `priority`            ENUM('low','medium','high')           NOT NULL DEFAULT 'medium',
  `completed`           TINYINT(1)                           NOT NULL DEFAULT 0,
  `position`            INT                                  NOT NULL DEFAULT 0,
  `assigned_to`         INT UNSIGNED                         NULL DEFAULT NULL,
  `recurrence`          ENUM('none','daily','weekly','monthly','custom') NOT NULL DEFAULT 'none',
  `recurrence_interval` INT                                  NOT NULL DEFAULT 1,
  `is_archived`         TINYINT(1)                           NOT NULL DEFAULT 0,
  `completed_at`        DATETIME                             NULL,
  `created_at`          DATETIME                             DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_list_id`    (`list_id`),
  KEY `idx_tasks_completed`  (`completed`),
  KEY `idx_tasks_due`        (`due_date`, `due_time`),
  KEY `idx_tasks_assigned_to`(`assigned_to`),
  KEY `idx_tasks_archived`   (`is_archived`),
  CONSTRAINT `fk_tasks_list`
    FOREIGN KEY (`list_id`) REFERENCES `board_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tasks_assigned_user`
    FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `subtasks` (
  `id`         VARCHAR(64)  NOT NULL,
  `task_id`    VARCHAR(64)  NOT NULL,
  `title`      VARCHAR(255) NOT NULL,
  `completed`  TINYINT(1)   NOT NULL DEFAULT 0,
  `position`   INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_subtasks_task` (`task_id`),
  CONSTRAINT `fk_subtasks_task`
    FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `labels` (
  `id`         VARCHAR(64)  NOT NULL,
  `board_id`   VARCHAR(64)  NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `name`       VARCHAR(50)  NOT NULL,
  `color`      VARCHAR(20)  NOT NULL DEFAULT '#4f8ef7',
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_labels_board` (`board_id`),
  KEY `idx_labels_user` (`user_id`),
  CONSTRAINT `fk_labels_board`
    FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_labels_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `task_labels` (
  `task_id`  VARCHAR(64) NOT NULL,
  `label_id` VARCHAR(64) NOT NULL,
  PRIMARY KEY (`task_id`, `label_id`),
  KEY `idx_tl_label` (`label_id`),
  CONSTRAINT `fk_tl_task`
    FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tl_label`
    FOREIGN KEY (`label_id`) REFERENCES `labels` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `comments` (
  `id`         VARCHAR(64)  NOT NULL,
  `task_id`    VARCHAR(64)  NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `content`    TEXT         NOT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_task` (`task_id`),
  KEY `idx_comments_user` (`user_id`),
  CONSTRAINT `fk_comments_task`
    FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `attachments` (
  `id`            VARCHAR(64)   NOT NULL,
  `task_id`       VARCHAR(64)   NOT NULL,
  `user_id`       INT UNSIGNED  NOT NULL,
  `filename`      VARCHAR(255)  NOT NULL,
  `original_name` VARCHAR(255)  NOT NULL,
  `file_size`     INT UNSIGNED  NOT NULL,
  `file_type`     VARCHAR(100)  NOT NULL,
  `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_task` (`task_id`),
  KEY `idx_attachments_user` (`user_id`),
  CONSTRAINT `fk_attachments_task`
    FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attachments_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `board_members` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id`   VARCHAR(64)  NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `role`       ENUM('owner','admin','editor','viewer') NOT NULL DEFAULT 'editor',
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_board_user` (`board_id`, `user_id`),
  KEY `idx_bm_board` (`board_id`),
  KEY `idx_bm_user` (`user_id`),
  CONSTRAINT `fk_bm_board`
    FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bm_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`          VARCHAR(64)  NOT NULL,
  `user_id`     INT UNSIGNED NOT NULL,
  `actor_id`    INT UNSIGNED NULL,
  `type`        VARCHAR(50)  NOT NULL,
  `title`       VARCHAR(255) NOT NULL,
  `message`     TEXT         NULL,
  `entity_type` VARCHAR(50)  NULL,
  `entity_id`   VARCHAR(64)  NULL,
  `is_read`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_read` (`is_read`),
  KEY `idx_notifications_created` (`created_at`),
  CONSTRAINT `fk_notifications_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id`   VARCHAR(64)  NULL,
  `task_id`    VARCHAR(64)  NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `action`     VARCHAR(50)  NOT NULL,
  `details`    TEXT         NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_activity_board` (`board_id`),
  KEY `idx_activity_task` (`task_id`),
  KEY `idx_activity_user` (`user_id`),
  KEY `idx_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id`                  INT UNSIGNED NOT NULL,
  `theme`                    VARCHAR(10)  NOT NULL DEFAULT 'dark',
  `notification_preferences` TEXT         NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_settings_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `endpoint`   TEXT         NOT NULL,
  `p256dh`     VARCHAR(255) NULL,
  `auth`       VARCHAR(255) NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_push_user` (`user_id`),
  CONSTRAINT `fk_push_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications_sent` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` VARCHAR(64)  NOT NULL,
  `sent_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_task` (`task_id`),
  KEY `idx_notifications_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
