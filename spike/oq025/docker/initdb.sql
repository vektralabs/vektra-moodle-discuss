CREATE DATABASE IF NOT EXISTS moodle43 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS moodle52 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON moodle43.* TO 'moodleuser'@'%';
GRANT ALL ON moodle52.* TO 'moodleuser'@'%';
