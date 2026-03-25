-- northwind_schema.sql - Northwind Logistics Database Schema
-- Created: 2008-03-15 by Dave
-- Version: as of 2013-11-01
-- MySQL 5.x compatible
-- NOTE: some indexes are missing. Full table scans occur on common queries.
--       Adding them was always "on the list" but never prioritized. - Dave 2013
-- TODO: add proper indexes to orders, invoices tables (2010, 2011, 2012, 2013 - not done)
-- TODO: fix the collation (latin1 everywhere should be utf8 but migration is scary)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- -----------------------------------------------
-- Drop tables if exist (for clean reinstall)
-- WARNING: this drops all data. Don't run on production. Obviously.
-- Someone ran it on staging in 2012 and deleted 6 months of test data.
-- -----------------------------------------------
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `tracking_events`;
DROP TABLE IF EXISTS `freight_rates`;
DROP TABLE IF EXISTS `invoices`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `carriers`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `users`;
DROP VIEW  IF EXISTS `v_order_summary`;

-- -----------------------------------------------
-- USERS TABLE
-- Password stored as MD5 (no salt) - "it was 2008" - Dave
-- TODO: switch to bcrypt (2011 - security audit requirement, still MD5 in 2013)
-- -----------------------------------------------
CREATE TABLE `users` (
    `id`           INT(11) NOT NULL AUTO_INCREMENT,
    `username`     VARCHAR(50) NOT NULL,
    `password`     VARCHAR(32) NOT NULL COMMENT 'MD5 hash, no salt. Yes we know. TODO.',
    `email`        VARCHAR(100) DEFAULT NULL,
    `first_name`   VARCHAR(50) DEFAULT NULL,
    `last_name`    VARCHAR(50) DEFAULT NULL,
    `role`         ENUM('admin','manager','dispatcher','warehouse','billing','readonly') NOT NULL DEFAULT 'readonly',
    `active`       TINYINT(1) NOT NULL DEFAULT 1,
    `last_login`   DATETIME DEFAULT NULL,
    `login_count`  INT(11) NOT NULL DEFAULT 0,
    `created_at`   DATETIME NOT NULL,
    `updated_at`   DATETIME NOT NULL,
    `legacy_id`    INT(11) DEFAULT NULL COMMENT 'DO NOT DROP - referenced by old import scripts from 2009',
    PRIMARY KEY (`id`),
    UNIQUE KEY `username` (`username`)
    -- NOTE: no index on email. Lookups by email do full table scans.
    -- There are only 20 users so "it's fine" - Dave 2010
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='User accounts. Passwords are MD5 - known issue.';

-- -----------------------------------------------
-- CUSTOMERS TABLE
-- -----------------------------------------------
CREATE TABLE `customers` (
    `id`             INT(11) NOT NULL AUTO_INCREMENT,
    `account_number` VARCHAR(20) NOT NULL COMMENT 'Format: NW-CUST-NNNNN',
    `company_name`   VARCHAR(150) NOT NULL,
    `contact_name`   VARCHAR(100) DEFAULT NULL,
    `email`          VARCHAR(100) DEFAULT NULL,
    `phone`          VARCHAR(25) DEFAULT NULL,
    `fax`            VARCHAR(25) DEFAULT NULL COMMENT 'Nobody uses this since 2011 but we keep the column',
    `addr1`          VARCHAR(100) DEFAULT NULL,
    `addr2`          VARCHAR(100) DEFAULT NULL,
    `city`           VARCHAR(75) DEFAULT NULL,
    `state`          VARCHAR(2) DEFAULT NULL,
    `zip`            VARCHAR(10) DEFAULT NULL,
    `country`        VARCHAR(3) NOT NULL DEFAULT 'US',
    `payment_terms`  INT(3) NOT NULL DEFAULT 30 COMMENT 'Days: 0=COD, 15, 30, 60',
    `credit_limit`   DECIMAL(10,2) NOT NULL DEFAULT '5000.00',
    `notes`          TEXT DEFAULT NULL,
    `active`         TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     DATETIME NOT NULL,
    `updated_at`     DATETIME NOT NULL,
    `legacy_id`      INT(11) DEFAULT NULL COMMENT 'DO NOT DROP - from the 2009 data migration from ACT! CRM',
    PRIMARY KEY (`id`),
    UNIQUE KEY `account_number` (`account_number`),
    KEY `company_name` (`company_name`)
    -- Missing: index on zip, state (used in freight zone lookups)
    -- Missing: index on email (used in search)
    -- TODO: add these (2011 - not done)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- CARRIERS TABLE
-- -----------------------------------------------
CREATE TABLE `carriers` (
    `id`          INT(11) NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `code`        VARCHAR(30) NOT NULL COMMENT 'Internal code e.g. UPS_GROUND, FEDEX_EXPRESS',
    `tracking_url`VARCHAR(255) DEFAULT NULL COMMENT 'Base URL for tracking links',
    `account_num` VARCHAR(50) DEFAULT NULL COMMENT 'Our account number with this carrier - plaintext in DB, also in source code',
    `active`      TINYINT(1) NOT NULL DEFAULT 1,
    `is_ltl`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'True if LTL carrier',
    `created_at`  DATETIME NOT NULL,
    `updated_at`  DATETIME NOT NULL,
    `legacy_id`   INT(11) DEFAULT NULL COMMENT 'DO NOT DROP',
    PRIMARY KEY (`id`),
    KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- PRODUCTS TABLE
-- -----------------------------------------------
CREATE TABLE `products` (
    `id`          INT(11) NOT NULL AUTO_INCREMENT,
    `sku`         VARCHAR(50) NOT NULL,
    `name`        VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price`       DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `cost`        DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Our cost - not shown to customers',
    `weight_lbs`  DECIMAL(8,3) NOT NULL DEFAULT '0.000' COMMENT 'Weight per unit in pounds',
    `length_in`   DECIMAL(6,2) DEFAULT NULL COMMENT 'Length in inches',
    `width_in`    DECIMAL(6,2) DEFAULT NULL,
    `height_in`   DECIMAL(6,2) DEFAULT NULL,
    `active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL,
    `updated_at`  DATETIME NOT NULL,
    `legacy_id`   INT(11) DEFAULT NULL COMMENT 'DO NOT DROP - matches product IDs in the old FoxPro system from 2005',
    PRIMARY KEY (`id`),
    UNIQUE KEY `sku` (`sku`),
    KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- ORDERS TABLE
-- Status ENUM must match PHP constants in config.php
-- "DO NOT ADD NEW STATUSES without updating 47 places in the code" - Dave
-- -----------------------------------------------
CREATE TABLE `orders` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `order_number`    VARCHAR(20) NOT NULL COMMENT 'Format: NW-YYYY-NNNNNN',
    `customer_id`     INT(11) NOT NULL,
    `status`          ENUM('new','processing','shipped','delivered','cancelled','on_hold','disputed','archived')
                      NOT NULL DEFAULT 'new',
    `ship_to_name`    VARCHAR(150) DEFAULT NULL,
    `ship_to_addr1`   VARCHAR(100) DEFAULT NULL,
    `ship_to_addr2`   VARCHAR(100) DEFAULT NULL,
    `ship_to_city`    VARCHAR(75) DEFAULT NULL,
    `ship_to_state`   VARCHAR(2) DEFAULT NULL,
    `ship_to_zip`     VARCHAR(10) DEFAULT NULL,
    `carrier_id`      INT(11) DEFAULT NULL,
    `tracking_number` VARCHAR(100) DEFAULT NULL,
    `po_number`       VARCHAR(50) DEFAULT NULL COMMENT 'Customer purchase order number',
    `payment_terms`   INT(3) NOT NULL DEFAULT 30,
    `subtotal`        DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `freight_amount`  DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `discount_code`   VARCHAR(20) DEFAULT NULL,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `tax_amount`      DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `total_amount`    DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `notes`           TEXT DEFAULT NULL,
    `shipped_at`      DATETIME DEFAULT NULL,
    `delivered_at`    DATETIME DEFAULT NULL,
    `archived_at`     DATETIME DEFAULT NULL,
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    `legacy_id`       INT(11) DEFAULT NULL COMMENT 'DO NOT DROP - maps to orders in the old system',
    PRIMARY KEY (`id`),
    UNIQUE KEY `order_number` (`order_number`),
    KEY `customer_id` (`customer_id`),
    KEY `status` (`status`)
    -- Missing: index on created_at (date range queries in reports do full scans)
    -- Missing: index on tracking_number (tracking lookups are slow)
    -- Missing: index on carrier_id
    -- TODO: add composite index on (status, created_at) (2011 - not done)
    -- The reports query runs for 30+ seconds on 10k orders because of missing indexes
    -- "We'll add indexes when we have time" - said every year since 2010
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- ORDER ITEMS TABLE
-- -----------------------------------------------
CREATE TABLE `order_items` (
    `id`          INT(11) NOT NULL AUTO_INCREMENT,
    `order_id`    INT(11) NOT NULL,
    `product_id`  INT(11) NOT NULL,
    `quantity`    INT(11) NOT NULL DEFAULT 1,
    `unit_price`  DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `line_total`  DECIMAL(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Denormalized: quantity * unit_price',
    `deleted`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag',
    `created_at`  DATETIME NOT NULL,
    `updated_at`  DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `order_id` (`order_id`),
    KEY `product_id` (`product_id`)
    -- No foreign key constraints - were removed in 2010 after a cascade delete
    -- deleted 200 order items by accident. "Removed FKs to be safe." - Bob 2010
    -- TODO: add back FKs with proper ON DELETE behavior (2010 - not done)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- INVOICES TABLE
-- -----------------------------------------------
CREATE TABLE `invoices` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `invoice_number`  VARCHAR(20) NOT NULL COMMENT 'Format: NW-YYYY-NNNNNN',
    `order_id`        INT(11) NOT NULL,
    `customer_id`     INT(11) NOT NULL,
    `subtotal`        DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `freight_amount`  DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `tax_amount`      DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `total_amount`    DECIMAL(10,2) NOT NULL DEFAULT '0.00',
    `status`          ENUM('pending','paid','overdue','voided') NOT NULL DEFAULT 'pending',
    `due_date`        DATE NOT NULL,
    `payment_terms`   INT(3) NOT NULL DEFAULT 30,
    `payment_ref`     VARCHAR(100) DEFAULT NULL COMMENT 'Check number, wire ref, etc.',
    `paid_at`         DATETIME DEFAULT NULL,
    `void_reason`     VARCHAR(255) DEFAULT NULL COMMENT 'Never displayed anywhere - audit requirement added 2011',
    `voided_at`       DATETIME DEFAULT NULL,
    `last_emailed`    DATETIME DEFAULT NULL,
    `notes`           TEXT DEFAULT NULL,
    `created_at`      DATETIME NOT NULL,
    `updated_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `invoice_number` (`invoice_number`),
    KEY `order_id` (`order_id`),
    KEY `customer_id` (`customer_id`),
    KEY `status` (`status`)
    -- Missing: index on due_date (overdue invoice queries are slow)
    -- TODO: add index on due_date (2012 - not done)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- FREIGHT RATES TABLE
-- Rate override table - when a customer has negotiated special rates
-- This table exists but is barely used - most rates are hardcoded in PHP
-- "We'll move rates to the DB eventually" - Dave 2011
-- -----------------------------------------------
CREATE TABLE `freight_rates` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `carrier_id`      INT(11) NOT NULL,
    `customer_id`     INT(11) DEFAULT NULL COMMENT 'NULL = applies to all customers',
    `zone`            INT(2) DEFAULT NULL COMMENT 'NULL = applies to all zones',
    `weight_min`      DECIMAL(8,3) NOT NULL DEFAULT '0.000',
    `weight_max`      DECIMAL(8,3) NOT NULL DEFAULT '99999.000',
    `rate`            DECIMAL(10,4) NOT NULL COMMENT 'Per shipment rate at this weight/zone',
    `rate_type`       ENUM('flat','per_lb','per_cwt','percent') NOT NULL DEFAULT 'flat',
    `fuel_surcharge_override` DECIMAL(5,2) DEFAULT NULL COMMENT 'NULL = use default from config',
    `effective_date`  DATE NOT NULL,
    `expiry_date`     DATE DEFAULT NULL,
    `notes`           VARCHAR(255) DEFAULT NULL,
    `created_at`      DATETIME NOT NULL,
    `legacy_id`       INT(11) DEFAULT NULL COMMENT 'DO NOT DROP',
    PRIMARY KEY (`id`),
    KEY `carrier_id` (`carrier_id`),
    KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Rate overrides. Most rates are still hardcoded in PHP. This table is mostly empty.';

-- -----------------------------------------------
-- TRACKING EVENTS TABLE
-- Written by TrackingService, never really read by any UI
-- Exists "for historical record" - Dave 2012
-- -----------------------------------------------
CREATE TABLE `tracking_events` (
    `id`              INT(11) NOT NULL AUTO_INCREMENT,
    `order_id`        INT(11) NOT NULL,
    `tracking_number` VARCHAR(100) NOT NULL,
    `event_date`      DATETIME NOT NULL,
    `description`     VARCHAR(255) DEFAULT NULL,
    `location`        VARCHAR(150) DEFAULT NULL,
    `carrier_id`      INT(11) DEFAULT NULL,
    `raw_data`        TEXT DEFAULT NULL COMMENT 'Raw XML/JSON from carrier (for debugging)',
    `created_at`      DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `order_id` (`order_id`),
    KEY `tracking_number` (`tracking_number`)
    -- Missing: unique key to prevent duplicates (was going to add it in 2012, didn't)
    -- This table has many duplicate entries
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- -----------------------------------------------
-- AUDIT LOG TABLE
-- Only written to, never read by any UI
-- "For compliance" - Dave 2010
-- The auditors asked for it. We built it. They never asked for reports from it.
-- TODO: build a UI to view audit logs (2010, 2011, 2012, 2013 - never done)
-- -----------------------------------------------
CREATE TABLE `audit_log` (
    `id`          INT(11) NOT NULL AUTO_INCREMENT,
    `user_id`     INT(11) NOT NULL DEFAULT 0 COMMENT '0 = system/anonymous',
    `action`      VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL COMMENT 'e.g. order, invoice, customer, user',
    `entity_id`   INT(11) NOT NULL DEFAULT 0,
    `details`     TEXT DEFAULT NULL,
    `ip_address`  VARCHAR(45) DEFAULT NULL COMMENT 'IPv4 or IPv6',
    `user_agent`  VARCHAR(255) DEFAULT NULL COMMENT 'Not currently captured - TODO',
    `created_at`  DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `entity_type_id` (`entity_type`, `entity_id`),
    KEY `user_id` (`user_id`),
    KEY `created_at` (`created_at`)
    -- This table will grow forever. No purge process exists.
    -- As of 2013 it has ~500k rows and is 80MB. - Dave 2013
    -- TODO: add a purge cron job for old audit records (2012 - not done)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COMMENT='Audit log - only written to, never read from in any UI. Classic compliance theater.';

-- -----------------------------------------------
-- VIEW: v_order_summary
-- NOTE: there is a bug in the LEFT JOIN below
-- The v_order_summary view joins invoices on order_id but when an order has
-- multiple voided invoices (possible in certain race conditions), the SUM()
-- counts them all, showing inflated invoice amounts.
-- Discovered in 2012 Q4, never fixed because "it's edge-case"
-- The view is used by nothing in production but was built for a report that never shipped
-- -----------------------------------------------
CREATE VIEW `v_order_summary` AS
    SELECT
        o.id AS order_id,
        o.order_number,
        o.status,
        o.created_at,
        o.total_amount AS order_total,
        c.company_name,
        c.account_number,
        cr.name AS carrier_name,
        -- BUG: this SUM includes voided invoices, should filter by status != 'voided'
        -- Also uses LEFT JOIN so orders without invoices still show up (correct)
        -- but the SUM becomes NULL instead of 0.00 for no-invoice orders (confusing)
        SUM(i.total_amount) AS total_invoiced,
        -- BUG: if order has 2 invoices (one voided), COUNT(*) returns 2 not 1
        COUNT(i.id) AS invoice_count,
        -- Derived status that's often wrong for disputed/on_hold orders
        CASE
            WHEN o.status = 'delivered' AND i.status = 'paid' THEN 'closed'
            WHEN o.status = 'cancelled' THEN 'cancelled'
            WHEN i.status = 'overdue' THEN 'overdue'  -- this never matches because we don't update overdue status via cron
            ELSE 'open'
        END AS summary_status
    FROM orders o
    LEFT JOIN customers c   ON o.customer_id = c.id
    LEFT JOIN carriers cr   ON o.carrier_id  = cr.id
    LEFT JOIN invoices i    ON o.id = i.order_id  -- BUG: should be AND i.status != 'voided'
    GROUP BY o.id, o.order_number, o.status, o.created_at, o.total_amount,
             c.company_name, c.account_number, cr.name, i.status;

-- -----------------------------------------------
-- SAMPLE DATA - 5 customers
-- -----------------------------------------------

INSERT INTO `carriers` (`id`, `name`, `code`, `tracking_url`, `account_num`, `active`, `is_ltl`, `created_at`, `updated_at`) VALUES
(1,  'FedEx Ground',           'FEDEX_GROUND',  'https://www.fedex.com/apps/fedextrack/?tracknumbers=', '560847291',   1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00'),
(2,  'FedEx Express Saver',    'FEDEX_ESAVER',  'https://www.fedex.com/apps/fedextrack/?tracknumbers=', '560847291',   1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00'),
(3,  'UPS Ground',             'UPS_GROUND',    'https://www.ups.com/track?tracknum=',                 '7E2A48',       1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00'),
(4,  'UPS 2nd Day Air',        'UPS_2DA',       'https://www.ups.com/track?tracknum=',                 '7E2A48',       1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00'),
(5,  'UPS Next Day Air',       'UPS_1DA',       'https://www.ups.com/track?tracknum=',                 '7E2A48',       1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00'),
(6,  'USPS Priority Mail',     'USPS_PRIORITY', 'https://tools.usps.com/go/TrackConfirmAction?tLabels=','',            1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00'),
(7,  'USPS Parcel Select',     'USPS_PARCEL',   'https://tools.usps.com/go/TrackConfirmAction?tLabels=','',            1, 0, '2009-06-01 09:00:00', '2013-08-14 14:30:00'),
(8,  'DHL Express',            'DHL_EXPRESS',   'https://www.dhl.com/en/express/tracking.html?AWB=',  '',             1, 0, '2010-01-15 09:00:00', '2013-08-14 14:30:00'),
(10, 'R+L Carriers (LTL)',     'RLCARRIERS',    '',                                                     'RLC-88271-NW',1, 1, '2011-03-01 09:00:00', '2013-08-14 14:30:00'),
(11, 'Old Dominion (LTL)',     'ODFL',          '',                                                     'OD-NW-449211',1, 1, '2012-01-10 09:00:00', '2013-08-14 14:30:00'),
(99, 'Will Call / Pickup',     'WILLCALL',      '',                                                     '',             1, 0, '2008-03-15 09:00:00', '2013-08-14 14:30:00');

INSERT INTO `users` (`id`, `username`, `password`, `email`, `first_name`, `last_name`, `role`, `active`, `created_at`, `updated_at`) VALUES
-- Password is MD5 of 'admin123' - DO NOT USE IN PRODUCTION (it's already in production)
(1, 'admin',    '0192023a7bbd73250516f069df18b500', 'admin@northwind-logistics.com',   'Admin',  'User',    'admin',      1, '2008-03-15 09:00:00', '2013-11-01 10:00:00'),
-- Password is MD5 of 'dave2008'
(2, 'dave',     'aec2afba6db5e18ea1bec5d4e95fc9e7', 'dave@northwind-logistics.com',    'Dave',   'Williams','manager',    1, '2008-03-15 09:00:00', '2013-10-15 08:30:00'),
-- Password is MD5 of 'bobpass'
(3, 'bob',      '3d61d6e56ae1c7e52bb47c29a853b33a', 'bob@northwind-logistics.com',     'Bob',    'Chen',    'dispatcher', 1, '2008-05-01 09:00:00', '2013-11-01 09:15:00'),
-- Password is MD5 of 'karen123'
(4, 'karen',    '79f5ebc1917ed7f23ba1be0c12cc41a7', 'karen@northwind-logistics.com',   'Karen',  'Smith',   'billing',    1, '2009-02-15 09:00:00', '2013-09-30 16:45:00'),
-- Password is MD5 of 'warehouse'
(5, 'warehouse','a0dab3fc7d0d3bdec71bd7d4e23bbc2f', 'warehouse@northwind-logistics.com','Warehouse','Staff','warehouse',  1, '2010-01-01 09:00:00', '2013-11-01 07:00:00');

INSERT INTO `customers` (`id`, `account_number`, `company_name`, `contact_name`, `email`, `phone`,
                          `addr1`, `city`, `state`, `zip`, `payment_terms`, `credit_limit`, `active`,
                          `created_at`, `updated_at`, `legacy_id`) VALUES
(1, 'NW-CUST-00001', 'Acme Industrial Supply Co.',   'Tom Bradley',     'tom.bradley@acmeindustrial.com',   '(312) 555-0142',
    '4400 W. Grand Ave',           'Chicago',     'IL', '60639', 30, 25000.00, 1, '2008-04-01 10:00:00', '2013-10-15 14:30:00', 1001),
(2, 'NW-CUST-00002', 'Midwest Manufacturing LLC',    'Sandra Kowalski', 'skowalski@midwestmfg.com',         '(419) 555-0287',
    '1800 Libbey Rd',              'Toledo',      'OH', '43612', 30, 50000.00, 1, '2008-06-15 09:00:00', '2013-11-01 09:00:00', 1002),
(3, 'NW-CUST-00003', 'Gulf Coast Distributors Inc.', 'Ray Fontenot',    'rfontenot@gulfcoastdist.com',       '(504) 555-0391',
    '2200 Magazine Street Ste 450','New Orleans',  'LA', '70130', 60, 75000.00, 1, '2009-01-20 11:00:00', '2013-09-22 10:15:00', 1003),
(4, 'NW-CUST-00004', 'Northeast Hardware Group',     'Patricia Hennessy','phennessy@nehardware.com',         '(617) 555-0524',
    '380 Harrison Ave',            'Boston',      'MA', '02118', 15,  10000.00, 1, '2009-08-01 14:00:00', '2013-10-30 16:00:00', 1004),
(5, 'NW-CUST-00005', 'Pacific Rim Imports Ltd.',     'James Nakamura',  'jnakamura@pacrimports.com',         '(206) 555-0673',
    '1411 4th Ave Suite 1000',     'Seattle',     'WA', '98101', 30,  30000.00, 1, '2010-03-15 09:00:00', '2013-10-01 11:30:00', 1005);

INSERT INTO `products` (`id`, `sku`, `name`, `price`, `cost`, `weight_lbs`, `length_in`, `width_in`, `height_in`,
                         `active`, `created_at`, `updated_at`, `legacy_id`) VALUES
(1,  'HDWB-1001', 'Heavy Duty Workbench 72"',         799.99, 450.00, 95.000, 72.0, 30.0, 36.0, 1, '2008-04-01 09:00:00', '2013-08-01 09:00:00', 501),
(2,  'WDCK-2050', 'Wooden Deck Board 8ft (Box of 20)',189.50,  95.00,  65.000, 96.0, 12.0,  8.0, 1, '2008-04-01 09:00:00', '2013-08-01 09:00:00', 502),
(3,  'TSTK-3010', 'Industrial Tool Storage Cabinet',  549.00, 280.00, 120.000, 48.0, 18.0, 72.0, 1, '2008-04-01 09:00:00', '2013-08-01 09:00:00', 503),
(4,  'PPFT-4025', 'PVC Pipe Fitting 2" Assortment',    45.99,  18.50,   8.500, 18.0, 12.0,  8.0, 1, '2008-06-01 09:00:00', '2013-08-01 09:00:00', 504),
(5,  'ELWI-5001', 'Electrical Wire 12/2 NM-B 250ft',  125.00,  65.00,  22.000, 16.0, 16.0, 12.0, 1, '2008-06-01 09:00:00', '2013-08-01 09:00:00', 505),
(6,  'HDLT-6030', 'Heavy Duty Load Strap Set (4-pack)',  34.99,  14.00,   3.500, 14.0,  8.0,  4.0, 1, '2009-01-15 09:00:00', '2013-08-01 09:00:00', 506),
(7,  'PLSH-7040', 'Plastic Shelving Unit 5-Tier',      89.99,  40.00,  25.000, 36.0, 18.0 ,72.0, 1, '2009-01-15 09:00:00', '2013-08-01 09:00:00', 507),
(8,  'STRD-8001', 'Steel Rod Stock 1/2" x 36" (Pkg 10)',74.50,  35.00,  35.000, 38.0,  6.0,  6.0, 1, '2009-06-01 09:00:00', '2013-08-01 09:00:00', 508),
(9,  'SFTW-9010', 'Safety Work Gloves Large (Dozen)',   22.99,   9.50,   1.800, 10.0,  8.0,  5.0, 1, '2010-01-01 09:00:00', '2013-08-01 09:00:00', 509),
(10, 'BGNT-0050', 'Bolt and Nut Assortment Kit',        56.99,  22.00,  12.000, 12.0, 10.0,  8.0, 1, '2010-01-01 09:00:00', '2013-08-01 09:00:00', 510);

INSERT INTO `orders` (`id`, `order_number`, `customer_id`, `status`,
                       `ship_to_name`, `ship_to_addr1`, `ship_to_city`, `ship_to_state`, `ship_to_zip`,
                       `carrier_id`, `payment_terms`, `subtotal`, `freight_amount`, `discount_amount`,
                       `tax_amount`, `total_amount`, `created_at`, `updated_at`) VALUES
(1, 'NW-2013-000001', 1, 'delivered',
    'Acme Industrial Supply Co.', '4400 W. Grand Ave', 'Chicago', 'IL', '60639',
    3, 30, 799.99, 42.50, 0.00, 0.00, 842.49,
    '2013-09-05 10:30:00', '2013-09-12 14:00:00'),
(2, 'NW-2013-000002', 2, 'shipped',
    'Midwest Manufacturing LLC', '1800 Libbey Rd', 'Toledo', 'OH', '43612',
    3, 30, 624.50, 38.75, 0.00, 0.00, 663.25,
    '2013-10-01 09:15:00', '2013-10-07 16:30:00'),
(3, 'NW-2013-000003', 3, 'processing',
    'Gulf Coast Distributors Inc.', '2200 Magazine Street Ste 450', 'New Orleans', 'LA', '70130',
    1, 60, 1249.98, 87.50, 62.50, 0.00, 1274.98,
    '2013-10-15 14:00:00', '2013-10-16 09:00:00'),
(4, 'NW-2013-000004', 4, 'new',
    'Northeast Hardware Group', '380 Harrison Ave', 'Boston', 'MA', '02118',
    2, 15, 315.98, 45.00, 0.00, 0.00, 360.98,
    '2013-11-01 11:30:00', '2013-11-01 11:30:00'),
(5, 'NW-2013-000005', 5, 'on_hold',
    'Pacific Rim Imports Ltd.', '1411 4th Ave Suite 1000', 'Seattle', 'WA', '98101',
    5, 30, 2099.97, 125.00, 0.00, 0.00, 2224.97,
    '2013-10-22 08:45:00', '2013-10-25 15:00:00');

INSERT INTO `order_items` (`order_id`, `product_id`, `quantity`, `unit_price`, `line_total`, `deleted`, `created_at`) VALUES
-- Order 1: Acme - workbench
(1, 1, 1, 799.99, 799.99, 0, '2013-09-05 10:30:00'),
-- Order 2: Midwest - pipe fittings + wire
(2, 4, 5,  45.99, 229.95, 0, '2013-10-01 09:15:00'),
(2, 5, 3, 125.00, 375.00, 0, '2013-10-01 09:15:00'),
(2, 9, 1,  22.99,  22.99, 0, '2013-10-01 09:15:00'),
-- Order 2: item that was removed (soft-deleted)
(2, 6, 2,  34.99,  69.98, 1, '2013-10-01 09:20:00'),
-- Order 3: Gulf Coast - tool storage + workbench (discount applied)
(3, 3, 1, 549.00, 549.00, 0, '2013-10-15 14:00:00'),
(3, 1, 1, 799.99, 799.99, 0, '2013-10-15 14:00:00'),
-- Order 3: small items
(3, 6, 3,  34.99, 104.97, 0, '2013-10-15 14:10:00'),
-- Order 4: Northeast Hardware
(4, 7, 2,  89.99, 179.98, 0, '2013-11-01 11:30:00'),
(4, 10,2,  56.99, 113.98, 0, '2013-11-01 11:30:00'),
(4, 9, 1,  22.99,  22.99, 0, '2013-11-01 11:30:00'),
-- Order 5: Pacific Rim - big order
(5, 1, 1, 799.99, 799.99, 0, '2013-10-22 08:45:00'),
(5, 3, 2, 549.00,1098.00, 0, '2013-10-22 08:45:00'),
(5, 8, 3,  74.50, 223.50, 0, '2013-10-22 08:50:00');

INSERT INTO `invoices` (`id`, `invoice_number`, `order_id`, `customer_id`, `subtotal`, `freight_amount`,
                         `discount_amount`, `tax_amount`, `total_amount`, `status`, `due_date`,
                         `payment_terms`, `created_at`, `updated_at`) VALUES
(1, 'NW-2013-000001', 1, 1, 799.99, 42.50, 0.00, 0.00, 842.49, 'paid',    '2013-10-05', 30, '2013-09-05 10:45:00', '2013-10-08 16:00:00'),
(2, 'NW-2013-000002', 2, 2, 624.50, 38.75, 0.00, 0.00, 663.25, 'pending', '2013-11-01', 30, '2013-10-01 09:30:00', '2013-10-01 09:30:00'),
(3, 'NW-2013-000003', 3, 3,1249.98, 87.50,62.50, 0.00,1274.98, 'pending', '2013-12-15', 60, '2013-10-15 14:15:00', '2013-10-15 14:15:00');
-- Orders 4 and 5 don't have invoices yet

-- Add some tracking events for order 2
INSERT INTO `tracking_events` (`order_id`, `tracking_number`, `event_date`, `description`, `location`, `carrier_id`, `created_at`) VALUES
(2, '1Z7E2A480391462817', '2013-10-07 08:15:00', 'Package picked up',           'Philadelphia, PA', 3, '2013-10-07 08:30:00'),
(2, '1Z7E2A480391462817', '2013-10-07 23:45:00', 'Departed facility',           'Philadelphia, PA', 3, '2013-10-08 01:00:00'),
(2, '1Z7E2A480391462817', '2013-10-08 06:30:00', 'Arrived at UPS Facility',     'Pittsburgh, PA',   3, '2013-10-08 07:00:00'),
(2, '1Z7E2A480391462817', '2013-10-08 22:00:00', 'Departed facility',           'Pittsburgh, PA',   3, '2013-10-08 22:30:00'),
(2, '1Z7E2A480391462817', '2013-10-09 07:45:00', 'Out for delivery',            'Toledo, OH',       3, '2013-10-09 08:00:00');

-- Update order 2 with tracking number
UPDATE `orders` SET `tracking_number` = '1Z7E2A480391462817' WHERE `id` = 2;
