-- ============================================================================
-- STAYLEDGER ADBMS - MODULE: ROOM ALLOCATIONS, TRANSFERS & CHECKOUT
-- Member: N.L. Lekamge (36934)
-- Contributions: Transactions + Row Locking, Overlap Trigger, Occupancy View
-- ============================================================================

CREATE DATABASE IF NOT EXISTS stayledger_db;
USE stayledger_db;

-- 1. Floors Table
CREATE TABLE IF NOT EXISTS floors (
    floor_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    floor_name VARCHAR(50) NOT NULL UNIQUE
);

-- 2. Rooms Table
CREATE TABLE IF NOT EXISTS rooms (
    room_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    floor_id INT UNSIGNED NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 3,
    status ENUM('AVAILABLE', 'MAINTENANCE') NOT NULL DEFAULT 'AVAILABLE',
    CONSTRAINT fk_room_floor FOREIGN KEY (floor_id) REFERENCES floors(floor_id),
    CONSTRAINT chk_room_capacity CHECK (capacity > 0 AND capacity <= 6)
);

-- 3. Residents Table
CREATE TABLE IF NOT EXISTS residents (
    resident_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Room Allocations Table (Core Module Table)
CREATE TABLE IF NOT EXISTS room_allocations (
    allocation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allocation_code VARCHAR(20) NOT NULL UNIQUE,
    resident_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('Active', 'Transferred', 'Checked Out') NOT NULL DEFAULT 'Active',
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_alloc_resident FOREIGN KEY (resident_id) REFERENCES residents(resident_id) ON DELETE RESTRICT,
    CONSTRAINT fk_alloc_room FOREIGN KEY (room_id) REFERENCES rooms(room_id) ON DELETE RESTRICT,
    CONSTRAINT chk_valid_dates CHECK (end_date IS NULL OR end_date >= start_date),

    INDEX idx_room_active (room_id, status),
    INDEX idx_resident_overlap (resident_id, status, start_date, end_date)
);

-- 5. Trigger: Prevent Overlapping Allocations (වැළැක්වීම) & Capacity Overflow
DELIMITER $$

DROP TRIGGER IF EXISTS trg_before_allocation_insert$$
CREATE TRIGGER trg_before_allocation_insert
BEFORE INSERT ON room_allocations
FOR EACH ROW
BEGIN
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
END$$

DELIMITER ;

-- 6. Live Occupancy View (vw_room_occupancy)
CREATE OR REPLACE VIEW vw_room_occupancy AS
SELECT 
    rm.room_id,
    rm.room_number,
    fl.floor_id,
    fl.floor_name,
    rm.capacity,
    COUNT(ra.allocation_id) AS occupied_beds,
    (rm.capacity - COUNT(ra.allocation_id)) AS available_beds,
    CASE 
        WHEN COUNT(ra.allocation_id) >= rm.capacity THEN 'Full'
        WHEN COUNT(ra.allocation_id) > 0 THEN 'Partial'
        ELSE 'Available'
    END AS occupancy_status
FROM rooms rm
INNER JOIN floors fl ON rm.floor_id = fl.floor_id
LEFT JOIN room_allocations ra 
    ON rm.room_id = ra.room_id AND ra.status = 'Active'
GROUP BY rm.room_id, rm.room_number, fl.floor_id, fl.floor_name, rm.capacity
ORDER BY rm.room_number ASC;

-- 7. Seed Initial Database Records
INSERT IGNORE INTO floors (floor_id, floor_name) VALUES
(1, 'First floor'),
(2, 'Second floor');

INSERT IGNORE INTO rooms (room_id, room_number, floor_id, capacity) VALUES
(1, '101', 1, 3),
(2, '102', 1, 3),
(3, '103', 1, 3),
(4, '104', 1, 3),
(5, '201', 2, 3),
(6, '202', 2, 3),
(7, '204', 2, 3),
(8, '208', 2, 3);

INSERT IGNORE INTO residents (resident_id, resident_code, full_name, email) VALUES
(1, 'RES-001', 'Amaya Perera', 'amaya@example.com'),
(2, 'RES-002', 'Dinithi Fernando', 'dinithi@example.com'),
(3, 'RES-003', 'Nethmi Silva', 'nethmi@example.com'),
(4, 'RES-004', 'Kavindi Jayasinghe', 'kavindi@example.com'),
(5, 'RES-005', 'Tharushi Bandara', 'tharushi@example.com'),
(6, 'RES-006', 'Hiruni Wijesinghe', 'hiruni@example.com'),
(7, 'RES-007', 'Sanduni Rajapaksa', 'sanduni@example.com'),
(8, 'RES-008', 'Piumi Gunasekara', 'piumi@example.com'),
(9, 'RES-009', 'Chathuri Senanayake', 'chathuri@example.com'),
(10, 'RES-010', 'Malsha Wickramasinghe', 'malsha@example.com');

INSERT IGNORE INTO room_allocations (allocation_id, allocation_code, resident_id, room_id, start_date, end_date, status, remarks) VALUES
(101, 'ALLOC-101', 1, 1, '2026-11-02', NULL, 'Active', 'Initial semester intake'),
(102, 'ALLOC-102', 5, 1, '2026-11-03', NULL, 'Active', 'Assigned near study hall'),
(103, 'ALLOC-103', 6, 1, '2026-11-04', NULL, 'Active', 'Room 101 now at full capacity (3/3)'),
(104, 'ALLOC-104', 2, 7, '2026-11-04', NULL, 'Active', 'Second floor quiet wing'),
(105, 'ALLOC-105', 3, 2, '2026-10-01', '2026-11-05', 'Transferred', 'Transferred from Room 102 to 103'),
(106, 'ALLOC-106', 3, 3, '2026-11-05', NULL, 'Active', 'Transferred from Room 102'),
(107, 'ALLOC-107', 4, 6, '2026-11-08', NULL, 'Active', 'Standard bed allocation'),
(108, 'ALLOC-108', 7, 8, '2026-09-15', '2026-11-01', 'Checked Out', 'Completed internship stay');