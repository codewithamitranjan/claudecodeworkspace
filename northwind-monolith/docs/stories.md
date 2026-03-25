# Northwind Logistics — User Stories

> Hackathon context: these stories describe the three business capabilities extracted from the
> PHP 5 monolith (`OrderManager.php`, `FreightCalc.php`, `TrackingService.php`) and define
> the acceptance bar for the new Spring Boot microservices.

---

## Story 1 — Order Creation

As a **dispatcher**, I want to create a new freight order with a customer and one or more line
items, so that the warehouse can begin picking and the customer receives a confirmed order
number immediately.

### Acceptance Criteria

- **Given** a valid customer ID (e.g. `NW-CUST-00001`) and at least one product SKU with a
  positive integer quantity, **when** the dispatcher submits the Create Order form,
  **then** the system saves the order with `status = new`, generates an order number in the
  format `NW-YYYY-NNNNNN` (e.g. `NW-2026-000042`), and returns HTTP 201 with the new order
  ID in the response body.

- **Given** a `ship_to_zip` of `19103` (Philadelphia) and a destination `ship_to_zip` of
  `10001` (New York), **when** the order is created, **then** the `ship_to_zip` field on the
  saved order record exactly matches the value submitted and is a 5-digit US ZIP code string.

- **Given** an order with three line items each at quantity 2 and unit prices $10.00, $25.00,
  and $40.00, **when** the order is saved, **then** the `subtotal` field equals `$150.00` and
  the `total_amount` equals `subtotal + freight_amount - discount_amount`.

- **Given** a dispatcher submits an order with `customer_id` referencing a customer whose
  `active` flag is `0` (deactivated), **when** the create request is processed,
  **then** the system rejects the request with HTTP 422 and an error message that contains
  the word "inactive" and does not create any record in the `orders` table.

- **Given** a dispatcher submits an order with no line items, **when** the create request is
  processed, **then** the system returns HTTP 422 with an error message indicating at least
  one item is required, and no order record is created.

- **Given** a dispatcher submits an order with a valid discount code `LOYAL5`, **when** the
  order is saved, **then** `discount_amount` equals 5% of `subtotal`, the `discount_code`
  column stores the literal string `LOYAL5`, and `total_amount` reflects the deducted amount.

- **Given** a dispatcher submits an order with `ship_to_zip` set to an empty string or a
  non-numeric value such as `ABCDE`, **when** the create request is processed, **then** the
  system returns HTTP 422 with a field-level validation error identifying `ship_to_zip` as
  invalid, and no order record is created.

- **Given** a newly created order in `status = new`, **when** the dispatcher views the order
  detail screen, **then** the system displays each line item's product name, SKU, quantity,
  unit price, and line total, and the displayed subtotal matches the sum of all line totals.

---

## Story 2 — Freight Rating

As a **dispatcher**, I want to request a rate quote for an order before confirming a carrier,
so that I can choose the most cost-effective shipping option and communicate an accurate
freight charge to the customer before the shipment is booked.

### Acceptance Criteria

- **Given** an order with total shipment weight of 15 lbs, origin ZIP `19103`
  (Philadelphia, PA), destination ZIP `90210` (Beverly Hills, CA), and carrier `UPS Ground`
  (carrier ID 3), **when** the dispatcher requests a rate quote, **then** the response
  contains a `rate` value greater than $0.00, a `zone` integer between 2 and 8 inclusive,
  and the carrier name `UPS Ground`.

- **Given** an order with total shipment weight of 0 lbs (all products have `weight_lbs = 0`),
  **when** a rate is requested, **then** the system applies the minimum freight charge of
  `$8.50` (the `MIN_FREIGHT_CHARGE` constant) and does not return an error or a zero-dollar
  rate.

- **Given** an order with total shipment weight of 75 lbs and carrier `USPS Priority Mail`
  (carrier ID 6), which has a maximum supported weight of 70 lbs, **when** a rate is
  requested, **then** the system falls back to `UPS Ground` (carrier ID 3) and the response
  includes a `fallback_carrier` field indicating the substitution occurred.

