USE wtrs_db;
ALTER TABLE users 
ADD COLUMN student_id varchar(50) DEFAULT NULL AFTER role,
ADD COLUMN course varchar(150) DEFAULT NULL AFTER college,
ADD COLUMN year_level varchar(20) DEFAULT NULL AFTER course;
