-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 11, 2026 at 10:45 PM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.4.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `stayledger_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `floors`
--

CREATE TABLE `floors` (
  `floor_id` int(10) UNSIGNED NOT NULL,
  `floor_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `floors`
--

INSERT INTO `floors` (`floor_id`, `floor_name`) VALUES
(1, 'First floor'),
(2, 'Second floor');

-- --------------------------------------------------------

--
-- Table structure for table `residents`
--

CREATE TABLE `residents` (
  `resident_id` int(10) UNSIGNED NOT NULL,
  `resident_code` varchar(20) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `residents`
--

INSERT INTO `residents` (`resident_id`, `resident_code`, `full_name`, `email`, `phone`, `status`, `created_at`) VALUES
(1, 'RES-001', 'Amaya Perera', 'amaya@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(2, 'RES-002', 'Dinithi Fernando', 'dinithi@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(3, 'RES-003', 'Nethmi Silva', 'nethmi@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(4, 'RES-004', 'Kavindi Jayasinghe', 'kavindi@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(5, 'RES-005', 'Tharushi Bandara', 'tharushi@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(6, 'RES-006', 'Hiruni Wijesinghe', 'hiruni@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(7, 'RES-007', 'Sanduni Rajapaksa', 'sanduni@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(8, 'RES-008', 'Piumi Gunasekara', 'piumi@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(9, 'RES-009', 'Chathuri Senanayake', 'chathuri@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10'),
(10, 'RES-010', 'Malsha Wickramasinghe', 'malsha@example.com', NULL, 'ACTIVE', '2026-11-11 19:42:10');

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `room_id` int(10) UNSIGNED NOT NULL,
  `room_number` varchar(10) NOT NULL,
  `floor_id` int(10) UNSIGNED NOT NULL,
  `capacity` int(10) UNSIGNED NOT NULL DEFAULT 3,
  `status` enum('AVAILABLE','MAINTENANCE') NOT NULL DEFAULT 'AVAILABLE'
) ;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`room_id`, `room_number`, `floor_id`, `capacity`, `status`) VALUES
(1, '01', 1, 3, 'AVAILABLE'),
(2, '02', 1, 3, 'AVAILABLE'),
(3, '03', 1, 3, 'AVAILABLE'),
(4, '04', 1, 3, 'AVAILABLE'),
(5, '05', 1, 3, 'AVAILABLE'),
(6, '06', 1, 3, 'AVAILABLE'),
(7, '07', 1, 3, 'AVAILABLE'),
(8, '08', 1, 3, 'AVAILABLE'),
(9, '09', 1, 3, 'AVAILABLE'),
(10, '10', 1, 3, 'AVAILABLE'),
(11, '11', 2, 3, 'AVAILABLE'),
(12, '12', 2, 3, 'AVAILABLE'),
(13, '13', 2, 3, 'AVAILABLE'),
(14, '14', 2, 3, 'AVAILABLE'),
(15, '15', 2, 3, 'AVAILABLE'),
(16, '16', 2, 3, 'AVAILABLE'),
(17, '17', 2, 3, 'AVAILABLE'),
(18, '18', 2, 3, 'AVAILABLE'),
(19, '19', 2, 3, 'AVAILABLE'),
(20, '20', 2, 3, 'AVAILABLE');

-- --------------------------------------------------------

--
-- Table structure for table `room_allocations`
--

CREATE TABLE `room_allocations` (
  `allocation_id` int(10) UNSIGNED NOT NULL,
  `allocation_code` varchar(20) NOT NULL,
  `resident_id` int(10) UNSIGNED NOT NULL,
  `room_id` int(10) UNSIGNED NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('Active','Transferred','Checked Out') NOT NULL DEFAULT 'Active',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `room_allocations`
--

INSERT INTO `room_allocations` (`allocation_id`, `allocation_code`, `resident_id`, `room_id`, `start_date`, `end_date`, `status`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 'ALLOC-101', 1, 13, '2026-11-11', NULL, 'Active', 'Standard bed allocation', '2026-11-11 20:53:31', '2026-11-11 20:53:31');

--
-- Triggers `room_allocations`
--
DELIMITER $$
CREATE TRIGGER `trg_before_allocation_insert` BEFORE INSERT ON `room_allocations` FOR EACH ROW BEGIN
    DECLARE overlap_count INT DEFAULT 0;
    DECLARE current_occ INT DEFAULT 0;
    DECLARE max_cap INT DEFAULT 0;

    SELECT COUNT(*) INTO overlap_count
    FROM room_allocations
    WHERE resident_id = NEW.resident_id
      AND (
          (status = 'Active' AND NEW.status = 'Active')
          OR (
              NEW.start_date <= COALESCE(end_date, '9999-12-31')
              AND COALESCE(NEW.end_date, '9999-12-31') >= start_date
              AND status = 'Active'
          )
      );

    IF overlap_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'DB Trigger Error: Resident already has an active or overlapping room allocation.';
    END IF;

    IF NEW.status = 'Active' THEN
        SELECT capacity INTO max_cap FROM rooms WHERE room_id = NEW.room_id;
        SELECT COUNT(*) INTO current_occ FROM room_allocations WHERE room_id = NEW.room_id AND status = 'Active';

        IF current_occ >= max_cap THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'DB Trigger Error: Target room has reached maximum bed capacity.';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_room_occupancy`
-- (See below for the actual view)
--
CREATE TABLE `vw_room_occupancy` (
`room_id` int(10) unsigned
,`room_num_int` bigint(20) unsigned
,`room_number` varchar(10)
,`floor_id` int(10) unsigned
,`floor_name` varchar(50)
,`capacity` int(10) unsigned
,`occupied_beds` bigint(21)
,`available_beds` bigint(21) unsigned
,`occupancy_status` varchar(9)
);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `floors`
--
ALTER TABLE `floors`
  ADD PRIMARY KEY (`floor_id`),
  ADD UNIQUE KEY `floor_name` (`floor_name`);

--
-- Indexes for table `residents`
--
ALTER TABLE `residents`
  ADD PRIMARY KEY (`resident_id`),
  ADD UNIQUE KEY `resident_code` (`resident_code`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`room_id`),
  ADD UNIQUE KEY `room_number` (`room_number`),
  ADD KEY `fk_room_floor` (`floor_id`);

--
-- Indexes for table `room_allocations`
--
ALTER TABLE `room_allocations`
  ADD PRIMARY KEY (`allocation_id`),
  ADD UNIQUE KEY `allocation_code` (`allocation_code`),
  ADD KEY `idx_room_active` (`room_id`,`status`),
  ADD KEY `idx_resident_overlap` (`resident_id`,`status`,`start_date`,`end_date`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `floors`
--
ALTER TABLE `floors`
  MODIFY `floor_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `residents`
--
ALTER TABLE `residents`
  MODIFY `resident_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `room_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `room_allocations`
--
ALTER TABLE `room_allocations`
  MODIFY `allocation_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `vw_room_occupancy`
--
DROP TABLE IF EXISTS `vw_room_occupancy`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_room_occupancy`  AS SELECT `rm`.`room_id` AS `room_id`, cast(`rm`.`room_number` as unsigned) AS `room_num_int`, `rm`.`room_number` AS `room_number`, `fl`.`floor_id` AS `floor_id`, `fl`.`floor_name` AS `floor_name`, `rm`.`capacity` AS `capacity`, count(`ra`.`allocation_id`) AS `occupied_beds`, `rm`.`capacity`- count(`ra`.`allocation_id`) AS `available_beds`, CASE WHEN count(`ra`.`allocation_id`) >= `rm`.`capacity` THEN 'Full' WHEN count(`ra`.`allocation_id`) > 0 THEN 'Partial' ELSE 'Available' END AS `occupancy_status` FROM ((`rooms` `rm` join `floors` `fl` on(`rm`.`floor_id` = `fl`.`floor_id`)) left join `room_allocations` `ra` on(`rm`.`room_id` = `ra`.`room_id` and `ra`.`status` = 'Active')) GROUP BY `rm`.`room_id`, `rm`.`room_number`, `fl`.`floor_id`, `fl`.`floor_name`, `rm`.`capacity` ORDER BY cast(`rm`.`room_number` as unsigned) ASC ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `rooms`
--
ALTER TABLE `rooms`
  ADD CONSTRAINT `fk_room_floor` FOREIGN KEY (`floor_id`) REFERENCES `floors` (`floor_id`);

--
-- Constraints for table `room_allocations`
--
ALTER TABLE `room_allocations`
  ADD CONSTRAINT `fk_alloc_resident` FOREIGN KEY (`resident_id`) REFERENCES `residents` (`resident_id`),
  ADD CONSTRAINT `fk_alloc_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`room_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
