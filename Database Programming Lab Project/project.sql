-- phpMyAdmin SQL Dump
-- version 5.1.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Nov 23, 2025 at 05:32 AM
-- Server version: 5.7.24
-- PHP Version: 8.2.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `project`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `cancel_reservation` (IN `p_reserve_id` INT, IN `p_user_id` INT, OUT `p_is_refunded` BOOLEAN)   BEGIN
    DECLARE v_claim_deadline DATETIME;
    DECLARE v_table_id INT;
    DECLARE done INT DEFAULT FALSE;
    
    DECLARE cur CURSOR FOR
        SELECT table_id
        FROM reservation_tables
        WHERE reserve_id = p_reserve_id;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- ดึง claim_deadline_at
    SELECT claim_deadline_at INTO v_claim_deadline
    FROM reservation
    WHERE reserve_id = p_reserve_id
    AND user_id = p_user_id;
    
    IF v_claim_deadline IS NULL THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Reservation not found or access denied';
    END IF;
    
    -- เช็คว่ายกเลิกก่อน deadline หรือไม่
    IF NOW() < v_claim_deadline THEN
        SET p_is_refunded = TRUE;
        
        -- Refund payment
        UPDATE payment
        SET payment_status = 'REFUNDED'
        WHERE reserve_id = p_reserve_id
        AND payment_status = 'PAID';
    ELSE
        SET p_is_refunded = FALSE;
    END IF;
    
    -- อัพเดท reservation status
    UPDATE reservation
    SET reserve_status = 'CANCELLED',
        cancelled_at = NOW()
    WHERE reserve_id = p_reserve_id;
    
    -- คืนสถานะโต๊ะ
    OPEN cur;
    
    read_loop: LOOP
        FETCH cur INTO v_table_id;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        UPDATE `table`
        SET table_status = 'AVAILABLE'
        WHERE table_id = v_table_id;
    END LOOP;
    
    CLOSE cur;
    
    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `pay_and_hold` (IN `p_user_id` INT, IN `p_restaurant_id` INT, IN `p_party_size` INT, IN `p_reserve_date` DATE, IN `p_reserve_deposit` DECIMAL(10,2), IN `p_payment_method` VARCHAR(50), IN `p_table_ids` TEXT)   BEGIN
    DECLARE v_table_id INT;
    DECLARE v_pos INT;
    DECLARE v_table_ids_copy TEXT;
    DECLARE v_table_status VARCHAR(20);
    DECLARE v_existing_reservation INT;
    DECLARE v_table_count INT DEFAULT 0;
    DECLARE v_reserve_id INT;
    
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    --  เพิ่มการตรวจสอบวันที่ย้อนหลัง
    IF p_reserve_date < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot book in the past! Please select today or future date.';
    END IF;
    
    -- ตรวจสอบโต๊ะทั้งหมด
    IF p_table_ids IS NULL OR p_table_ids = '' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No tables selected';
    END IF;
    
    SET v_table_ids_copy = CONCAT(p_table_ids, ',');
    
    --  LOOP 1: ตรวจสอบว่าโต๊ะว่างและไม่ถูกจอง
    WHILE LENGTH(v_table_ids_copy) > 0 DO
        SET v_pos = LOCATE(',', v_table_ids_copy);
        
        IF v_pos > 0 THEN
            SET v_table_id = CAST(TRIM(SUBSTRING(v_table_ids_copy, 1, v_pos - 1)) AS UNSIGNED);
            
            -- เช็ค table_status
            SELECT table_status INTO v_table_status
            FROM `table`
            WHERE table_id = v_table_id;
            
            IF v_table_status IS NULL THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'Invalid table ID';
            END IF;
            
            IF v_table_status != 'AVAILABLE' THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'One or more tables are unavailable';
            END IF;
            
            -- เช็คว่ามีการจองวันนี้แล้วหรือยัง
            SELECT COUNT(*) INTO v_existing_reservation
            FROM reservation_tables rt
            INNER JOIN reservation r ON rt.reserve_id = r.reserve_id
            WHERE rt.table_id = v_table_id
            AND r.reserve_date = p_reserve_date
            AND r.reserve_status IN ('HOLD', 'CONFIRMED');
            
            IF v_existing_reservation > 0 THEN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'One or more tables are already booked for this date';
            END IF;
            
            SET v_table_count = v_table_count + 1;
            SET v_table_ids_copy = SUBSTRING(v_table_ids_copy, v_pos + 1);
        ELSE
            SET v_table_ids_copy = '';
        END IF;
    END WHILE;
    
    IF v_table_count = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'No valid tables found';
    END IF;
    
    -- สร้าง reservation
    INSERT INTO reservation (
        user_id, restaurant_id, party_size, 
        reserve_status, reserve_date, reserve_deposit
    ) VALUES (
        p_user_id, p_restaurant_id, p_party_size,
        'HOLD', p_reserve_date, p_reserve_deposit
    );
    
    SET v_reserve_id = LAST_INSERT_ID();
    
    -- สร้าง payment
    INSERT INTO payment (
        reserve_id, payment_amount, payment_status,
        paid_at, payment_method
    ) VALUES (
        v_reserve_id, p_reserve_deposit, 'PAID',
        NOW(), p_payment_method
    );
    
    --  LOOP 2: เชื่อมโยงโต๊ะ +  UPDATE table_status = 'HOLD'
    SET v_table_ids_copy = CONCAT(p_table_ids, ',');
    
    WHILE LENGTH(v_table_ids_copy) > 0 DO
        SET v_pos = LOCATE(',', v_table_ids_copy);
        
        IF v_pos > 0 THEN
            SET v_table_id = CAST(TRIM(SUBSTRING(v_table_ids_copy, 1, v_pos - 1)) AS UNSIGNED);
            
            -- INSERT reservation_tables
            INSERT INTO reservation_tables (reserve_id, table_id) 
            VALUES (v_reserve_id, v_table_id);
            
            --  NEW: UPDATE table status เป็น HOLD
            UPDATE `table`
            SET table_status = 'UNAVAILABLE'
            WHERE table_id = v_table_id;
            
            SET v_table_ids_copy = SUBSTRING(v_table_ids_copy, v_pos + 1);
        ELSE
            SET v_table_ids_copy = '';
        END IF;
    END WHILE;
    
    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `release_expired_holds` ()   BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    -- 1) เปลี่ยน reservation ที่หมดเวลาเป็น CANCELLED
    UPDATE reservation
    SET reserve_status = 'CANCELLED',
        cancelled_at = NOW()
    WHERE reserve_status = 'HOLD'
      AND claim_deadline_at < NOW();

    -- 2) อัพเดทโต๊ะทั้งหมดที่เกี่ยวข้องกับ reservations ที่เพิ่งถูก CANCELLED
    UPDATE `table` t
    JOIN (
        SELECT DISTINCT rt.table_id
        FROM reservation r
        JOIN reservation_tables rt ON r.reserve_id = rt.reserve_id
        WHERE r.reserve_status = 'CANCELLED'
          AND r.cancelled_at >= (NOW() - INTERVAL 5 MINUTE)
    ) sub ON t.table_id = sub.table_id
    SET t.table_status = 'AVAILABLE';

    COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `reset_tables_after_closing` ()   BEGIN
    DECLARE v_tables_reset INT DEFAULT 0;
    DECLARE v_current_datetime DATETIME;
    DECLARE v_yesterday_date DATE;
    DECLARE v_today_date DATE;
    
    -- เวลาปัจจุบัน (แบบ DATETIME)
    SET v_current_datetime = NOW();
    SET v_today_date = CURDATE();
    SET v_yesterday_date = DATE_SUB(CURDATE(), INTERVAL 1 DAY);
    
    -- รีเซ็ตโต๊ะที่ OCCUPIED กลับเป็น AVAILABLE
    UPDATE `table` t
    INNER JOIN area a ON t.area_id = a.area_id
    INNER JOIN restaurant r ON a.restaurant_id = r.restaurant_id
    SET t.table_status = 'AVAILABLE'
    WHERE t.table_status = 'OCCUPIED'
    --  เช็คว่าร้านปิดแล้วเกิน 1 ชม. (รองรับข้ามวัน)
    AND (
        -- กรณีที่ 1: ร้านปิดวันนี้แล้วเกิน 1 ชม.
        (
            v_current_datetime >= TIMESTAMP(v_today_date, ADDTIME(r.close_time, '01:00:00'))
        )
        OR
        -- กรณีที่ 2: ร้านปิดเมื่อวาน (ข้ามเที่ยงคืนแล้ว)
        (
            TIME(v_current_datetime) < r.close_time
            AND v_current_datetime >= TIMESTAMP(v_yesterday_date, ADDTIME(r.close_time, '01:00:00'))
        )
    )
    -- ไม่มีการจองวันนี้หรืออนาคต
    AND NOT EXISTS (
        SELECT 1 
        FROM reservation_tables rt
        INNER JOIN reservation res ON rt.reserve_id = res.reserve_id
        WHERE rt.table_id = t.table_id
        AND res.reserve_date >= v_today_date
        AND res.reserve_status IN ('HOLD', 'CONFIRMED')
    );
    
    -- นับจำนวนโต๊ะที่ถูก reset
    SET v_tables_reset = ROW_COUNT();
    
    -- Log ผลลัพธ์
    SELECT 
        CONCAT('Reset completed: ', v_tables_reset, ' tables set to AVAILABLE') AS result,
        v_current_datetime AS current_datetime,
        NOW() AS reset_at;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `send_deadline_warning` ()   BEGIN
    -- Insert reminders for HOLD reservations expiring within 30 minutes
    INSERT IGNORE INTO notification (user_id, reserve_id, noti_type, noti_title, noti_message)
    SELECT r.user_id, r.reserve_id, 'REMINDER',
           'Booking Deadline Reminder',
           CONCAT('Your booking at ', res.rest_name, ' expires at ',
                  TIME_FORMAT(r.claim_deadline_at, '%H:%i'),
                  '. Please check-in before this time.')
    FROM reservation r
    JOIN restaurant res ON r.restaurant_id = res.restaurant_id
    WHERE r.reserve_status = 'HOLD'
      AND r.claim_deadline_at > NOW()
      AND r.claim_deadline_at < DATE_ADD(NOW(), INTERVAL 30 MINUTE);
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`admin_id`, `user_id`) VALUES
(1, 6);

