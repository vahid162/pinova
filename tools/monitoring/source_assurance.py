"""Operational assurance for trusted, pinned native adapters; no I/O on import.

This module does not turn LogCursor's generic ``coverage_complete=False`` into
a producer guarantee. A private controller must first verify the actual writer,
rotation and native-adapter sources, pin their digests, and produce the native
witnesses described below. Protocol names are not authentication of a caller.
Unknown protocols, incomplete witnesses and real gaps cannot certify coverage.

File protocol: call cursor.observe(), then capture a stable native process/FD
scan and fstat the cursor's retained descriptors. Pass both to observe(). Keep
the descriptors open through finalize(); the controller closes them afterwards.
begin_window() follows the producer lookback; finalization follows the full
24-hour window, producer buffer, margin and retired-writer drain.

Event protocol: a reviewed native adapter reads live InnoDB allocation fences
and ALL id/level rows in fresh autocommit statements, with identity and session
fences before/after. The ledger observes stored sampled severity facts, never
all requests, failed logger writes, original context or original flow history.
"""
import copy
import re
import time


DAY_NS = 86400 * 1_000_000_000
SECOND_NS = 1_000_000_000
LEVELS = ("debug", "info", "notice", "warning", "error", "critical", "alert", "emergency")
FILE_PROTOCOLS = {
    "append_rename_unlink_v1": 0,
    "nginx_append_rename_unlink_5s_v1": 5,
    "site_php_runtime_append_v1": 0,
}
FILE_PINS = {"writer", "rotation_inventory", "configuration"}
DB_IDENTITY = {"server", "table", "definition"}


def _integer(value, minimum=0, maximum=2**64 - 1):
    return type(value) is int and minimum <= value <= maximum


def _digest(value):
    return isinstance(value, str) and re.fullmatch(r"[0-9a-f]{64}", value) is not None


def _digests(value, keys):
    return isinstance(value, dict) and set(value) == keys and all(_digest(x) for x in value.values())


def _pair(value):
    return isinstance(value, (list, tuple)) and len(value) == 2 and all(_integer(x, 1) for x in value)


class _Assurance:
    def __init__(self, adapter_sha256, max_gap_seconds, max_witness_age_seconds):
        if (not _digest(adapter_sha256) or not _integer(max_gap_seconds, 1, 660)
                or not _integer(max_witness_age_seconds, 1, 30)):
            raise ValueError("invalid_assurance_configuration")
        self.adapter_sha256 = adapter_sha256
        self.max_gap_ns = max_gap_seconds * SECOND_NS
        self.max_age_ns = max_witness_age_seconds * SECOND_NS
        self.gaps = set()
        self._last_sequence = None
        self._last_read_ns = None
        self._clock_origin = None
        self._final = None

    def _clock(self, witness, now_ns):
        start, end, wall, seq = (witness.get(k) for k in
                                 ("read_started_ns", "read_completed_ns", "wall_epoch_ns", "sequence"))
        if (not all(_integer(x) for x in (start, end, wall, seq, now_ns))
                or not start <= end <= now_ns or now_ns - start > self.max_age_ns
                or (self._last_sequence is not None and seq <= self._last_sequence)):
            raise ValueError("stale_or_invalid_native_snapshot")
        if self._last_read_ns is not None:
            if end < self._last_read_ns or end - self._last_read_ns > self.max_gap_ns:
                self.gaps.add("observation_gap")
        if self._clock_origin is None:
            self._clock_origin = end, wall
        elif abs((wall - self._clock_origin[1]) - (end - self._clock_origin[0])) > 5 * SECOND_NS:
            self.gaps.add("clock_discontinuity")
        self._last_sequence, self._last_read_ns = seq, end
        return end

    def fail(self, reason):
        """Native adapters use a fixed failure category, never raw exception text."""
        allowed = {"native_read_failed", "native_read_bound", "native_reconnect",
                   "native_identity_changed", "native_adapter_unavailable"}
        self.gaps.add(reason if reason in allowed else "native_adapter_unavailable")


