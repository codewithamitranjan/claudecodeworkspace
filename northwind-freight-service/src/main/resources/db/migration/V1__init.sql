-- ============================================================
-- V1 — Initial schema for Northwind Freight Rating Service
--
-- Extracted from the PHP monolith (northwind_db.freight_rates
-- and northwind_db.shipping_log) and normalised for the
-- stand-alone microservice.
--
-- NOTE: application code currently uses the hard-coded rate
-- tables in CarrierRates.java.  The freight_rates table is
-- reserved for a future story (NW-FREIGHT-55) that will allow
-- ops to update rates without a code deployment.
-- ============================================================

CREATE TABLE freight_rates (
    id             BIGSERIAL    PRIMARY KEY,
    carrier        VARCHAR(10)  NOT NULL,
    zone           INTEGER      NOT NULL,
    base_rate      DECIMAL(10,2) NOT NULL,
    effective_date DATE         NOT NULL DEFAULT CURRENT_DATE,

    CONSTRAINT uq_carrier_zone_date UNIQUE (carrier, zone, effective_date),
    CONSTRAINT chk_zone             CHECK  (zone BETWEEN 1 AND 8),
    CONSTRAINT chk_base_rate        CHECK  (base_rate > 0)
);

COMMENT ON TABLE  freight_rates              IS 'Carrier base rates per zone — future dynamic rate store (NW-FREIGHT-55)';
COMMENT ON COLUMN freight_rates.carrier        IS 'Carrier code: FEDEX | UPS | USPS | DHL';
COMMENT ON COLUMN freight_rates.zone           IS 'Shipping zone 1-8 per FreightCalc zone algorithm';
COMMENT ON COLUMN freight_rates.base_rate      IS 'Base rate in USD before weight multiplier and fuel surcharge';
COMMENT ON COLUMN freight_rates.effective_date IS 'Date from which this rate is active';

-- --------------------------------------------------------
-- Seed the initial rates from carrier_rates.php so that
-- the table reflects the current hard-coded values.
-- --------------------------------------------------------
INSERT INTO freight_rates (carrier, zone, base_rate) VALUES
    ('FEDEX', 1,  5.99), ('FEDEX', 2,  8.49), ('FEDEX', 3, 11.99), ('FEDEX', 4, 15.49),
    ('FEDEX', 5, 19.99), ('FEDEX', 6, 24.99), ('FEDEX', 7, 31.99), ('FEDEX', 8, 39.99),

    ('UPS',   1,  5.49), ('UPS',   2,  7.99), ('UPS',   3, 11.49), ('UPS',   4, 14.99),
    ('UPS',   5, 19.49), ('UPS',   6, 23.99), ('UPS',   7, 30.99), ('UPS',   8, 38.99),

    ('USPS',  1,  4.99), ('USPS',  2,  6.99), ('USPS',  3,  9.99), ('USPS',  4, 12.99),
    ('USPS',  5, 16.99), ('USPS',  6, 20.99), ('USPS',  7, 26.99), ('USPS',  8, 33.99),

    ('DHL',   1,  6.49), ('DHL',   2,  9.49), ('DHL',   3, 13.49), ('DHL',   4, 17.49),
    ('DHL',   5, 22.49), ('DHL',   6, 27.99), ('DHL',   7, 35.49), ('DHL',   8, 44.99);


-- --------------------------------------------------------
-- Audit / analytics table that records every rate lookup.
-- The application writes a row here after each successful
-- POST /api/freight/rate call (async, best-effort).
-- --------------------------------------------------------
CREATE TABLE rate_calculations (
    id           BIGSERIAL     PRIMARY KEY,
    origin_zip   VARCHAR(5),
    dest_zip     VARCHAR(5),
    carrier      VARCHAR(10),
    weight_lbs   DECIMAL(10,2),
    zone         INTEGER,
    total_rate   DECIMAL(10,2),
    calculated_at TIMESTAMP    DEFAULT NOW(),

    CONSTRAINT chk_calc_zone  CHECK (zone IS NULL OR zone BETWEEN 1 AND 8),
    CONSTRAINT chk_weight_pos CHECK (weight_lbs IS NULL OR weight_lbs > 0)
);

COMMENT ON TABLE  rate_calculations              IS 'Immutable audit log of every freight rate calculation';
COMMENT ON COLUMN rate_calculations.origin_zip    IS '5-digit origin ZIP code';
COMMENT ON COLUMN rate_calculations.dest_zip      IS '5-digit destination ZIP code';
COMMENT ON COLUMN rate_calculations.carrier       IS 'Carrier code used for this calculation';
COMMENT ON COLUMN rate_calculations.weight_lbs    IS 'Shipment weight in pounds';
COMMENT ON COLUMN rate_calculations.zone          IS 'Computed shipping zone (1-8)';
COMMENT ON COLUMN rate_calculations.total_rate    IS 'Final rate including fuel surcharge, in USD';
COMMENT ON COLUMN rate_calculations.calculated_at IS 'UTC timestamp of the rate calculation';

CREATE INDEX idx_rate_calc_carrier  ON rate_calculations (carrier);
CREATE INDEX idx_rate_calc_calc_at  ON rate_calculations (calculated_at DESC);