-- --------------------------------------------------------

--
-- Table structure for table `area`
--

CREATE TABLE `area` (
  `area_id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `area_name` varchar(50) NOT NULL,
  `area_type` enum('INFRONT','BACKSIDE') NOT NULL,
  `area_price` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `area`
--

INSERT INTO `area` (`area_id`, `restaurant_id`, `area_name`, `area_type`, `area_price`) VALUES
(1, 1, 'Front Rows', 'INFRONT', '400.00'),
(2, 1, 'Back Rows', 'BACKSIDE', '200.00'),
(3, 2, 'Front Rows', 'INFRONT', '400.00'),
(4, 2, 'Back Rows', 'BACKSIDE', '200.00');

-- --------------------------------------------------------

--
-- Table structure for table `checkin`
--

CREATE TABLE `checkin` (
  `checkin_id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `reserve_id` int(11) NOT NULL,
  `table_id` int(11) NOT NULL,
  `checkin_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `checkin`
--

INSERT INTO `checkin` (`checkin_id`, `staff_id`, `reserve_id`, `table_id`, `checkin_at`) VALUES
(13, 1, 39, 23, '2025-11-17 20:42:51'),
(14, 1, 50, 11, '2025-11-17 23:25:17'),
(15, 1, 49, 19, '2025-11-17 23:25:19');

-- --------------------------------------------------------

--
-- Table structure for table `login_history`
--

CREATE TABLE `login_history` (
  `login_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `login_history`
--

INSERT INTO `login_history` (`login_id`, `user_id`, `login_at`) VALUES
(1, 1, '2025-11-15 09:34:54'),
(2, 1, '2025-11-15 10:39:19'),
(3, 1, '2025-11-15 11:05:54'),
(12, 1, '2025-11-15 19:15:38'),
(23, 1, '2025-11-16 01:02:56'),
(24, 1, '2025-11-16 01:11:19'),
(30, 1, '2025-11-16 05:58:13'),
(33, 1, '2025-11-16 22:07:21'),
(37, 1, '2025-11-17 16:14:00'),
(44, 1, '2025-11-17 22:20:55'),
(45, 1, '2025-11-18 01:26:41'),
(4, 2, '2025-11-15 12:45:53'),
(16, 2, '2025-11-15 20:31:04'),
(32, 2, '2025-11-16 06:07:14'),
(9, 4, '2025-11-15 18:09:21'),
(10, 4, '2025-11-15 19:13:18'),
(11, 4, '2025-11-15 19:14:40'),
(13, 4, '2025-11-15 20:19:10'),
(18, 4, '2025-11-15 21:16:17'),
(21, 4, '2025-11-16 00:08:21'),
(26, 4, '2025-11-16 01:20:37'),
(29, 4, '2025-11-16 05:58:06'),
(34, 4, '2025-11-16 22:07:28'),
(39, 4, '2025-11-17 17:30:35'),
(41, 4, '2025-11-17 17:31:08'),
(43, 4, '2025-11-17 20:56:09'),
(14, 5, '2025-11-15 20:20:25'),
(15, 5, '2025-11-15 20:29:27'),
(17, 5, '2025-11-15 21:16:05'),
(19, 5, '2025-11-15 21:20:30'),
(20, 5, '2025-11-16 00:08:12'),
(22, 5, '2025-11-16 00:18:52'),
(25, 5, '2025-11-16 01:20:26'),
(27, 5, '2025-11-16 01:20:59'),
(31, 5, '2025-11-16 05:58:20'),
(38, 5, '2025-11-17 17:30:29'),
(40, 5, '2025-11-17 17:31:04'),
(5, 6, '2025-11-15 15:46:56'),
(6, 6, '2025-11-15 15:47:07'),
(7, 6, '2025-11-15 15:47:14'),
(8, 6, '2025-11-15 15:47:21'),
(28, 6, '2025-11-16 05:57:53'),
(35, 6, '2025-11-16 22:07:38'),
(36, 6, '2025-11-17 16:13:51'),
(42, 6, '2025-11-17 20:52:34');

-- --------------------------------------------------------

--
-- Table structure for table `logout_history`
--

CREATE TABLE `logout_history` (
  `logout_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `logout_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `logout_history`
--

INSERT INTO `logout_history` (`logout_id`, `user_id`, `logout_at`) VALUES
(1, 1, '2025-11-15 10:44:43'),
(3, 1, '2025-11-15 15:33:07'),
(18, 1, '2025-11-16 01:02:51'),
(19, 1, '2025-11-16 01:11:13'),
(22, 1, '2025-11-16 05:56:28'),
(24, 1, '2025-11-16 06:02:06'),
(28, 1, '2025-11-17 16:11:23'),
(33, 1, '2025-11-18 01:08:28'),
(2, 2, '2025-11-15 15:32:57'),
(14, 2, '2025-11-16 00:07:51'),
(27, 2, '2025-11-16 22:07:17'),
(8, 4, '2025-11-15 20:18:34'),
(9, 4, '2025-11-15 20:29:21'),
(13, 4, '2025-11-15 21:20:23'),
(16, 4, '2025-11-16 00:18:21'),
(23, 4, '2025-11-16 05:58:15'),
(29, 4, '2025-11-17 16:11:33'),
(31, 4, '2025-11-17 17:30:59'),
(10, 5, '2025-11-15 20:29:34'),
(11, 5, '2025-11-15 21:16:00'),
(12, 5, '2025-11-15 21:16:13'),
(15, 5, '2025-11-16 00:08:18'),
(17, 5, '2025-11-16 00:18:34'),
(20, 5, '2025-11-16 01:20:34'),
(21, 5, '2025-11-16 01:20:55'),
(25, 5, '2025-11-16 22:07:05'),
(32, 5, '2025-11-17 17:31:06'),
(4, 6, '2025-11-15 15:34:01'),
(5, 6, '2025-11-15 15:46:59'),
(6, 6, '2025-11-15 15:47:09'),
(7, 6, '2025-11-15 15:47:16'),
(26, 6, '2025-11-16 22:07:10'),
(30, 6, '2025-11-17 16:11:38');

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `noti_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `reserve_id` int(11) NOT NULL,
  `noti_type` enum('BOOKED','CHECKIN','CANCELLED','REMINDER') DEFAULT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_read` enum('Y','N') DEFAULT 'N',
  `noti_title` varchar(100) DEFAULT NULL,
  `noti_message` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `notification`
--

INSERT INTO `notification` (`noti_id`, `user_id`, `reserve_id`, `noti_type`, `sent_at`, `is_read`, `noti_title`, `noti_message`) VALUES
(60, 1, 39, 'BOOKED', '2025-11-17 20:42:34', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(61, 1, 39, 'CHECKIN', '2025-11-17 20:42:51', 'Y', 'Check-in Successful', 'Welcome to The Chill Bar & Restaurant! You have successfully checked in for your reservation on November 17, 2025. Enjoy your meal!'),
(62, 1, 40, 'BOOKED', '2025-11-17 22:21:09', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(63, 1, 40, 'CANCELLED', '2025-11-17 22:25:31', 'Y', 'Booking Cancelled', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 has been cancelled.'),
(64, 1, 41, 'BOOKED', '2025-11-17 22:43:41', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(65, 1, 41, 'CANCELLED', '2025-11-17 22:45:31', 'Y', 'Booking Cancelled', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 has been cancelled.'),
(80, 1, 49, 'BOOKED', '2025-11-17 23:20:42', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(81, 1, 50, 'BOOKED', '2025-11-17 23:21:11', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 17, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(82, 1, 50, 'CHECKIN', '2025-11-17 23:25:17', 'Y', 'Check-in Successful', 'Welcome to The Chill Bar & Restaurant! You have successfully checked in for your reservation on November 17, 2025. Enjoy your meal!'),
(83, 1, 49, 'CHECKIN', '2025-11-17 23:25:19', 'Y', 'Check-in Successful', 'Welcome to The Chill Bar & Restaurant! You have successfully checked in for your reservation on November 17, 2025. Enjoy your meal!'),
(84, 1, 51, 'BOOKED', '2025-11-18 10:08:57', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 18, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(85, 1, 51, 'CANCELLED', '2025-11-18 10:09:11', 'Y', 'Booking Cancelled', 'Your booking at The Chill Bar & Restaurant on November 18, 2025 has been cancelled.'),
(86, 1, 52, 'BOOKED', '2025-11-18 10:09:30', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 18, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(87, 1, 52, 'CANCELLED', '2025-11-18 10:09:44', 'Y', 'Booking Cancelled', 'Your booking at The Chill Bar & Restaurant on November 18, 2025 has been cancelled.'),
(88, 1, 53, 'BOOKED', '2025-11-18 11:27:15', 'Y', 'Booking On Hold', 'Your booking at The Chill Bar & Restaurant on November 18, 2025 is on hold. Please check-in before 20:00 to confirm your reservation.'),
(89, 1, 53, 'REMINDER', '2025-11-18 11:31:28', 'Y', 'Booking Deadline Reminder', 'Your booking at The Chill Bar & Restaurant expires at 12:00. Please check-in before this time.'),
(90, 1, 53, 'CANCELLED', '2025-11-18 11:33:11', 'Y', 'Booking Cancelled', 'Your booking at The Chill Bar & Restaurant on November 18, 2025 has been cancelled.');

-- --------------------------------------------------------

--
-- Table structure for table `payment`
--

CREATE TABLE `payment` (
  `payment_id` int(11) NOT NULL,
  `reserve_id` int(11) NOT NULL,
  `payment_amount` decimal(10,2) NOT NULL,
  `payment_status` enum('PENDING','PAID','REFUNDED') DEFAULT 'PENDING',
  `paid_at` datetime DEFAULT NULL,
  `payment_method` enum('Credit Card','PayPal','Qrcode') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `payment`
--

INSERT INTO `payment` (`payment_id`, `reserve_id`, `payment_amount`, `payment_status`, `paid_at`, `payment_method`) VALUES
(38, 39, '400.00', 'PAID', '2025-11-17 20:42:34', 'PayPal'),
(39, 40, '400.00', 'PAID', '2025-11-17 22:21:09', 'Qrcode'),
(40, 41, '400.00', 'PAID', '2025-11-17 22:43:41', 'Qrcode'),
(48, 49, '400.00', 'PAID', '2025-11-17 23:20:42', 'PayPal'),
(49, 50, '800.00', 'PAID', '2025-11-17 23:21:11', 'PayPal'),
(50, 51, '800.00', 'REFUNDED', '2025-11-18 10:08:57', 'Qrcode'),
(51, 52, '800.00', 'REFUNDED', '2025-11-18 10:09:30', 'PayPal'),
(52, 53, '200.00', 'REFUNDED', '2025-11-18 11:27:15', 'PayPal');

-- --------------------------------------------------------

--
-- Table structure for table `policy`
--

CREATE TABLE `policy` (
  `policy_id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `min_age` int(11) NOT NULL,
  `claim_by_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `policy`
--

INSERT INTO `policy` (`policy_id`, `restaurant_id`, `min_age`, `claim_by_time`) VALUES
(1, 1, 20, '20:00:00'),
(2, 2, 20, '21:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `reservation`
--

CREATE TABLE `reservation` (
  `reserve_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL,
  `party_size` int(10) UNSIGNED NOT NULL,
  `reserve_status` enum('HOLD','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'HOLD',
  `reserve_date` date NOT NULL,
  `claim_deadline_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `reserve_deposit` decimal(10,2) NOT NULL,
  `cancelled_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `reservation`
--

INSERT INTO `reservation` (`reserve_id`, `user_id`, `restaurant_id`, `party_size`, `reserve_status`, `reserve_date`, `claim_deadline_at`, `created_at`, `reserve_deposit`, `cancelled_at`) VALUES
(39, 1, 1, 6, 'CONFIRMED', '2025-11-17', '2025-11-17 20:00:00', '2025-11-17 20:42:34', '400.00', NULL),
(40, 1, 1, 7, 'CANCELLED', '2025-11-17', '2025-11-17 20:00:00', '2025-11-17 22:21:09', '400.00', '2025-11-17 22:25:31'),
(41, 1, 1, 6, 'CANCELLED', '2025-11-17', '2025-11-17 20:00:00', '2025-11-17 22:43:41', '400.00', '2025-11-17 22:45:31'),
(49, 1, 1, 5, 'CONFIRMED', '2025-11-17', '2025-11-17 20:00:00', '2025-11-17 23:20:42', '400.00', NULL),
(50, 1, 1, 6, 'CONFIRMED', '2025-11-17', '2025-11-17 20:00:00', '2025-11-17 23:21:11', '800.00', NULL),
(51, 1, 1, 5, 'CANCELLED', '2025-11-18', '2025-11-18 20:00:00', '2025-11-18 10:08:57', '800.00', '2025-11-18 10:09:11'),
(52, 1, 1, 7, 'CANCELLED', '2025-11-18', '2025-11-18 20:00:00', '2025-11-18 10:09:30', '800.00', '2025-11-18 10:09:44'),
(53, 1, 1, 3, 'CANCELLED', '2025-11-18', '2025-11-18 12:00:00', '2025-11-18 11:27:15', '200.00', '2025-11-18 11:33:11');

--
-- Triggers `reservation`
--
DELIMITER $$
CREATE TRIGGER `reservation_age_guard` BEFORE INSERT ON `reservation` FOR EACH ROW BEGIN
    DECLARE user_age INT;
    DECLARE min_age_required INT;
    DECLARE user_dob DATE;
    
    -- ดึงวันเกิดของ user
    SELECT dob INTO user_dob
    FROM user
    WHERE user_id = NEW.user_id;
    
    -- คำนวณอายุจาก dob
    SET user_age = TIMESTAMPDIFF(YEAR, user_dob, CURDATE());
    
    -- ดึง min_age จาก policy
    SELECT min_age INTO min_age_required
    FROM policy
    WHERE restaurant_id = NEW.restaurant_id
    LIMIT 1;
    
    -- ตรวจสอบอายุ
    IF user_age < min_age_required THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'User age does not meet minimum age requirement';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reservation_booked_noti` AFTER INSERT ON `reservation` FOR EACH ROW BEGIN
    DECLARE rest_name_var VARCHAR(100);
    DECLARE noti_title_var VARCHAR(100);
    DECLARE noti_message_var TEXT;
    
    -- ดึงชื่อร้านอาหาร
    SELECT rest_name INTO rest_name_var
    FROM restaurant
    WHERE restaurant_id = NEW.restaurant_id;
    
    -- กำหนดข้อความสำหรับการจอง
    IF NEW.reserve_status = 'HOLD' THEN
        SET noti_title_var = 'Booking On Hold';
        SET noti_message_var = CONCAT(
            'Your booking at ', rest_name_var, 
            ' on ', DATE_FORMAT(NEW.reserve_date, '%M %d, %Y'), 
            ' is on hold. Please check-in before ', 
            TIME_FORMAT(NEW.claim_deadline_at, '%H:%i'),
            ' to confirm your reservation.'
        );
    ELSE
        -- กรณีจองแบบอื่นๆ (ถ้ามี)
        SET noti_title_var = 'Booking Received';
        SET noti_message_var = CONCAT(
            'Your booking at ', rest_name_var, 
            ' on ', DATE_FORMAT(NEW.reserve_date, '%M %d, %Y'), 
            ' has been received.'
        );
    END IF;
    
    -- สร้าง notification แบบ BOOKED
    INSERT INTO notification (user_id, reserve_id, noti_type, noti_title, noti_message)
    VALUES (
        NEW.user_id,
        NEW.reserve_id,
        'BOOKED',
        noti_title_var,
        noti_message_var
    );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reservation_cancel_noti` AFTER UPDATE ON `reservation` FOR EACH ROW BEGIN
    DECLARE rest_name_var VARCHAR(100);
    
    -- เช็คว่า status เปลี่ยนเป็น CANCELLED
    IF OLD.reserve_status != 'CANCELLED' AND NEW.reserve_status = 'CANCELLED' THEN
        -- ดึงชื่อร้านอาหาร
        SELECT rest_name INTO rest_name_var
        FROM restaurant
        WHERE restaurant_id = NEW.restaurant_id;
        
        -- สร้าง notification
        INSERT INTO notification (user_id, reserve_id, noti_type, noti_title, noti_message)
        VALUES (
            NEW.user_id,
            NEW.reserve_id,
            'CANCELLED',
            'Booking Cancelled',
            CONCAT('Your booking at ', rest_name_var, ' on ', DATE_FORMAT(NEW.reserve_date, '%M %d, %Y'), ' has been cancelled.')
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reservation_checkin_noti` AFTER UPDATE ON `reservation` FOR EACH ROW BEGIN
    DECLARE rest_name_var VARCHAR(100);
    
    -- ตรวจสอบว่าเปลี่ยนสถานะเป็น CONFIRMED (check-in)
    IF OLD.reserve_status != 'CONFIRMED' AND NEW.reserve_status = 'CONFIRMED' THEN
        
        -- ดึงชื่อร้านอาหาร
        SELECT rest_name INTO rest_name_var
        FROM restaurant
        WHERE restaurant_id = NEW.restaurant_id;
        
        -- สร้าง notification แบบ CHECKIN
        INSERT INTO notification (user_id, reserve_id, noti_type, noti_title, noti_message)
        VALUES (
            NEW.user_id,
            NEW.reserve_id,
            'CHECKIN',
            'Check-in Successful',
            CONCAT(
                'Welcome to ', rest_name_var, '! ',
                'You have successfully checked in for your reservation on ',
                DATE_FORMAT(NEW.reserve_date, '%M %d, %Y'), '. ',
                'Enjoy your meal!'
            )
        );
        
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reservation_day_guard` BEFORE INSERT ON `reservation` FOR EACH ROW BEGIN
    -- ป้องกันการจองย้อนหลัง
    IF NEW.reserve_date < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot book in the past! Please select today or future date.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reservation_deadline_calc` BEFORE INSERT ON `reservation` FOR EACH ROW BEGIN
    DECLARE claim_time TIME;
    
    -- ดึง claim_by_time จาก policy ของร้าน
    SELECT claim_by_time INTO claim_time
    FROM policy
    WHERE restaurant_id = NEW.restaurant_id
    LIMIT 1;
        
    -- คำนวณ claim_deadline_at = reserve_date + claim_by_time
    SET NEW.claim_deadline_at = TIMESTAMP(NEW.reserve_date, claim_time);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `reservation_lock_deposit` BEFORE UPDATE ON `reservation` FOR EACH ROW BEGIN
    -- ห้ามแก้ไข reserve_deposit (ยกเว้น INSERT ครั้งแรก)
    IF OLD.reserve_deposit IS NOT NULL AND NEW.reserve_deposit != OLD.reserve_deposit THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot modify reserve_deposit after creation';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `reservation_tables`
--

CREATE TABLE `reservation_tables` (
  `reserve_id` int(11) NOT NULL,
  `table_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `reservation_tables`
--

INSERT INTO `reservation_tables` (`reserve_id`, `table_id`) VALUES
(52, 1),
(52, 7),
(51, 9),
(51, 10),
(50, 11),
(50, 12),
(40, 19),
(41, 19),
(49, 19),
(40, 20),
(41, 20),
(49, 20),
(53, 22),
(39, 23),
(39, 24);

-- --------------------------------------------------------

--
-- Table structure for table `restaurant`
--

CREATE TABLE `restaurant` (
  `restaurant_id` int(11) NOT NULL,
  `rest_name` varchar(100) NOT NULL,
  `rest_description` text NOT NULL,
  `open_time` time NOT NULL,
  `close_time` time NOT NULL,
  `rest_phone` varchar(10) NOT NULL,
  `rest_status` enum('OPEN','CLOSED') DEFAULT 'OPEN',
  `rest_address` text NOT NULL,
  `rest_email` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `restaurant`
--

INSERT INTO `restaurant` (`restaurant_id`, `rest_name`, `rest_description`, `open_time`, `close_time`, `rest_phone`, `rest_status`, `rest_address`, `rest_email`) VALUES
(1, 'The Chill Bar & Restaurant', 'Experience the perfect blend of music and mixology at The Chill Bar. Our venue features live DJ performances, craft cocktails, and a vibrant atmosphere perfect for night owls. With three distinct zones including VIP seating and stage-front tables, we cater to every mood and occasion.', '17:00:00', '02:00:00', '0812345678', 'OPEN', '123 Sukhumvit Rd, Bangkok', 'info@thechillbar.com'),
(2, 'Brewly Lounge & Dining', 'Brewly Lounge & Dining offers an upscale dining experience with our signature craft beers and gourmet cuisine. Our elegant Main Hall and exclusive Private Room provide the perfect setting for both casual gatherings and special celebrations. Enjoy live music performances every weekend.', '18:00:00', '01:00:00', '0823456789', 'OPEN', '456 Silom Rd, Bangkok', 'contact@brewly.com');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `restaurant_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`staff_id`, `user_id`, `restaurant_id`) VALUES
(1, 4, 1),
(2, 5, 2);

-- --------------------------------------------------------

--
-- Table structure for table `table`
--

CREATE TABLE `table` (
  `table_id` int(11) NOT NULL,
  `area_id` int(11) NOT NULL,
  `table_number` varchar(10) NOT NULL,
  `capacity` int(10) UNSIGNED NOT NULL,
  `table_status` enum('AVAILABLE','OCCUPIED','UNAVAILABLE') DEFAULT 'AVAILABLE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `table`
--

INSERT INTO `table` (`table_id`, `area_id`, `table_number`, `capacity`, `table_status`) VALUES
(1, 1, 'F1', 4, 'AVAILABLE'),
(2, 1, 'F2', 4, 'AVAILABLE'),
(3, 1, 'F3', 4, 'AVAILABLE'),
(4, 1, 'F4', 4, 'AVAILABLE'),
(5, 1, 'F5', 4, 'AVAILABLE'),
(6, 1, 'F6', 4, 'AVAILABLE'),
(7, 1, 'F7', 4, 'AVAILABLE'),
(8, 1, 'F8', 4, 'AVAILABLE'),
(9, 1, 'F9', 4, 'AVAILABLE'),
(10, 1, 'F10', 4, 'AVAILABLE'),
(11, 1, 'F11', 4, 'AVAILABLE'),
(12, 1, 'F12', 4, 'AVAILABLE'),
(13, 2, 'B1', 4, 'AVAILABLE'),
(14, 2, 'B2', 4, 'AVAILABLE'),
(15, 2, 'B3', 4, 'AVAILABLE'),
(16, 2, 'B4', 4, 'AVAILABLE'),
(17, 2, 'B5', 4, 'AVAILABLE'),
(18, 2, 'B6', 4, 'AVAILABLE'),
(19, 2, 'B7', 4, 'AVAILABLE'),
(20, 2, 'B8', 4, 'AVAILABLE'),
(21, 2, 'B9', 4, 'AVAILABLE'),
(22, 2, 'B10', 4, 'AVAILABLE'),
(23, 2, 'B11', 4, 'AVAILABLE'),
(24, 2, 'B12', 4, 'AVAILABLE'),
(25, 3, 'F1', 4, 'AVAILABLE'),
(26, 3, 'F2', 4, 'AVAILABLE'),
(27, 3, 'F3', 4, 'AVAILABLE'),
(28, 3, 'F4', 4, 'AVAILABLE'),
(29, 3, 'F5', 4, 'AVAILABLE'),
(30, 3, 'F6', 4, 'AVAILABLE'),
(31, 3, 'F7', 4, 'AVAILABLE'),
(32, 3, 'F8', 4, 'AVAILABLE'),
(33, 3, 'F9', 4, 'AVAILABLE'),
(34, 3, 'F10', 4, 'AVAILABLE'),
(35, 3, 'F11', 4, 'AVAILABLE'),
(36, 3, 'F12', 4, 'AVAILABLE'),
(37, 4, 'B1', 4, 'AVAILABLE'),
(38, 4, 'B2', 4, 'AVAILABLE'),
(39, 4, 'B3', 4, 'AVAILABLE'),
(40, 4, 'B4', 4, 'AVAILABLE'),
(41, 4, 'B5', 4, 'AVAILABLE'),
(42, 4, 'B6', 4, 'AVAILABLE'),
(43, 4, 'B7', 4, 'AVAILABLE'),
(44, 4, 'B8', 4, 'AVAILABLE'),
(45, 4, 'B9', 4, 'AVAILABLE'),
(46, 4, 'B10', 4, 'AVAILABLE'),
(47, 4, 'B11', 4, 'AVAILABLE'),
(48, 4, 'B12', 4, 'AVAILABLE');

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `firstname` varchar(50) NOT NULL,
  `lastname` varchar(50) NOT NULL,
  `dob` date NOT NULL,
  `user_phone` varchar(10) NOT NULL,
  `role` enum('CUSTOMER','STAFF','ADMIN') NOT NULL DEFAULT 'CUSTOMER',
  `user_created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_active` enum('Y','N') DEFAULT 'N'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`user_id`, `email`, `username`, `password`, `firstname`, `lastname`, `dob`, `user_phone`, `role`, `user_created_at`, `is_active`) VALUES
(1, 'john@email.com', 'john123', '03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4', 'John', 'Doe', '1995-05-15', '0891234567', 'CUSTOMER', '2025-11-14 16:12:26', 'Y'),
(2, 'jane@email.com', 'jane456', '03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4', 'Jane', 'Smith', '1998-08-20', '0892345678', 'CUSTOMER', '2025-11-14 16:12:26', 'N'),
(3, 'mike@email.com', 'mike789', '03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4', 'Mike', 'Johnson', '1990-12-10', '0893456789', 'CUSTOMER', '2025-11-14 16:12:26', 'N'),
(4, 'staff1@email.com', 'staff1', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'Sarah', 'Wilson', '1990-01-01', '0894567890', 'STAFF', '2025-11-14 16:12:26', 'Y'),
(5, 'staff2@email.com', 'staff2', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'Tom', 'Brown', '1992-05-15', '0895678901', 'STAFF', '2025-11-14 16:12:26', 'N'),
(6, 'admin@email.com', 'admin', '6b51d431df5d7f141cbececcf79edf3dd861c3b4069f0b11661a3eefacbba918', 'Admin', 'User', '1985-12-31', '0896789012', 'ADMIN', '2025-11-14 16:12:27', 'Y');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `area`
--
ALTER TABLE `area`
  ADD PRIMARY KEY (`area_id`),
  ADD KEY `idx_restaurant` (`restaurant_id`);

--
-- Indexes for table `checkin`
--
ALTER TABLE `checkin`
  ADD PRIMARY KEY (`checkin_id`),
  ADD UNIQUE KEY `reserve_id` (`reserve_id`),
  ADD KEY `table_id` (`table_id`),
  ADD KEY `idx_checkin_at` (`checkin_at`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `login_history`
--
ALTER TABLE `login_history`
  ADD PRIMARY KEY (`login_id`),
  ADD KEY `idx_user_login` (`user_id`,`login_at`),
  ADD KEY `idx_login_time` (`login_at`);

--
-- Indexes for table `logout_history`
--
ALTER TABLE `logout_history`
  ADD PRIMARY KEY (`logout_id`),
  ADD KEY `idx_user_logout` (`user_id`,`logout_at`),
  ADD KEY `idx_logout_time` (`logout_at`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`noti_id`),
  ADD UNIQUE KEY `ux_notification_reserve_noti` (`reserve_id`,`noti_type`),
  ADD KEY `idx_user_read` (`user_id`,`is_read`),
  ADD KEY `idx_sent_at` (`sent_at`);

--
-- Indexes for table `payment`
--
ALTER TABLE `payment`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_status` (`payment_status`),
  ADD KEY `idx_reserve` (`reserve_id`);

--
-- Indexes for table `policy`
--
ALTER TABLE `policy`
  ADD PRIMARY KEY (`policy_id`),
  ADD UNIQUE KEY `restaurant_id` (`restaurant_id`);

--
-- Indexes for table `reservation`
--
ALTER TABLE `reservation`
  ADD PRIMARY KEY (`reserve_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_restaurant` (`restaurant_id`),
  ADD KEY `idx_date` (`reserve_date`),
  ADD KEY `idx_status` (`reserve_status`),
  ADD KEY `idx_user_status` (`user_id`,`reserve_status`);

--
-- Indexes for table `reservation_tables`
--
ALTER TABLE `reservation_tables`
  ADD PRIMARY KEY (`reserve_id`,`table_id`),
  ADD KEY `idx_table` (`table_id`);

--
-- Indexes for table `restaurant`
--
ALTER TABLE `restaurant`
  ADD PRIMARY KEY (`restaurant_id`),
  ADD KEY `idx_status` (`rest_status`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_restaurant` (`restaurant_id`);

--
-- Indexes for table `table`
--
ALTER TABLE `table`
  ADD PRIMARY KEY (`table_id`),
  ADD UNIQUE KEY `unique_table_in_area` (`area_id`,`table_number`),
  ADD KEY `idx_status` (`table_status`),
  ADD KEY `idx_area_status` (`area_id`,`table_status`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `user_phone` (`user_phone`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `area`
--
ALTER TABLE `area`
  MODIFY `area_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `checkin`
--
ALTER TABLE `checkin`
  MODIFY `checkin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `login_history`
--
ALTER TABLE `login_history`
  MODIFY `login_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `logout_history`
--
ALTER TABLE `logout_history`
  MODIFY `logout_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `noti_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=91;

--
-- AUTO_INCREMENT for table `payment`
--
ALTER TABLE `payment`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `policy`
--
ALTER TABLE `policy`
  MODIFY `policy_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reservation`
--
ALTER TABLE `reservation`
  MODIFY `reserve_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `restaurant`
--
ALTER TABLE `restaurant`
  MODIFY `restaurant_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `table`
--
ALTER TABLE `table`
  MODIFY `table_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=49;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin`
--
ALTER TABLE `admin`
  ADD CONSTRAINT `admin_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`);

--
-- Constraints for table `area`
--
ALTER TABLE `area`
  ADD CONSTRAINT `area_ibfk_1` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`restaurant_id`) ON DELETE CASCADE;

--
-- Constraints for table `checkin`
--
ALTER TABLE `checkin`
  ADD CONSTRAINT `checkin_ibfk_1` FOREIGN KEY (`reserve_id`) REFERENCES `reservation` (`reserve_id`),
  ADD CONSTRAINT `checkin_ibfk_2` FOREIGN KEY (`table_id`) REFERENCES `table` (`table_id`),
  ADD CONSTRAINT `checkin_ibfk_3` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`);

--
-- Constraints for table `login_history`
--
ALTER TABLE `login_history`
  ADD CONSTRAINT `login_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `logout_history`
--
ALTER TABLE `logout_history`
  ADD CONSTRAINT `logout_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `notification_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `notification_ibfk_2` FOREIGN KEY (`reserve_id`) REFERENCES `reservation` (`reserve_id`) ON DELETE CASCADE;

--
-- Constraints for table `payment`
--
ALTER TABLE `payment`
  ADD CONSTRAINT `payment_ibfk_1` FOREIGN KEY (`reserve_id`) REFERENCES `reservation` (`reserve_id`) ON DELETE CASCADE;

--
-- Constraints for table `policy`
--
ALTER TABLE `policy`
  ADD CONSTRAINT `policy_ibfk_1` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`restaurant_id`) ON DELETE CASCADE;

--
-- Constraints for table `reservation`
--
ALTER TABLE `reservation`
  ADD CONSTRAINT `reservation_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `reservation_ibfk_2` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`restaurant_id`);

--
-- Constraints for table `reservation_tables`
--
ALTER TABLE `reservation_tables`
  ADD CONSTRAINT `reservation_tables_ibfk_1` FOREIGN KEY (`reserve_id`) REFERENCES `reservation` (`reserve_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservation_tables_ibfk_2` FOREIGN KEY (`table_id`) REFERENCES `table` (`table_id`);

--
-- Constraints for table `staff`
--
ALTER TABLE `staff`
  ADD CONSTRAINT `staff_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `staff_ibfk_2` FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`restaurant_id`);

--
-- Constraints for table `table`
--
ALTER TABLE `table`
  ADD CONSTRAINT `table_ibfk_1` FOREIGN KEY (`area_id`) REFERENCES `area` (`area_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
