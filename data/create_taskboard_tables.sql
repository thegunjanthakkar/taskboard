-- ============================================================
--  TasksBoard — Full MySQL Schema  (run once)
--  mysql -u root -p taskboard < data/create_taskboard_tables.sql
-- ============================================================

CREATE TABLE IF NOT EXISTS `boards` (
  `id`         VARCHAR(64)  NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `position`   INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_boards_user_id` (`user_id`),
  CONSTRAINT `fk_boards_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `board_lists` (
  `id`         VARCHAR(64)  NOT NULL,
  `board_id`   VARCHAR(64)  NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `position`   INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_board_lists_board_id` (`board_id`),
  CONSTRAINT `fk_board_lists_board`
    FOREIGN KEY (`board_id`) REFERENCES `boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tasks` (
  `id`          VARCHAR(64)                          NOT NULL,
  `list_id`     VARCHAR(64)                          NOT NULL,
  `title`       VARCHAR(255)                         NOT NULL,
  `description` TEXT                                 NULL,
  `due_date`    DATE                                 NULL,
  `due_time`    TIME                                 NULL,
  `priority`    ENUM('low','medium','high')           NOT NULL DEFAULT 'medium',
  `completed`   TINYINT(1)                           NOT NULL DEFAULT 0,
  `position`    INT                                  NOT NULL DEFAULT 0,
  `created_at`  DATETIME                             DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_list_id`    (`list_id`),
  KEY `idx_tasks_completed`  (`completed`),
  KEY `idx_tasks_due`        (`due_date`, `due_time`),
  CONSTRAINT `fk_tasks_list`
    FOREIGN KEY (`list_id`) REFERENCES `board_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_settings` (
  `user_id` INT UNSIGNED NOT NULL,
  `theme`   VARCHAR(10)  NOT NULL DEFAULT 'dark',
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

-- notifications_sent: task_id is UNIQUE so INSERT IGNORE prevents duplicates
CREATE TABLE IF NOT EXISTS `notifications_sent` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `task_id` VARCHAR(64)  NOT NULL,
  `sent_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notifications_task` (`task_id`),   -- ← prevents double-send
  KEY `idx_notifications_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
