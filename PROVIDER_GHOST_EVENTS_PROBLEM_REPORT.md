## Ghost-Event Identity Collisions: Provider-Facing Problem Report and Resolution Plan

### Executive summary
- We observe pervasive “ghost events”: multiple different UUIDs claiming the exact same event_type at the same millisecond event_timestamp. One real action cannot be performed by multiple people at the same instant.
- This is not ordinary duplication; it is identity collision at the moment of action. It inflates counts, breaks attribution, and compromises compliance.
- Across two independent datasets (multi-site and VettaFi month), 46–69% of millisecond event groups show multiple UUIDs; some collisions include up to 15 UUIDs for the same millisecond.
- Fixes must occur at collection and pipeline layers: generate a canonical event_id at the collector, enforce idempotent writes, bind identity before enrichment/replay, and stop normalization that reassigns identity.

## What the problem is (ghost events, not duplicates)
- Definition: Ghost events are collisions where different UUIDs claim the same event_type at the exact millisecond event_timestamp (often same IP). This violates the one-actor-per-event principle.
- Distinct from duplicates:
  - Duplicates: repeated sends of the same row/identity.
  - Ghost events: multiple identities per atomic event, created by labeling/idempotency failures or replay/enrichment artifacts.
- “More fields in a row” does not imply correctness. Row verbosity reflects enrichment, not ground truth identity.

## Evidence from two independent datasets (count = 2)
- Dataset A — Multi-site pixel (32 domains, 15 export files)
  - Raw events: 372,117; deduplicated events: 174,521.
  - Ghost-event signal: 58% of deduplicated rows exhibit ghost patterns.
  - Sites: 32 total; 16 contain financial professional (NPN/CRD) events.
  - NPN/CRD: 4,111 matches pre-selection (1.1% of raw); 2,931 retained post per-site selection (71.3% retention).
  - Competition among financial professionals is rare: only 20 events had multiple FinPros competing at the same millisecond.
- Dataset B — VettaFi month (deduped CSV-safe baseline)
  - Events analyzed: 1,511,607 unique rows.
  - Triplet view (event_type | event_timestamp[ms] | IP):
    - Ghost rows: 68.93%; Safe rows (exactly one UUID): 31.07%.
    - Distinct triplet groups: 359,827; multi-UUID triplets: 166,442 (46.26%).
  - Pair view (event_type | event_timestamp[ms]):
    - Additional rows beyond one per millisecond event: 876,208.
    - Distinct pairs: 359,139; multi-UUID pairs: 166,422 (46.34%).
  - Max UUIDs observed for a single millisecond event: 15.
  - Unique UUIDs: 44,259; high-confidence UUIDs (never ambiguous by pair): 13,406 (30.29%).

## Why this is not batching
- Batching affects when events arrive, not who acted at the action time. Multiple UUIDs claiming the same millisecond event cannot be caused by delivery lag.
- The canonical identity anchor is event_timestamp at millisecond precision (the actual moment of action), not logging/delivery times or session windows.

## Definitions you can rely on
- Pair group: (event_type, event_timestamp[ms]) — the atomic identity of a real-world event.
- Triplet group: (event_type, event_timestamp[ms], IP) — diagnostic lens for network noise; not a selection key.
- Competition: all rows within the same pair group. Only one can be admitted.

## Business and compliance impact
- Analytics and attribution: Journey mapping and conversion credit are corrupted when multiple identities claim one action.
- Billing and fairness: Inflated event counts bill clients for actions that did not happen or are counted multiple times.
- Financial services compliance: Weakens regulator-grade identity (NPN/CRD) and auditability.
- Trust and operability: Non-deterministic, non-idempotent pipelines are not supportable at scale.

## Root-cause hypotheses (provider-side)
- Identity labeling assigned post-collection or inconsistently across collectors.
- Enrichment/replay steps generating new identities for the same underlying event.
- Missing canonical event_id and lack of idempotent writes.
- Timestamp normalization that collapses distinct events or re-anchors identity incorrectly.
- Cross-collector race conditions that assign different UUIDs to the same action.

## What must change (collection and pipeline hardening)
- Canonical event_id at collection:
  - event_id = hash(collector_id, event_type, event_timestamp_ms, normalized_url)
- Idempotent writes:
  - Enforce unique(event_id) in storage to prevent re-materialization.
- Bind identity before enrichment:
  - Apply UUID selection and event_id write once; enrichment must not change identity or event_id.
- Preserve millisecond event_timestamp as identity anchor:
  - Never swap to logging/delivery timestamps for identity.
- Stop normalization that changes who acted:
  - Any process that can reassign UUIDs must be disabled or relocated pre-identity.

Example (DDL and generation):

