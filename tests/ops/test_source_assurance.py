"""Synthetic operational protocols; no WordPress, provider or database access."""
import copy
import importlib.util
import os
from pathlib import Path
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("source_assurance", ROOT / "tools/monitoring/source_assurance.py")
S = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(S)
NS, DAY = S.SECOND_NS, S.DAY_NS
ADAPTER = "a" * 64
PINS = {"writer": "b" * 64, "rotation_inventory": "c" * 64, "configuration": "d" * 64}
IDENTITY = {"server": "e" * 64, "table": "f" * 64, "definition": "1" * 64}


def clock(now, sequence):
    return {"read_started_ns": now, "read_completed_ns": now,
            "wall_epoch_ns": 1_700_000_000 * NS + now, "sequence": sequence}


def reader(count=1):
    return {"coverage_complete": False, "available": True, "current_path_available": True,
            "detected_gaps": [], "retained_files": count, "pending_line": False, "read_backlog": False}


class Files:
    def __init__(self, protocol="append_rename_unlink_v1", **kwargs):
        self.now, self.sequence = 0, 0
        self.protocol = protocol
        self.object = S.FileSourceAssurance(source_protocol=protocol, pins=PINS,
            adapter_sha256=ADAPTER, producer_identity="2" * 64, boot_identity="3" * 64, **kwargs)
        self.retained = [{"identity": [1, 10], "position": 0, "size": 0}]
        self.current, self.refs = [1, 10], []
        self.reader = reader()
        self.object.observe(self.reader, self.witness(), now_ns=0)

    def witness(self):
        self.sequence += 1
        return {**clock(self.now, self.sequence), "protocol": "native_file_fd_scan_v1",
            "adapter_sha256": ADAPTER, "source_protocol": self.protocol, "pins": dict(PINS),
            "producer_identity": "2" * 64, "boot_identity": "3" * 64,
            "current_identity": list(self.current), "retained": copy.deepcopy(self.retained),
            "registry_before": [[10, 100]], "registry_after": [[10, 100]],
            "writer_refs": copy.deepcopy(self.refs)}

    def begin(self):
        self.now = self.object.grace_ns
        self.object.begin_window(now_ns=self.now)
        return self.now

    def advance(self, target):
        while self.now + 600 * NS < target:
            self.now += 600 * NS
            self.object.observe(self.reader, self.witness(), now_ns=self.now)
        self.now = target

    def finish(self, target=None):
        if target is None:
            target = self.object._window_start_ns + DAY + self.object.grace_ns
        self.advance(target)
        return self.object.finalize(self.reader, self.witness(), now_ns=self.now)


