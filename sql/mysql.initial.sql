CREATE TABLE IF NOT EXISTS `pic_broken` (
  `broken_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `pic_path` text NOT NULL,
  PRIMARY KEY (`broken_id`),
  UNIQUE KEY `pic_path` (`pic_path`,`user_id`) USING HASH,
  KEY `user_id` (`user_id`),
  CONSTRAINT `pic_broken_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pic_pictures` (
  `pic_id` int(11) NOT NULL AUTO_INCREMENT,
  `pic_path` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pic_type` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `pic_taken` int(11) NOT NULL,
  `pic_EXIF` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`pic_id`),
  UNIQUE KEY `pic_path` (`pic_path`,`user_id`) USING HASH,
  KEY `user_id` (`user_id`),
  CONSTRAINT `pic_pictures_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27230 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pic_shared_pictures` (
  `shared_pic_id` int(11) NOT NULL AUTO_INCREMENT,
  `share_id` int(11) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `pic_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`shared_pic_id`),
  UNIQUE KEY `share_id` (`share_id`,`pic_id`),
  KEY `pic_id` (`pic_id`),
  CONSTRAINT `pic_shared_pictures_ibfk_1` FOREIGN KEY (`pic_id`) REFERENCES `pic_pictures` (`pic_id`) ON DELETE CASCADE,
  CONSTRAINT `pic_shared_pictures_ibfk_2` FOREIGN KEY (`share_id`) REFERENCES `pic_shares` (`share_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pic_shares` (
  `share_id` int(11) NOT NULL AUTO_INCREMENT,
  `share_name` text NOT NULL,
  `share_link` text NOT NULL,
  `expire_date` int(11) DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`share_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `pic_shares_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pic_tags` (
  `tag_id` int(11) NOT NULL AUTO_INCREMENT,
  `tag_name` varchar(255) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`tag_id`),
  KEY `user_id` (`user_id`),
  UNIQUE KEY `pic_tags_unique` (`tag_name`,`user_id`),
  CONSTRAINT `pic_tags_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `pic_symlink_map` (
  `user_id` int(10) unsigned NOT NULL,
  `symlink` text NOT NULL,
  `target` text NOT NULL,
  UNIQUE KEY `symlink` (`symlink`,`target`,`user_id`) USING HASH,
  KEY `user_id` (`user_id`),
  CONSTRAINT `pic_symlink_map_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;