```sql
-- Storage uniqueness
ALTER TABLE events ADD UNIQUE KEY uq_event_id (event_id);
```

```python
# Canonical ID at collector
event_id = sha256(f"{collector_id}|{event_type}|{event_timestamp_ms}|{normalize_url(url)}")
```

## Selection model when collisions occur (one per event, provider-side)
- When multiple rows share the same (event_type, event_timestamp[ms]), admit exactly one.
- Use profile-relevant signals only; ignore row verbosity, majority rules, IP sameness, or pixel/source dominance.
- Signals (weights indicative):
  - Identity strength: NPN/CRD present (highest), deep-verified email, business_email ↔ company_domain alignment.
  - Professional relevance: role/department/seniority alignment to FS; FS industry/company alignment.
  - Consistency & uniqueness: unique regulator presence within group; cross-field coherence; non-FS plausibility penalty.
- Size-aware thresholds:
  - Larger collision groups require higher confidence margins between first and second candidates.
- Outcomes observed:
  - Dataset B recommended scenario admits 219,091 events (one per millisecond group) and 16,182 visitors with transparent confidence.
  - Dataset A preserves 71.3% of NPN/CRD events post per-site selection; FinPro-vs-FinPro competitions are rare (20 events).

## Per-site lens (multi-site reality)
- Use per-site selection key: site_domain + event_type + event_timestamp[ms] to preserve site attribution.
- Key per-site KPIs to compute and monitor:
  - ghost_group_rate = multi-UUID pair groups / all pair groups
  - avg_group_size; tail frequencies (size ≥ 3, ≥ 5, ≥ 10)
  - admitted_share = admitted_events / all pair groups
  - finpro_retention = admitted_finpro_events / raw_finpro_events
  - competition_finpro_rate = FinPro competitions / all competitions

## Validation queries you can run
- Pair-group ghost prevalence:

```sql
WITH pairs AS (
  SELECT event_type, event_timestamp_ms, COUNT(DISTINCT uuid) AS uuids
  FROM events
  GROUP BY event_type, event_timestamp_ms
)
SELECT
  COUNT(*) AS total_pairs,
  SUM(CASE WHEN uuids = 1 THEN 1 ELSE 0 END) AS safe_pairs,
  SUM(CASE WHEN uuids > 1 THEN 1 ELSE 0 END) AS multi_uuid_pairs,
  ROUND(SUM(CASE WHEN uuids > 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) AS multi_uuid_pair_rate_pct
FROM pairs;
```

- Rows beyond one per event (pair view):

```sql
WITH pairs AS (
  SELECT event_type, event_timestamp_ms, COUNT(*) AS rows_in_group
  FROM events
  GROUP BY event_type, event_timestamp_ms
)
SELECT SUM(GREATEST(rows_in_group - 1, 0)) AS rows_beyond_one
FROM pairs;
```

- FinPro competition rate:

```sql
WITH pairs AS (
  SELECT event_type, event_timestamp_ms,
         SUM(CASE WHEN npn IS NOT NULL OR crd IS NOT NULL THEN 1 ELSE 0 END) AS finpro_rows
  FROM events
  GROUP BY event_type, event_timestamp_ms
)
SELECT
  SUM(CASE WHEN finpro_rows > 1 THEN 1 ELSE 0 END) AS finpro_competition_pairs
FROM pairs;
```

## What we need from the provider
- Implement canonical event_id at collection time and enforce uniqueness downstream.
- Bind UUID identity once (pre-enrichment), and treat it as immutable for that event_id.
- Provide documentation of any enrichment or replay jobs that can re-emit the same event with new/altered identity.
- Ship a test month with:
  - One row per millisecond event (post internal selection), all original columns, confidence score, group_size, margin_to_second.
  - Metrics: multi-UUID pair rate, rows_beyond_one, finpro retention, competition rates.
- Share raw collector logs (sample) for audit to confirm event_id and timestamp handling.
- SLA commitments:
  - multi-UUID pair rate < 5%
  - rows_beyond_one per month < 1% of total rows
  - zero reassignments of UUID for the same event_id
  - full auditability of selection policy and code path

## Why this is necessary
- Accuracy: One actor per atomic event restores attribution and reliable analytics.
- Fair billing: Removes volume inflation from identity collisions.
- Compliance: Preserves regulator-grade identity (NPN/CRD) necessary for financial services.
- Operability: Deterministic, idempotent, auditable pipeline that scales.

Bottom line: The primary quality issue is identity collision at the millisecond event level. The fix is to anchor identity at collection with a canonical event_id, enforce idempotency, and choose exactly one UUID per atomic event using profile-relevant signals—not row verbosity or operational artifacts. This is required to deliver a trustworthy, compliant, and fairly billable event feed.