class FileSourceTests(unittest.TestCase):
    def test_full_verified_window_passes_without_changing_generic_reader_claim(self):
        f = Files(); f.begin(); result = f.finish()
        self.assertTrue(result["coverage_complete"])
        self.assertFalse(f.reader["coverage_complete"])
        self.assertEqual("passed", result["result"])
        self.assertEqual(result, f.object.receipt())
        result["reasons"].append("external_mutation")
        self.assertNotIn("external_mutation", f.object.receipt()["reasons"])

    def test_unknown_protocol_and_boolean_pin_cannot_establish_contract(self):
        with self.assertRaises(ValueError):
            Files("trust_me")
        f = Files(); w = f.witness(); w["pins"] = {"verified": True}
        self.assertIn("invalid_native_file_witness", f.object.observe(f.reader, w, now_ns=0)["reasons"])

    def test_generic_coverage_flag_is_never_a_substitute_for_native_witness(self):
        f = Files(); f.reader["coverage_complete"] = True
        result = f.object.observe(f.reader, {"source_verified": True}, now_ns=0)
        self.assertFalse(result["coverage_complete"])
        self.assertIn("invalid_native_file_witness", result["reasons"])

    def test_receipts_never_return_native_private_metadata_or_raw_reader_extras(self):
        f = Files(); w = f.witness(); w["private_path"] = "/private/customer-secret"
        f.reader["raw_line"] = "private-customer-secret"
        result = f.object.observe(f.reader, w, now_ns=0)
        self.assertNotIn("private", repr(result))
        self.assertNotIn(ADAPTER, repr(result))
        self.assertNotIn("registry_before", result)

    def test_buffered_source_requires_start_lookback_and_end_drain(self):
        f = Files("nginx_append_rename_unlink_5s_v1")
        with self.assertRaises(ValueError):
            f.object.begin_window(now_ns=5 * NS)
        start = f.begin()
        self.assertEqual(6 * NS, start)
        self.assertFalse(f.finish(start + DAY + 5 * NS)["coverage_complete"])
        healthy = Files("nginx_append_rename_unlink_5s_v1"); healthy.begin()
        self.assertTrue(healthy.finish()["coverage_complete"])

    def test_reader_gaps_remain_latched_after_a_later_healthy_sample(self):
        for gap in ("event_queue_overflow", "truncated", "read_bound", "generation_unavailable"):
            with self.subTest(gap=gap):
                f = Files(); f.begin(); f.reader["detected_gaps"] = [gap]
                f.object.observe(f.reader, f.witness(), now_ns=f.now)
                f.reader["detected_gaps"] = []
                self.assertIn("reader_detected_gap", f.finish()["reasons"])

    def test_digest_identity_scan_and_freshness_changes_refuse_assurance(self):
        changes = [lambda w: w["pins"].update(writer="4" * 64),
                   lambda w: w.update(producer_identity="4" * 64),
                   lambda w: w.update(boot_identity="4" * 64),
                   lambda w: w.update(registry_after=[]),
                   lambda w: w.update(sequence=0),
                   lambda w: w.update(read_completed_ns=10 * NS)]
        for change in changes:
            with self.subTest(change=change):
                f = Files(); w = f.witness(); change(w)
                self.assertFalse(f.object.observe(f.reader, w, now_ns=0)["coverage_complete"])
                self.assertIn("invalid_native_file_witness", f.object.receipt()["reasons"])

    def test_sampling_gap_and_wall_clock_jump_prevent_complete_window(self):
        f = Files(); f.begin(); f.now += 661 * NS
        self.assertIn("observation_gap", f.object.observe(f.reader, f.witness(), now_ns=f.now)["reasons"])
        f = Files(); w = f.witness(); w["wall_epoch_ns"] += 6 * NS
        self.assertIn("clock_discontinuity", f.object.observe(f.reader, w, now_ns=0)["reasons"])

    def test_retired_writer_and_unread_tail_block_finalization(self):
        for kind in ("writer", "tail", "partial"):
            with self.subTest(kind=kind):
                f = Files(); f.begin()
                f.retained.append({"identity": [1, 11], "position": 0, "size": 0})
                f.current = [1, 11]; f.reader = reader(2)
                if kind == "writer":
                    f.refs = [{"identity": [1, 10], "process": [10, 100]}]
                elif kind == "tail":
                    f.retained[0]["size"] = 1
                else:
                    f.reader["pending_line"] = True
                self.assertFalse(f.finish()["coverage_complete"])

    def test_dropped_retained_generation_and_bound_remain_incomplete(self):
        f = Files(max_generations=1); f.begin()
        f.current = [1, 11]
        f.retained = [{"identity": [1, 11], "position": 0, "size": 0}]
        result = f.finish()
        self.assertIn("retained_generation_lost", result["reasons"])
        self.assertIn("generation_bound", result["reasons"])
        self.assertLessEqual(result["retained_generations"], 1)

    def test_site_php_requires_a_runtime_proof_and_sufficient_active_lifetime(self):
        args = dict(source_protocol="site_php_runtime_append_v1", pins=PINS,
                    adapter_sha256=ADAPTER, producer_identity="2" * 64, boot_identity="3" * 64)
        with self.assertRaises(ValueError):
            S.FileSourceAssurance(**args, valid_until_epoch_ns=1_700_000_000 * NS + 2 * DAY)
        args["pins"] = {**PINS, "runtime_proof": "4" * 64}
        source = S.FileSourceAssurance(**args, valid_until_epoch_ns=1_700_000_000 * NS + DAY)
        f = Files(); w = f.witness(); w.update(source_protocol="site_php_runtime_append_v1", pins=args["pins"])
        source.observe(reader(), w, now_ns=0)
        with self.assertRaises(ValueError):
            source.begin_window(now_ns=NS)
        expired = S.FileSourceAssurance(**args, valid_until_epoch_ns=1_700_000_000 * NS)
        self.assertIn("producer_expired", expired.observe(reader(), w, now_ns=0)["reasons"])

    def test_real_cursor_preserves_renamed_unlinked_inode_and_its_late_writes(self):
        spec = importlib.util.spec_from_file_location("real_log_cursor", ROOT / "tools/monitoring/log_cursor.py")
        cursor_module = importlib.util.module_from_spec(spec); spec.loader.exec_module(cursor_module)
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "source.log"; path.write_bytes(b"before\n")
            cursor = cursor_module.LogCursor(path)
            old_writer = path.open("ab")
            f = Files()
            try:
                def scan():
                    f.reader = cursor.observe()
                    f.current = list((path.stat().st_dev, path.stat().st_ino))
                    f.retained = [{"identity": list(identity), "position": state.position,
                                   "size": os.fstat(state.fd).st_size}
                                  for identity, state in cursor._files.items()]
                    f.refs = [] if old_writer.closed else [{"identity": list(old_identity), "process": [10, 100]}]
                old_identity = next(iter(cursor._files))
                scan()
                # Start a fresh synthetic source contract with the actual cursor's identities.
                f.object = S.FileSourceAssurance(source_protocol=f.protocol, pins=PINS,
                    adapter_sha256=ADAPTER, producer_identity="2" * 64, boot_identity="3" * 64)
                f.object.observe(f.reader, f.witness(), now_ns=0); f.begin()
                rotated = path.with_suffix(".old"); path.rename(rotated); path.write_bytes(b"PHP Warning: new\n")
                old_writer.write(b"PHP Fatal error: late\n"); old_writer.flush(); rotated.unlink()
                scan(); f.object.observe(f.reader, f.witness(), now_ns=f.now)
                self.assertEqual(1, f.reader["totals"]["php_fatal"])
                self.assertEqual(1, f.reader["totals"]["php_warning"])
                old_writer.close(); scan()
                self.assertTrue(f.finish()["coverage_complete"])
                self.assertFalse(cursor.observe()["coverage_complete"])
            finally:
                old_writer.close(); cursor.close()


