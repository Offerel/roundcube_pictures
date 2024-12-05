CREATE TABLE IF NOT EXISTS `pic_broken` (
	`broken_id`	INTEGER,
	`user_id`	INTEGER NOT NULL,
	`pic_path`	TEXT NOT NULL,
	PRIMARY KEY(`broken_id` AUTOINCREMENT),
	UNIQUE(`pic_path`,`user_id`),
	FOREIGN KEY(`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pic_pictures` (
	`pic_id`	INTEGER,
	`pic_path`	TEXT NOT NULL,
	`pic_type`	TEXT NOT NULL,
	`pic_taken`	INTEGER NOT NULL,
	`pic_EXIF`	TEXT,
	`user_id`	INTEGER NOT NULL,
	FOREIGN KEY(`user_id`) REFERENCES `users`(`user_id`) ON DELETE CASCADE,
	PRIMARY KEY(`pic_id` AUTOINCREMENT),
	UNIQUE(`pic_path`,`user_id`)
);

CREATE TABLE IF NOT EXISTS `pic_shared_pictures` (
	`shared_pic_id`	INTEGER,
	`share_id`	INTEGER NOT NULL,
	`user_id`	INTEGER,
	`pic_id`	INTEGER,
	UNIQUE(`share_id`,`pic_id`),
	PRIMARY KEY(`shared_pic_id` AUTOINCREMENT),
	FOREIGN KEY(`pic_id`) REFERENCES `pic_pictures`(`pic_id`) ON DELETE CASCADE,
	FOREIGN KEY(`share_id`) REFERENCES `pic_shares`(`share_id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pic_shares` (
	`share_id`	INTEGER,
	`share_name`	TEXT NOT NULL,
	`share_link`	TEXT NOT NULL,
	`expire_date`	INTEGER,
	`user_id`	INTEGER NOT NULL,
	`share_down` tinyint(1) DEFAULT NULL,
	PRIMARY KEY(`share_id` AUTOINCREMENT),
	FOREIGN KEY(`user_id`) REFERENCES `users`(`user_id`) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `pic_tags` (
  `tag_id` INTEGER,
  `tag_name` TEXT NOT NULL,
  `user_id` INTEGER NOT NULL,
  PRIMARY KEY (`tag_id` AUTOINCREMENT),
  UNIQUE KEY `pic_tags_unique` (`tag_name`,`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS `pic_symlink_map` (
  `smid` INTEGER NOT NULL,
  `user_id` INTEGER NOT NULL,
  `symlink` TEXT NOT NULL,
  `target` TEXT NOT NULL,
  PRIMARY KEY (`smid` AUTOINCREMENT),
  UNIQUE KEY `symlink` (`symlink`,`target`,`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
);