class FileSourceAssurance(_Assurance):
    """Assurance within a verified append/rename/unlink writer contract.

    Native witness keys: protocol=native_file_fd_scan_v1, adapter_sha256,
    source_protocol, pins (FILE_PINS), producer_identity and boot_identity
    (digests), sequence/read timestamps/wall timestamp, current_identity,
    retained=[{identity:[dev,ino],position:int,size:int}], registry_before and
    registry_after (unique [pid,start_ticks] pairs), and writer_refs containing
    {identity:[dev,ino],process:[pid,start_ticks]}. The native adapter must scan
    the configured writer process set, not arbitrary selected processes.
    site_php_runtime_append_v1 additionally pins an independently verified
    native runtime-proof digest and its producer expiry; a readable expired
    sink is not an active source. The private source verifier owns that proof.
    """

    def __init__(self, *, source_protocol, pins, adapter_sha256, producer_identity,
                 boot_identity, max_gap_seconds=660, max_witness_age_seconds=5,
                 margin_seconds=1, max_generations=8, valid_until_epoch_ns=None):
        super().__init__(adapter_sha256, max_gap_seconds, max_witness_age_seconds)
        php_source = source_protocol == "site_php_runtime_append_v1"
        pin_keys = FILE_PINS | {"runtime_proof"} if php_source else FILE_PINS
        if (source_protocol not in FILE_PROTOCOLS or not _digests(pins, pin_keys)
                or not _digest(producer_identity) or not _digest(boot_identity)
                or not _integer(margin_seconds, 1, 60) or not _integer(max_generations, 1, 64)
                or (php_source and not _integer(valid_until_epoch_ns, 1))
                or (not php_source and valid_until_epoch_ns is not None)):
            raise ValueError("invalid_file_source_contract")
        self.source_protocol, self.pins = source_protocol, dict(pins)
        self.producer_identity, self.boot_identity = producer_identity, boot_identity
        self.grace_ns = (FILE_PROTOCOLS[source_protocol] + margin_seconds) * SECOND_NS
        self.max_generations = max_generations
        self.valid_until_epoch_ns = valid_until_epoch_ns
        self._baseline_ns = self._window_start_ns = None
        self._identities = set()
        self._reader = None
        self._native = None

    def observe(self, reader, witness, *, now_ns=None):
        if self._final is not None:
            return copy.deepcopy(self._final)
        now_ns = time.monotonic_ns() if now_ns is None else now_ns
        try:
            if not isinstance(reader, dict) or not isinstance(witness, dict):
                raise ValueError()
            if (witness.get("protocol") != "native_file_fd_scan_v1"
                    or witness.get("adapter_sha256") != self.adapter_sha256
                    or witness.get("source_protocol") != self.source_protocol
                    or witness.get("pins") != self.pins
                    or witness.get("producer_identity") != self.producer_identity
                    or witness.get("boot_identity") != self.boot_identity):
                raise ValueError()
            end = self._clock(witness, now_ns)
            if self.valid_until_epoch_ns is not None and witness["wall_epoch_ns"] >= self.valid_until_epoch_ns:
                self.gaps.add("producer_expired")
            retained, current = witness.get("retained"), witness.get("current_identity")
            before, after, refs = (witness.get(k) for k in ("registry_before", "registry_after", "writer_refs"))
            if (not isinstance(retained, list) or not 1 <= len(retained) <= self.max_generations
                    or not _pair(current) or not isinstance(before, list) or len(before) > 256
                    or not all(_pair(x) for x in before) or not isinstance(after, list)
                    or not all(_pair(x) for x in after)
                    or {tuple(x) for x in before} != {tuple(x) for x in after}
                    or len({tuple(x) for x in before}) != len(before)
                    or len({tuple(x) for x in after}) != len(after)
                    or not isinstance(refs, list) or len(refs) > 1024):
                raise ValueError()
            identities = set()
            for item in retained:
                if (not isinstance(item, dict) or set(item) != {"identity", "position", "size"}
                        or not _pair(item["identity"]) or not _integer(item["position"])
                        or not _integer(item["size"]) or item["position"] > item["size"]):
                    raise ValueError()
                identities.add(tuple(item["identity"]))
            if len(identities) != len(retained) or tuple(current) not in identities:
                raise ValueError()
            if not self._identities <= identities:
                self.gaps.add("retained_generation_lost")
            if (not isinstance(reader.get("detected_gaps"), list)
                    or reader.get("available") is not True
                    or reader.get("current_path_available") is not True
                    or reader.get("coverage_complete") is not False
                    or type(reader.get("pending_line")) is not bool
                    or type(reader.get("read_backlog")) is not bool
                    or type(reader.get("retained_files")) is not int
                    or reader.get("retained_files") != len(identities)):
                raise ValueError()
            if reader["detected_gaps"]:
                self.gaps.add("reader_detected_gap")
            if reader.get("read_backlog") is True:
                self.gaps.add("reader_read_bound")
            for ref in refs:
                if (not isinstance(ref, dict) or set(ref) != {"identity", "process"}
                        or not _pair(ref["identity"]) or not _pair(ref["process"])
                        or tuple(ref["identity"]) not in identities
                        or tuple(ref["process"]) not in {tuple(x) for x in before}):
                    raise ValueError()
            if len(self._identities | identities) > self.max_generations:
                self.gaps.add("generation_bound")
                return self.receipt()
            self._identities |= identities
            self._reader = {"pending_line": reader["pending_line"]}
            self._native = {key: copy.deepcopy(witness[key]) for key in
                            ("current_identity", "retained", "writer_refs")}
            if self._baseline_ns is None:
                self._baseline_ns = end
        except (ValueError, TypeError, KeyError):
            self.gaps.add("invalid_native_file_witness")
        return self.receipt()

    def begin_window(self, *, now_ns=None):
        now_ns = time.monotonic_ns() if now_ns is None else now_ns
        if (self._window_start_ns is not None or self._baseline_ns is None
                or not _integer(now_ns) or now_ns < self._baseline_ns + self.grace_ns
                or now_ns - self._last_read_ns > self.max_gap_ns):
            raise ValueError("file_window_not_ready")
        if (self.valid_until_epoch_ns is not None
                and self._clock_origin[1] + now_ns - self._clock_origin[0] + DAY_NS + self.grace_ns
                >= self.valid_until_epoch_ns):
            raise ValueError("file_window_exceeds_producer_lifetime")
        self._window_start_ns = now_ns

    def receipt(self):
        if self._final is not None:
            return copy.deepcopy(self._final)
        return {"scope": "verified_configured_file_source", "source_protocol": self.source_protocol,
                "coverage_complete": False, "result": "needs_review" if self.gaps else "observing",
                "reasons": sorted(self.gaps), "retained_generations": len(self._identities),
                "window_started": self._window_start_ns is not None,
                "counts_scope": "reader_observations_including_lookback_and_drain"}

    def finalize(self, reader, witness, *, now_ns=None):
        if self._final is not None:
            return copy.deepcopy(self._final)
        now_ns = time.monotonic_ns() if now_ns is None else now_ns
        self.observe(reader, witness, now_ns=now_ns)
        if (self._window_start_ns is None or self._last_read_ns is None
                or self._last_read_ns < self._window_start_ns + DAY_NS + self.grace_ns):
            self.gaps.add("window_or_producer_drain_short")
        if self._native is None or self._reader is None:
            self.gaps.add("missing_final_source_snapshot")
        else:
            current = tuple(self._native["current_identity"])
            if any(tuple(ref["identity"]) != current for ref in self._native["writer_refs"]):
                self.gaps.add("retired_writer_not_drained")
            if any(x["position"] != x["size"] for x in self._native["retained"]):
                self.gaps.add("final_read_backlog")
            if self._reader.get("pending_line") is not False:
                self.gaps.add("final_partial_line")
        self._final = self.receipt()
        self._final.update(coverage_complete=not self.gaps, result="passed" if not self.gaps else "needs_review")
        return copy.deepcopy(self._final)