class Events:
    def __init__(self, **kwargs):
        self.now, self.sequence, self.next_id = 0, 0, 100
        self.object = S.EventContinuityLedger(adapter_sha256=ADAPTER, identity=IDENTITY, **kwargs)
        self.object.start(self.witness(), now_ns=0)

    def witness(self, rows=(), *, after=None, through=None):
        self.sequence += 1
        bounds = self.object.query_bounds() if self.object._base is not None else {"after_id": 99, "end_fence_id": None}
        upper = self.next_id - 1
        if bounds["end_fence_id"] is not None:
            upper = min(upper, bounds["end_fence_id"])
        return {**clock(self.now, self.sequence), "protocol": "native_autocommit_dense_ids_v1",
            "adapter_sha256": ADAPTER, "identity_before": dict(IDENTITY), "identity_after": dict(IDENTITY),
            "allocation_source": "mariadb_live_show_table_status_v1", "connection_before": 1, "connection_after": 1,
            "autocommit_before": 1, "autocommit_after": 1, "in_transaction_before": 0, "in_transaction_after": 0,
            "isolation_before": "REPEATABLE-READ", "isolation_after": "REPEATABLE-READ",
            "increment": 1, "offset": 1, "next_id_before": self.next_id, "next_id_after": self.next_id,
            "query_after_id": bounds["after_id"] if after is None else after,
            "query_through_id": upper if through is None else through,
            "rows": [{"id": i, "level": level} for i, level in rows]}

    def consume(self, rows=(), **changes):
        w = self.witness(rows); w.update(changes)
        return self.object.consume(w, now_ns=self.now)

    def advance(self, target=DAY):
        while self.now + 600 * NS < target:
            self.now += 600 * NS; self.consume()
        self.now = target

    def freeze(self):
        self.advance(); return self.object.freeze_end(self.witness(), now_ns=self.now)

    def finish(self, rows=()):
        self.now += NS
        return self.object.finalize(self.witness(rows), now_ns=self.now)


