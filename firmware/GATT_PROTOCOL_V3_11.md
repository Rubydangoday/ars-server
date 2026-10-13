# V3.11 wire protocol and backend boundary

BLE Nordic UART Service (NUS):

| Characteristic | UUID | Direction |
|---|---|---|
| Service | 6e400001-b5a3-f393-e0a9-e50e24dcca9e | peripheral |
| RX | 6e400002-b5a3-f393-e0a9-e50e24dcca9e | laptop writes with ATT response |
| TX | 6e400003-b5a3-f393-e0a9-e50e24dcca9e | remote notifies after subscription |

Each stream record is `[length, payload...]`; length excludes itself. Payload
starts A5. Receivers support split/coalesced stream records. A malformed stream
makes the bridge reconnect. Single-remote records use at most 20 ATT bytes.

| Payload | Length | Purpose |
|---|---:|---|
| A5 20 room | 3 | HELLO every 2 s, only after Notify subscription |
| A5 21 room seat | 4 | HELLO reply; room mismatch disconnects bridge |
| A5 22 token[4] | 6 | Probe PING, never authorizes normal app transactions |
| A5 23 token[4] | 6 | Probe PONG |
| A5 type room seat q seq answer battLo battHi | 9 | Original uplink; type 1 LOGIN, 2 ANSWER |
| A5 14 room seat seq status | 6 | Exact LOGIN result |
| A5 13 room 01 seat q seq answer status | 9 | Exact single-seat ANSWER result |
| A5 10 room q open nOptions bitmap[13] | 19 | State, same format as V3.10 |
| A5 12 room mask[8] | 11 | Open question mask, same format as V3.10 |

Status 0 = accepted; others use existing REJECT_STATUS. ATTEND snapshots never
confirm LOGIN. Old ACKS from retained state never confirm ANSWER in GATT mode.

Bridge publishes the existing JSON packet on `ars/<room>/resp` with `via=ble`,
`transport=gatt` and a random 32-hex `gatt_request` correlation token. Retries of
the same packet within 10 s reuse this token; a new connection starts fresh.
Token is correlation, not authentication. Existing MAC/seat binding is kept.

Ingest uses its existing validation and autocommit DB writes. It emits a
non-retained JSON result to `ars/<room>/result/gatt` after a successful write,
a verified duplicate, or a rejection. The bridge matches token plus every
identity field before writing a result to the remote. Legacy advertising
packets omit the token and keep their old behavior.

Backend dedup remains the existing 10-second (question, MAC, seq, answer)
window. This is not a durable exactly-once protocol across arbitrary outages.
The firmware retries at most four times in roughly 4.5 s, and does not
replay an old answer after disconnect. If the DB committed and the connection
failed before confirmation, the outcome is unknown at the remote: inspect web.

Bridge marks the gateway online only after BLE HELLO/Notify and MQTT readiness.
HELLO checks communication, not database health. Missing backend responses
produce application timeouts. STATE is pushed on changes and replayed after
reconnect. Database/query validation remains authoritative for closed questions.
