-- ============================================================================
-- STAYLEDGER ADBMS - MODULE: ROOM ALLOCATIONS, TRANSFERS & CHECKOUT
-- Member: N.L. Lekamge (36934)
-- ============================================================================

CREATE DATABASE IF NOT EXISTS stayledger_db;
USE stayledger_db;

DROP TABLE IF EXISTS room_allocations;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS floors;
DROP TABLE IF EXISTS residents;

-- 1. Floors Table
CREATE TABLE floors (
    floor_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    floor_name VARCHAR(50) NOT NULL UNIQUE
);

-- 2. Rooms Table (Rooms 1 to 20)
CREATE TABLE rooms (
    room_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    floor_id INT UNSIGNED NOT NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 3,
    status ENUM('AVAILABLE', 'MAINTENANCE') NOT NULL DEFAULT 'AVAILABLE',
    CONSTRAINT fk_room_floor FOREIGN KEY (floor_id) REFERENCES floors(floor_id),
    CONSTRAINT chk_room_capacity CHECK (capacity > 0 AND capacity <= 6)
);

-- 3. Residents Table (60 capacity tracking)
CREATE TABLE residents (
    resident_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,
    status ENUM('ACTIVE', 'INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Room Allocations Table
CREATE TABLE room_allocations (
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

-- 5. Trigger: Prevent Overlapping Allocations & Capacity Overflow
DELIMITER $$

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
    CAST(rm.room_number AS UNSIGNED) AS room_num_int,
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
ORDER BY room_num_int ASC;

-- 7. Seed Data: Floors (2), Rooms (1 to 20), Residents, Allocations
INSERT INTO floors (floor_id, floor_name) VALUES (1, 'First floor'), (2, 'Second floor');

-- First Floor Rooms (1 to 10)
INSERT INTO rooms (room_number, floor_id, capacity) VALUES 
('1', 1, 3), ('2', 1, 3), ('3', 1, 3), ('4', 1, 3), ('5', 1, 3),
('6', 1, 3), ('7', 1, 3), ('8', 1, 3), ('9', 1, 3), ('10', 1, 3);

-- Second Floor Rooms (11 to 20)
INSERT INTO rooms (room_number, floor_id, capacity) VALUES 
('11', 2, 3), ('12', 2, 3), ('13', 2, 3), ('14', 2, 3), ('15', 2, 3),
('16', 2, 3), ('17', 2, 3), ('18', 2, 3), ('19', 2, 3), ('20', 2, 3);

INSERT INTO residents (resident_code, full_name, email) VALUES
('RES-001', 'Amaya Perera', 'amaya@example.com'),
('RES-002', 'Dinithi Fernando', 'dinithi@example.com'),
('RES-003', 'Nethmi Silva', 'nethmi@example.com'),
('RES-004', 'Kavindi Jayasinghe', 'kavindi@example.com'),
('RES-005', 'Tharushi Bandara', 'tharushi@example.com'),
('RES-006', 'Hiruni Wijesinghe', 'hiruni@example.com'),
('RES-007', 'Sanduni Rajapaksa', 'sanduni@example.com'),
('RES-008', 'Piumi Gunasekara', 'piumi@example.com'),
('RES-009', 'Chathuri Senanayake', 'chathuri@example.com'),
('RES-010', 'Malsha Wickramasinghe', 'malsha@example.com');

INSERT INTO room_allocations (allocation_code, resident_id, room_id, start_date, end_date, status, remarks) VALUES
('ALLOC-101', 1, 1, '2026-11-02', NULL, 'Active', 'Initial semester intake'),
('ALLOC-102', 5, 1, '2026-11-03', NULL, 'Active', 'Assigned near study hall'),
('ALLOC-103', 6, 2, '2026-11-04', NULL, 'Active', 'Standard bed allocation'),
('ALLOC-104', 2, 11, '2026-11-04', NULL, 'Active', 'Second floor quiet wing'),
('ALLOC-105', 3, 3, '2026-10-01', '2026-11-05', 'Transferred', 'Transferred to Room 4'),
('ALLOC-106', 3, 4, '2026-11-05', NULL, 'Active', 'Transferred from Room 3'),
('ALLOC-107', 4, 15, '2026-11-08', NULL, 'Active', 'Standard bed allocation'),
('ALLOC-108', 7, 18, '2026-09-15', '2026-11-01', 'Checked Out', 'Completed internship stay');