class EventContinuityLedger(_Assurance):
    """Bounded dense-ID ledger for stored sampled severity facts only.

    Native observation: protocol=native_autocommit_dense_ids_v1, pinned adapter,
    identity_before/after (DB_IDENTITY digests), connection_before/after > 0,
    autocommit_before/after=1, in_transaction_before/after=0, increment=offset=1,
    isolation_before/after equal READ-COMMITTED or REPEATABLE-READ,
    allocation_source=mariadb_live_show_table_status_v1, next_id_before/after,
    query_after_id/query_through_id, rows=[{id,level}] and fresh clock/sequence
    fields. The adapter performs the unfiltered ordered ID/level query, limited
    to max_batch_rows. Table allocation metadata must be verified live for the
    actual server version; a cached estimate is not this supported protocol.

    Baseline excludes IDs allocated before its final allocation fence, not just
    a visible MAX(id). This is an allocation cohort, not a commit-time census.
    End fence is captured once after >=24h. Pending holes can settle in fresh
    snapshots; an unresolved rollback/deletion suffix remains needs_review.
    """

    def __init__(self, *, adapter_sha256, identity, max_gap_seconds=660,
                 max_witness_age_seconds=5, max_batch_rows=1000, max_pending=2000,
                 max_cohort_ids=250000):
        super().__init__(adapter_sha256, max_gap_seconds, max_witness_age_seconds)
        if (not _digests(identity, DB_IDENTITY) or not _integer(max_batch_rows, 1, 1000)
                or not _integer(max_pending, 1, 10000) or not _integer(max_cohort_ids, 1, 1000000)):
            raise ValueError("invalid_event_source_contract")
        self.identity = dict(identity)
        self.max_batch_rows, self.max_pending = max_batch_rows, max_pending
        self.max_cohort_ids = max_cohort_ids
        self._base = self._cursor = self._started_ns = self._next_id = None
        self._end = self._end_sequence = None
        self._pending = {}
        self._levels = {x: 0 for x in LEVELS}

    def _native(self, value, now_ns):
        if not isinstance(value, dict):
            raise ValueError()
        if (value.get("protocol") != "native_autocommit_dense_ids_v1"
                or value.get("adapter_sha256") != self.adapter_sha256
                or value.get("identity_before") != self.identity or value.get("identity_after") != self.identity
                or value.get("allocation_source") != "mariadb_live_show_table_status_v1"
                or not _integer(value.get("connection_before"), 1)
                or not _integer(value.get("connection_after"), 1)
                or value.get("connection_before") != value.get("connection_after")
                or value.get("isolation_before") not in ("READ-COMMITTED", "REPEATABLE-READ")
                or value.get("isolation_before") != value.get("isolation_after")
                or any(type(value.get(k)) is not int or value[k] != target for k, target in
                       (("autocommit_before", 1), ("autocommit_after", 1),
                        ("in_transaction_before", 0), ("in_transaction_after", 0),
                        ("increment", 1), ("offset", 1)))):
            raise ValueError()
        end = self._clock(value, now_ns)
        lower, upper = value.get("next_id_before"), value.get("next_id_after")
        if (not _integer(lower, 1) or not _integer(upper, lower)
                or (self._next_id is not None and lower < self._next_id)):
            raise ValueError()
        self._next_id = upper
        return end

    def start(self, witness, *, now_ns=None):
        if self._base is not None:
            raise ValueError("event_ledger_already_started")
        now_ns = time.monotonic_ns() if now_ns is None else now_ns
        try:
            self._started_ns = self._native(witness, now_ns)
            if witness.get("rows") != []:
                raise ValueError()
            self._base = self._cursor = witness["next_id_after"] - 1
        except (ValueError, TypeError, KeyError):
            self.gaps.add("invalid_native_event_baseline")
        return self.receipt()

    def consume(self, witness, *, now_ns=None):
        if self._final is not None:
            return copy.deepcopy(self._final)
        now_ns = time.monotonic_ns() if now_ns is None else now_ns
        try:
            if self._base is None:
                raise ValueError()
            self._native(witness, now_ns)
            after, through, rows = (witness.get(k) for k in ("query_after_id", "query_through_id", "rows"))
            if (not _integer(after, self._base) or not _integer(through, after)
                    or through >= witness["next_id_before"]
                    or (self._end is not None and through > self._end)
                    or not isinstance(rows, list) or len(rows) > self.max_batch_rows):
                raise ValueError()
            if witness["next_id_after"] - 1 - self._base > self.max_cohort_ids and self._end is None:
                self.gaps.add("event_allocation_bound")
                return self.receipt()
            previous = after
            for row in rows:
                if (not isinstance(row, dict) or set(row) != {"id", "level"}
                        or not _integer(row["id"], previous + 1, through)
                        or row["level"] not in LEVELS):
                    raise ValueError()
                current, previous = row["id"], row["id"]
                if current <= self._cursor:
                    continue
                if current in self._pending and self._pending[current] != row["level"]:
                    self.gaps.add("event_fact_changed")
                if current not in self._pending and len(self._pending) >= self.max_pending:
                    self.gaps.add("event_pending_bound")
                    break
                self._pending[current] = row["level"]
                while self._cursor + 1 in self._pending:
                    self._cursor += 1
                    self._levels[self._pending.pop(self._cursor)] += 1
        except (ValueError, TypeError, KeyError):
            self.gaps.add("invalid_native_event_snapshot")
        return self.receipt()

    def freeze_end(self, witness, *, now_ns=None):
        if self._end is not None:
            raise ValueError("event_end_already_frozen")
        now_ns = time.monotonic_ns() if now_ns is None else now_ns
        try:
            end = self._native(witness, now_ns)
            if self._base is None or end < self._started_ns + DAY_NS:
                raise ValueError()
            self._end = witness["next_id_before"] - 1
            self._end_sequence = witness["sequence"]
            if self._end < self._cursor or self._end - self._base > self.max_cohort_ids:
                self.gaps.add("event_end_or_allocation_bound")
        except (ValueError, TypeError, KeyError):
            self.gaps.add("invalid_native_event_end")
        return self.receipt()

    def query_bounds(self):
        """Private adapter query controls, separate from aggregate report facts."""
        if self._base is None:
            raise ValueError("event_ledger_not_started")
        return {"after_id": self._cursor, "end_fence_id": self._end,
                "max_rows": self.max_batch_rows}

    def receipt(self):
        if self._final is not None:
            return copy.deepcopy(self._final)
        outstanding = None if self._end is None else max(0, self._end - self._cursor)
        observed = dict(self._levels)
        for level in self._pending.values():
            observed[level] += 1
        return {"scope": "stored_sampled_event_severity_facts_in_allocation_cohort",
                "coverage_complete": False, "result": "needs_review" if self.gaps else "observing",
                "reasons": sorted(self.gaps), "settled_rows": sum(self._levels.values()),
                "levels": observed, "settled_levels": dict(self._levels),
                "observed_rows": sum(self._levels.values()) + len(self._pending),
                "pending_rows": len(self._pending),
                "unsettled_allocations": outstanding, "end_frozen": self._end is not None,
                "all_requests_or_logger_writes_verified": False, "original_flow_history_verified": False}

    def finalize(self, witness, *, now_ns=None):
        if self._final is not None:
            return copy.deepcopy(self._final)
        if self._end is None:
            self.gaps.add("event_end_not_frozen")
        prior_sequence = self._end_sequence
        self.consume(witness, now_ns=now_ns)
        if (prior_sequence is None or self._last_sequence is None
                or self._last_sequence <= prior_sequence):
            self.gaps.add("fresh_final_event_snapshot_required")
        if self._end is None or self._cursor != self._end or self._pending:
            self.gaps.add("unresolved_allocated_event_ids")
        self._final = self.receipt()
        self._final.update(coverage_complete=not self.gaps, result="passed" if not self.gaps else "needs_review")
        return copy.deepcopy(self._final)