- **Given** a rate has been calculated and cached for order ID 42 with carrier `FedEx Ground`
  (carrier ID 1), **when** the dispatcher requests a rate for the same order ID with carrier
  `UPS Next Day Air` (carrier ID 5), **then** the system returns a fresh calculation for the
  new carrier and does not return the cached FedEx rate.

- **Given** an invalid `carrier_id` not present in the `carriers` table (e.g. carrier ID
  9999), **when** a rate is requested, **then** the system returns HTTP 422 with an error
  message identifying the carrier as unknown, and no rate value is returned.

- **Given** a valid shipment where origin ZIP is `19103` and destination ZIP is `19103`
  (same prefix), **when** a rate is requested, **then** the response returns `zone = 2`
  (local zone), and the zone 2 base rate for the selected carrier is used in the calculation.

- **Given** a rate is calculated with an 8.5% fuel surcharge applied (as hardcoded in
  `FreightCalc.php`), **when** the rate quote is returned, **then** the response includes
  a `fuel_surcharge_pct` field equal to `8.5` and a `fuel_surcharge_amount` field equal to
  `base_rate * 0.085` rounded to two decimal places.

- **Given** a dispatcher requests quotes for the same order against all four active carriers
  (FedEx, UPS, USPS, DHL), **when** all four responses are received, **then** each response
  contains a distinct `carrier` name, a `rate` in USD, a `zone` integer, and an
  `estimated_transit_days` integer greater than or equal to 1.

---

## Story 3 — Shipment Tracking

As a **customer service representative**, I want to look up the live status of an active
shipment by tracking number, so that I can give a customer an accurate, up-to-date answer
about where their freight is and when it will arrive, without leaving the internal
application to visit each carrier's website.

### Acceptance Criteria

- **Given** a shipment for order `NW-2026-000012` with a valid UPS tracking number
  (e.g. `1Z999AA10123456784`) and `status = shipped` in the `orders` table, **when** the
  customer service rep looks up the tracking number, **then** the system returns a response
  containing `tracking_number`, `carrier` (e.g. `UPS`), `status`, `last_event.description`,
  `last_event.location`, and `estimated_delivery` fields, all non-null.

- **Given** a tracking number that does not exist in the `orders` table and is not found by
  the carrier API, **when** a lookup is requested, **then** the system returns HTTP 404 with
  an error message containing "tracking number not found" and does not return a partially
  populated response object.

- **Given** a tracking number for a DHL shipment (carrier ID 8) whose carrier API call
  fails or times out, **when** the lookup is attempted, **then** the system returns a
  response with `status = "unknown"` and `status_desc` set to a human-readable message
  instructing the representative to check the carrier website directly; it does not return
  HTTP 500 or an unhandled exception.

- **Given** a FedEx tracking number (carrier IDs 1 or 2) that has already been fetched and
  written to the `tracking_events` table within the last 30 minutes, **when** a lookup is
  requested, **then** the system returns the cached event data without making a live carrier
  API call, and the response includes a `source` field with value `"cache"` and a
  `cached_at` timestamp.

- **Given** a tracking lookup for carrier USPS (carrier IDs 6 or 7) where the carrier API
  returns a `TrackSummary` element with `Event = "DELIVERED"`, **when** the response is
  processed, **then** the corresponding order's `status` in the `orders` table is
  automatically updated to `delivered` and `delivered_at` is set to the current UTC
  timestamp.

- **Given** a tracking number entered with mixed case (e.g. `1z999Aa10123456784`), **when**
  the lookup is submitted, **then** the system normalises the value to uppercase before
  querying and returns the same result as if the uppercase value had been submitted directly.

- **Given** a customer service rep submits an empty string or a tracking number shorter than
  8 characters, **when** the lookup is submitted, **then** the system returns HTTP 422 with
  a validation error indicating the tracking number format is invalid, and no carrier API
  call is made.

- **Given** an order in `status = new` or `status = processing` (not yet shipped), **when**
  a tracking number lookup is attempted for that order, **then** the system returns HTTP 409
  with a message stating the order has not yet shipped and no tracking number has been
  assigned, rather than forwarding an empty or null tracking number to the carrier API.
