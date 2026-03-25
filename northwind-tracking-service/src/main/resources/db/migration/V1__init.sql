-- V1__init.sql
-- Initial schema for the Northwind Tracking Service (Postgres).
--
-- Migration notes:
--   The legacy MySQL schema had a single flat `tracking_events` table with no
--   indexes, written by PHP via file_get_contents() + a broken lock file.
--   This schema separates concerns:
--     - tracking_events  : immutable audit log of every carrier status update
--     - active_shipments : mutable set of shipments currently being polled
--     - outbox_events    : transactional outbox for reliable Kafka publishing

CREATE TABLE tracking_events (
    id             BIGSERIAL    PRIMARY KEY,
    tracking_number VARCHAR(50)  NOT NULL,
    carrier         VARCHAR(10)  NOT NULL,
    status          VARCHAR(30)  NOT NULL,
    location        VARCHAR(200),
    order_id        VARCHAR(30),
    event_time      TIMESTAMP    NOT NULL DEFAULT NOW(),
    raw_response    TEXT,
    source          VARCHAR(10)  DEFAULT 'LIVE'   -- 'LIVE' | 'CACHE'
);

CREATE TABLE active_shipments (
    id              BIGSERIAL   PRIMARY KEY,
    order_id        VARCHAR(30) NOT NULL UNIQUE,
    tracking_number VARCHAR(50) NOT NULL,
    carrier         VARCHAR(10) NOT NULL,
    started_at      TIMESTAMP   DEFAULT NOW(),
    last_polled_at  TIMESTAMP,
    status          VARCHAR(30) DEFAULT 'IN_TRANSIT'
);

CREATE TABLE outbox_events (
    id            BIGSERIAL    PRIMARY KEY,
    topic         VARCHAR(100) NOT NULL,
    partition_key VARCHAR(100),
    payload       TEXT         NOT NULL,
    created_at    TIMESTAMP    DEFAULT NOW(),
    published_at  TIMESTAMP,
    published     BOOLEAN      DEFAULT FALSE
);

-- Partial index: only unpublished rows are scanned by OutboxProcessor.
-- This keeps the index tiny as published rows accumulate.
CREATE INDEX idx_outbox_unpublished ON outbox_events(published) WHERE published = FALSE;

CREATE INDEX idx_tracking_number   ON tracking_events(tracking_number);
CREATE INDEX idx_tracking_order_id ON tracking_events(order_id);
CREATE INDEX idx_active_order      ON active_shipments(order_id);
