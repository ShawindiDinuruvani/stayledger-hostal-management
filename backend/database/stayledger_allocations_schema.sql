-- ============================================================================
-- STAYLEDGER ADBMS - POSTGRESQL (NEON) SCHEMA
-- MODULE: ROOM ALLOCATIONS, TRANSFERS & CHECKOUT
-- Member: N.L. Lekamge (36934)
-- ============================================================================

DROP VIEW IF EXISTS vw_room_occupancy;
DROP TABLE IF EXISTS room_allocations CASCADE;
DROP TABLE IF EXISTS rooms CASCADE;
DROP TABLE IF EXISTS floors CASCADE;
DROP TABLE IF EXISTS residents CASCADE;

-- 1. Floors Table
CREATE TABLE floors (
    floor_id SERIAL PRIMARY KEY,
    floor_name VARCHAR(50) NOT NULL UNIQUE
);

-- 2. Rooms Table (Rooms 01 to 20)
CREATE TABLE rooms (
    room_id SERIAL PRIMARY KEY,
    room_number VARCHAR(10) NOT NULL UNIQUE,
    floor_id INT NOT NULL REFERENCES floors(floor_id) ON DELETE RESTRICT,
    capacity INT NOT NULL DEFAULT 3 CHECK (capacity > 0 AND capacity <= 6),
    status VARCHAR(20) NOT NULL DEFAULT 'AVAILABLE' CHECK (status IN ('AVAILABLE', 'MAINTENANCE'))
);

-- 3. Residents Table
CREATE TABLE residents (
    resident_id SERIAL PRIMARY KEY,
    resident_code VARCHAR(20) NOT NULL UNIQUE,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(20) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE' CHECK (status IN ('ACTIVE', 'INACTIVE')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Room Allocations Table
CREATE TABLE room_allocations (
    allocation_id SERIAL PRIMARY KEY,
    allocation_code VARCHAR(20) NOT NULL UNIQUE,
    resident_id INT NOT NULL REFERENCES residents(resident_id) ON DELETE RESTRICT,
    room_id INT NOT NULL REFERENCES rooms(room_id) ON DELETE RESTRICT,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active' CHECK (status IN ('Active', 'Transferred', 'Checked Out')),
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_valid_dates CHECK (end_date IS NULL OR end_date >= start_date)
);

CREATE INDEX idx_room_active ON room_allocations (room_id, status);
CREATE INDEX idx_resident_overlap ON room_allocations (resident_id, status, start_date, end_date);

-- 5. PostgreSQL Trigger Function: Prevent Overlapping Allocations & Capacity Overflow
CREATE OR REPLACE FUNCTION fn_before_allocation_insert()
RETURNS TRIGGER AS $$
DECLARE
    overlap_count INT := 0;
    current_occ INT := 0;
    max_cap INT := 0;
    rm_status VARCHAR(20);
BEGIN
    SELECT COUNT(*) INTO overlap_count
    FROM room_allocations
    WHERE resident_id = NEW.resident_id
      AND (
          (status = 'Active' AND NEW.status = 'Active')
          OR (
              NEW.start_date <= COALESCE(end_date, DATE '9999-12-31')
              AND COALESCE(NEW.end_date, DATE '9999-12-31') >= start_date
              AND status = 'Active'
          )
      );

    IF overlap_count > 0 THEN
        RAISE EXCEPTION 'DB Trigger Error: Resident already has an active or overlapping room allocation.';
    END IF;

    IF NEW.status = 'Active' THEN
        SELECT capacity, status INTO max_cap, rm_status FROM rooms WHERE room_id = NEW.room_id;

        IF rm_status = 'MAINTENANCE' THEN
            RAISE EXCEPTION 'DB Trigger Error: Target room is currently under maintenance.';
        END IF;

        SELECT COUNT(*) INTO current_occ FROM room_allocations WHERE room_id = NEW.room_id AND status = 'Active';

        IF current_occ >= max_cap THEN
            RAISE EXCEPTION 'DB Trigger Error: Target room has reached maximum bed capacity.';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_before_allocation_insert
BEFORE INSERT ON room_allocations
FOR EACH ROW
EXECUTE FUNCTION fn_before_allocation_insert();

-- 6. Live Occupancy View (vw_room_occupancy)
CREATE OR REPLACE VIEW vw_room_occupancy AS
SELECT 
    rm.room_id,
    CAST(rm.room_number AS INTEGER) AS room_num_int,
    rm.room_number,
    fl.floor_id,
    fl.floor_name,
    rm.capacity,
    COUNT(ra.allocation_id)::INT AS occupied_beds,
    (rm.capacity - COUNT(ra.allocation_id))::INT AS available_beds,
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
ORDER BY CAST(rm.room_number AS INTEGER) ASC;

-- 7. Structural Setup: 2 Floors & 20 Rooms (01 to 20)
INSERT INTO floors (floor_id, floor_name) VALUES (1, 'First floor'), (2, 'Second floor');
SELECT setval('floors_floor_id_seq', 2, true);

INSERT INTO rooms (room_number, floor_id, capacity) VALUES 
('01', 1, 3), ('02', 1, 3), ('03', 1, 3), ('04', 1, 3), ('05', 1, 3),
('06', 1, 3), ('07', 1, 3), ('08', 1, 3), ('09', 1, 3), ('10', 1, 3),
('11', 2, 3), ('12', 2, 3), ('13', 2, 3), ('14', 2, 3), ('15', 2, 3),
('16', 2, 3), ('17', 2, 3), ('18', 2, 3), ('19', 2, 3), ('20', 2, 3);

-- Optional: Initial Residents (Remove or edit as needed)
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