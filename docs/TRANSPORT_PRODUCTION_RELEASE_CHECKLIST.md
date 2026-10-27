# Transport production release checklist

**Status: not approved for deployment.** This checklist prepares a controlled release; completing local tests does not authorize a production change.

## 1. Protect and isolate the working changes

- [ ] Record the current backend and frontend branch names, `git status`, and existing ahead/behind counts.
- [ ] Preserve all pre-existing work. In the current worktrees, separate the transport changes from finance/accounting changes, `.gitignore`/PHPUnit changes, the frontend library edit and its backup, and the existing local commit before creating a release.
- [ ] Review the final staged diff in both repositories. Stage only approved transport files and this checklist; do not use a blanket `git add .`.
- [ ] Run backend tests against the production database engine/version in an isolated test database, and run frontend typecheck/build. SQLite success is not a substitute for MySQL migration, foreign-key, timestamp, or locking verification.
- [ ] Have a second reviewer approve the migration, authorization, webhook authentication, rollback plan, and final diff.

## 2. Verify release prerequisites

- [ ] Confirm the intended St Peters vehicle using an authorized, read-only production lookup. Record its school, vehicle ID, registration, active status, and assigned route; do not infer identity from a display label.
- [ ] Set `TRANSPORT_DEMO_VEHICLE_ID` to that explicitly verified vehicle ID. Confirm the demo-target endpoint returns only that active, same-school, routed vehicle and fails closed when the setting is absent or invalid.
- [ ] Confirm production uses HTTPS for both the frontend and API. Browser geolocation requires a secure context.
- [ ] Confirm the operator account is an active school administrator and belongs to the vehicle's school. Confirm non-admin access is denied.
- [ ] Verify the exact deployed Traccar version and its supported forwarding feature. Prove that it can send the required POST body, ISO-8601 or epoch timestamp, and an HTTP bearer authorization header over TLS. Do not enable forwarding until an end-to-end request with a test device succeeds.
- [ ] Generate a unique high-entropy token, store it in the production secret manager, configure `TRACCAR_WEBHOOK_TOKEN` without exposing it in source, shell history, URLs, logs, or deployment output, and confirm the configured value is at least 32 characters.
- [ ] Confirm the Traccar device identifier is the assigned vehicle's unique IMEI, and confirm the vehicle is assigned to a route in the same school. Never use a real customer's device for pre-production tests.
- [ ] Agree the maintenance window, change owner, monitoring owner, operator, and rollback decision-maker.

## 3. Back up and establish rollback

- [ ] Take a fresh, encrypted database backup using the approved production backup process immediately before the change.
- [ ] Confirm backup completion, retention, encryption, and access controls. Verify that the latest backup is restorable using the established restore procedure and a non-production target.
- [ ] Record the application release identifier and current migration state; retain the previous known-good backend and frontend releases.
- [ ] Review rollback behavior before the window. Roll back application code independently if needed. The new table is additive; do not run the migration's `down` operation automatically. It drops demo-session data and should only be considered after the demo is stopped, data-retention implications are reviewed, and an authorized operator approves it.
- [ ] Do not restore a production database backup as a routine application rollback; use the approved recovery plan only if data recovery is necessary.

## 4. Controlled deployment sequence

- [ ] Announce the change and confirm backup/rollback gates are satisfied.
- [ ] Deploy the backend code and additive migration through the normal release pipeline. Apply the migration once; verify the new table and foreign keys on the production database.
- [ ] Configure the production token only after the receiver code is available. Confirm the value is not included in request logs or error output.
- [ ] Deploy the frontend build containing the demo indicator and controls. Confirm the live-map assets and API are from the same compatible release.
- [ ] Keep Traccar forwarding disabled until its exact version and bearer-header behavior are verified. Do not purchase or connect a physical FMC920 based solely on simulated tests.

## 5. Phone demo acceptance

- [ ] Confirm the St Peters vehicle record and route again immediately before starting.
- [ ] Sign in as the authorized school administrator on the GPS phone; load the live map over HTTPS and grant location permission.
- [ ] Start the session and verify the map shows the selected vehicle with the DEMO label. Verify a second authorized map viewer receives the temporary overlay.
- [ ] Confirm through approved read-only monitoring that `vehicles.last_lat/last_lng/last_speed`, vehicle telemetry history, trip locations, geofence events, and notification queues remain unchanged by demo fixes.
- [ ] Refresh the phone browser during an active session. Confirm the active demo is recovered and GPS updates resume.
- [ ] Stop the demo. Confirm the overlay disappears and the vehicle returns to its genuine stored position.
- [ ] In a separate controlled session, stop GPS updates or disconnect the phone. Confirm the overlay disappears within the 30-second lease plus the map refresh interval.
- [ ] Verify the 15-minute hard limit using a test session, and verify driver/other-school accounts cannot start, inspect, update, or stop the session.
- [ ] Stop all demo sessions after acceptance; record the operator, vehicle ID, start/stop times, and results. Do not leave a demo session active after the window.

## 6. Traccar acceptance and monitoring

- [ ] Before real device traffic, use a non-production test vehicle and isolated endpoint configuration to verify a valid report updates only its mapped vehicle and school.
- [ ] Exercise missing/invalid token, malformed coordinates, missing/invalid timestamp, future, stale, duplicate, out-of-order, `valid=false`, and unknown-IMEI cases. Confirm rejected/ignored reports do not create telemetry/trip history or geofence/off-route events.
- [ ] Verify a fresh accepted report updates the live map, preserves the intended units and UTC timestamp, writes at most one history sample per accepted fix, and triggers only expected operational behavior.
- [ ] Verify simultaneous/retried reports against MySQL; stale checks and persistence must serialize for a vehicle so an older fix cannot overwrite a newer one.
- [ ] Confirm rate-limit behavior and operational alerts for receiver unavailability, authentication failures, and repeated rejected reports without logging bearer tokens or sensitive coordinates unnecessarily.
- [ ] Keep physical FMC920, SIM/APN, device protocol, reporting interval, installation, and live-coordinate acceptance as outstanding until tested with the purchased hardware.

## 7. Closeout

- [ ] Record deployment identifiers, migration result, test output, approved vehicle mapping, and acceptance evidence.
- [ ] Confirm normal driver telemetry, live map, active trip history, geofences, and alert delivery still work with genuine GPS.
- [ ] Confirm no demo session remains active and Traccar forwarding is in the approved state.
- [ ] Keep this release marked **not physically verified** until FMC920 acceptance tests pass.