class EventLedgerTests(unittest.TestCase):
    def test_dense_rows_are_counted_once_and_full_window_can_pass(self):
        e = Events(); e.next_id = 103
        e.consume([(100, "info"), (101, "warning"), (102, "error")])
        e.object.consume(e.witness([(101, "warning"), (102, "error")], after=100), now_ns=0)
        e.freeze(); result = e.finish()
        self.assertTrue(result["coverage_complete"])
        self.assertEqual(3, result["observed_rows"])
        self.assertEqual(1, result["levels"]["error"])
        self.assertFalse(result["all_requests_or_logger_writes_verified"])
        self.assertFalse(result["original_flow_history_verified"])
        self.assertEqual(result, e.object.receipt())

    def test_out_of_order_commit_stays_pending_until_lower_id_is_visible(self):
        e = Events(); e.next_id = 102
        first = e.consume([(101, "error")])
        self.assertEqual(1, first["pending_rows"])
        self.assertEqual(0, first["settled_rows"])
        self.assertEqual(1, first["levels"]["error"])
        second = e.consume([(100, "info"), (101, "error")])
        self.assertEqual(2, second["settled_rows"])
        self.assertEqual(0, second["pending_rows"])
        e.freeze(); self.assertTrue(e.finish()["coverage_complete"])

    def test_rollback_or_deleted_middle_id_cannot_be_skipped(self):
        e = Events(); e.next_id = 103
        e.consume([(100, "info"), (102, "warning")]); e.freeze()
        result = e.finish()
        self.assertIn("unresolved_allocated_event_ids", result["reasons"])
        self.assertEqual(2, result["observed_rows"])

    def test_deleted_final_allocated_suffix_is_detected_without_visible_max(self):
        e = Events(); e.next_id = 103
        e.consume([(100, "info")]); e.freeze(); result = e.finish()
        self.assertEqual(2, result["unsettled_allocations"])
        self.assertFalse(result["coverage_complete"])

    def test_empty_cohort_is_not_a_claim_that_logging_calls_succeeded(self):
        e = Events(); e.freeze(); result = e.finish()
        self.assertTrue(result["coverage_complete"])
        self.assertEqual(0, result["observed_rows"])
        self.assertFalse(result["all_requests_or_logger_writes_verified"])

    def test_prior_allocations_are_excluded_by_explicit_start_fence(self):
        e = Events(); self.assertEqual(99, e.object.query_bounds()["after_id"])
        self.assertEqual("stored_sampled_event_severity_facts_in_allocation_cohort", e.object.receipt()["scope"])
        e.next_id = 101; e.consume([(100, "notice")]); e.freeze()
        self.assertEqual(1, e.finish()["settled_rows"])

    def test_privacy_update_or_old_retention_does_not_recount_severity_facts(self):
        e = Events(); e.next_id = 102; e.consume([(100, "notice"), (101, "error")])
        # Native adapter intentionally excludes personal context and old rows.
        e.object.consume(e.witness([(100, "notice"), (101, "error")], after=99), now_ns=0)
        e.freeze(); self.assertEqual(2, e.finish()["observed_rows"])

    def test_insertions_after_fixed_end_fence_do_not_move_the_cohort(self):
        e = Events(); e.next_id = 101; e.consume([(100, "info")]); e.freeze()
        self.assertEqual(100, e.object.query_bounds()["end_fence_id"])
        e.next_id = 110
        self.assertTrue(e.finish()["coverage_complete"])
        self.assertEqual(100, e.object.query_bounds()["end_fence_id"])

    def test_fresh_final_snapshot_can_settle_a_previously_uncommitted_row(self):
        e = Events(); e.next_id = 101; e.freeze()
        self.assertTrue(e.finish([(100, "info")])["coverage_complete"])

    def test_repeatable_read_or_replayed_final_snapshot_cannot_pass(self):
        e = Events(); e.freeze(); w = e.witness(); w["in_transaction_before"] = 1
        self.assertFalse(e.object.finalize(w, now_ns=e.now)["coverage_complete"])
        e = Events(); e.advance(); w = e.witness()
        e.object.freeze_end(w, now_ns=e.now)
        self.assertFalse(e.object.finalize(w, now_ns=e.now)["coverage_complete"])

    def test_identity_reconnect_counter_protocol_and_read_shape_fail_closed(self):
        changes = [dict(protocol="trust_me", coverage_complete=True),
            dict(identity_after={**IDENTITY, "table": "9" * 64}), dict(connection_after=2),
            dict(allocation_source="cached_estimate"), dict(next_id_before=99, next_id_after=99),
            dict(increment=2), dict(autocommit_before=True), dict(query_through_id=100),
            dict(isolation_before="READ-UNCOMMITTED", isolation_after="READ-UNCOMMITTED")]
        for change in changes:
            with self.subTest(change=change):
                e = Events(); self.assertIn("invalid_native_event_snapshot", e.consume(**change)["reasons"])

    def test_separate_fresh_connections_are_valid_when_each_native_read_is_fenced(self):
        e = Events(); e.consume(connection_before=2, connection_after=2)
        self.assertEqual([], e.object.receipt()["reasons"])

    def test_rows_are_ordered_unfiltered_finite_severity_facts(self):
        for rows in ([(101, "info"), (100, "error")], [(100, "secret")], [(100, "info"), (100, "info")]):
            with self.subTest(rows=rows):
                e = Events(); e.next_id = 102
                self.assertIn("invalid_native_event_snapshot", e.consume(rows)["reasons"])

    def test_batch_pending_and_allocation_bounds_prevent_unbounded_state(self):
        e = Events(max_batch_rows=1); e.next_id = 102
        self.assertIn("invalid_native_event_snapshot", e.consume([(100, "info"), (101, "info")])["reasons"])
        e = Events(max_pending=1); e.next_id = 103
        result = e.consume([(101, "info"), (102, "error")])
        self.assertIn("event_pending_bound", result["reasons"])
        self.assertLessEqual(result["pending_rows"], 1)
        e = Events(max_cohort_ids=1); e.next_id = 102
        self.assertIn("event_allocation_bound", e.consume()["reasons"])

    def test_failed_native_read_latches_even_if_later_observations_are_healthy(self):
        e = Events(); e.object.fail("native_read_failed"); e.freeze()
        self.assertFalse(e.finish()["coverage_complete"])

    def test_early_end_clock_discontinuity_and_missing_final_fence_refuse(self):
        e = Events(); e.object.freeze_end(e.witness(), now_ns=0)
        self.assertIn("invalid_native_event_end", e.object.receipt()["reasons"])
        e = Events(); w = e.witness(); w["wall_epoch_ns"] += 6 * NS
        self.assertIn("clock_discontinuity", e.object.consume(w, now_ns=0)["reasons"])
        e = Events(); self.assertIn("event_end_not_frozen", e.finish()["reasons"])


if __name__ == "__main__":
    unittest.main()
