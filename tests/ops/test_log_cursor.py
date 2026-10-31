"""Linux-only, synthetic filesystem acceptance for the bounded log observer.

Run on isolated CI with PYTHONDONTWRITEBYTECODE=1; never point at a site log.
"""
import gzip
import importlib.util
import json
import os
from pathlib import Path
import struct
import tempfile
import unittest
from unittest.mock import patch


SOURCE = Path(__file__).resolve().parents[2] / "tools/monitoring/log_cursor.py"
SPEC = importlib.util.spec_from_file_location("pinova_log_cursor", SOURCE)
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)
LogCursor = MODULE.LogCursor


class LogCursorTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="pinova-cursor-test-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.logs = self.root / "logs"
        self.logs.mkdir()
        self.path = self.logs / "source.log"
        self.path.write_bytes(b"")

    def cursor(self, **options):
        cursor = LogCursor(self.path, **options)
        self.addCleanup(cursor.close)
        return cursor

    def append(self, message, path=None):
        with (path or self.path).open("ab") as output:
            output.write(message)

    def inject(self, cursor, events):
        raw = b"".join(struct.pack("iIII", wd, mask, cookie, 0)
                       for wd, mask, cookie in events)
        with patch.object(MODULE.os, "read", side_effect=[raw, BlockingIOError()]):
            return cursor.observe()

    def test_append_is_counted_once_without_raw_content_or_assurance_claim(self):
        cursor = self.cursor()
        secret = b"private-mobile-OTP-cookie-and-token"
        self.append(b"PHP Warning: " + secret + b"\nPHP Fatal error: synthetic\n")
        first = cursor.observe()
        self.assertEqual(1, first["counts"]["php_warning"])
        self.assertEqual(1, first["counts"]["php_fatal"])
        self.assertEqual(0, cursor.observe()["counts"]["php_warning"])
        self.assertEqual("unknown", first["history_assurance"])
        self.assertFalse(first["coverage_complete"])
        self.assertTrue(first["needs_review"])
        encoded = json.dumps(first)
        self.assertNotIn(secret.decode(), encoded)
        self.assertNotIn(str(self.path), encoded)

    def test_baseline_skips_existing_rows(self):
        self.path.write_bytes(b"PHP Warning: before baseline\n")
        cursor = self.cursor()
        self.assertEqual(0, cursor.observe()["counts"]["php_warning"])
        self.append(b"PHP Warning: after baseline\n")
        self.assertEqual(1, cursor.observe()["counts"]["php_warning"])

    def test_baseline_partial_line_is_not_combined_into_new_event(self):
        self.path.write_bytes(b"PHP Warning:")
        cursor = self.cursor()
        self.append(b" old unfinished row\nPHP Warning: new row\n")
        result = cursor.observe()
        self.assertEqual(1, result["counts"]["php_warning"])
        self.assertIn("baseline_partial_line", result["coverage_reasons"])

    def test_rename_new_file_and_late_old_descriptor_writes_are_observed(self):
        cursor = self.cursor()
        with self.path.open("ab", buffering=0) as old_writer:
            old_writer.write(b"PHP Warning: old before rotate\n")
            self.path.rename(self.logs / "source.log.1")
            self.path.write_bytes(b"PHP Warning: new generation\n")
            self.assertEqual(2, cursor.observe()["counts"]["php_warning"])
            old_writer.write(b"PHP Warning: late old generation\n")
            result = cursor.observe()
        self.assertEqual(1, result["counts"]["php_warning"])
        self.assertEqual(2, result["retained_files"])
        self.assertEqual([], result["detected_gaps"])
        self.assertFalse(result["coverage_complete"])

    def test_compression_and_unlink_do_not_drop_or_duplicate_retained_inode(self):
        cursor = self.cursor()
        with self.path.open("ab", buffering=0) as old_writer:
            self.path.rename(self.logs / "source.log.1")
            self.path.write_bytes(b"")
            old_writer.write(b"PHP Warning: old row\n")
            self.assertEqual(1, cursor.observe()["counts"]["php_warning"])
            archive = self.logs / "source.log.1"
            with gzip.open(self.logs / "source.log.1.gz", "wb") as compressed:
                compressed.write(archive.read_bytes())
            archive.unlink()
            old_writer.write(b"PHP Warning: late after compression\n")
            result = cursor.observe()
        self.assertEqual(1, result["counts"]["php_warning"])
        self.assertEqual(2, result["totals"]["php_warning"])
        self.assertNotIn("generation_unavailable", result["detected_gaps"])

    def test_repeated_rename_before_poll_recovers_each_retained_generation(self):
        cursor = self.cursor()
        self.append(b"PHP Warning: original\n")
        first = self.logs / "first"
        second = self.logs / "second"
        self.path.rename(first)
        first.rename(second)
        self.path.write_bytes(b"PHP Warning: middle\n")
        self.path.rename(self.logs / "middle")
        self.path.write_bytes(b"PHP Warning: current\n")
        result = cursor.observe()
        self.assertEqual(3, result["counts"]["php_warning"])
        self.assertEqual(3, result["retained_files"])
        self.assertEqual([], result["detected_gaps"])

    def test_replacement_between_event_drain_and_open_cannot_silently_lose_a_generation(self):
        cursor = self.cursor()
        self.path.rename(self.logs / "baseline")
        first_generation = b"PHP Warning: unresolved first generation\n"
        self.path.write_bytes(first_generation)
        retained = self.logs / "first-generation"
        native_open = MODULE.os.open
        raced = False

        def replace_before_native_open(name, flags, *args, **kwargs):
            nonlocal raced
            if not raced and name == cursor._name and kwargs.get("dir_fd") == cursor._parent_fd:
                raced = True
                # CREATE has already been drained. Change the name before the
                # unresolved generation's actual open, retaining its old bytes.
                self.path.rename(retained)
                self.path.write_bytes(b"PHP Fatal error: replacement generation\n")
            return native_open(name, flags, *args, **kwargs)

        with patch.object(MODULE.os, "open", side_effect=replace_before_native_open):
            cursor.observe()
        self.assertTrue(raced, "The fixture must reach the intended native-open race.")
        self.assertEqual(first_generation, retained.read_bytes())
        result = cursor.observe()
        both_observed = (result["totals"]["php_warning"] == 1
                         and result["totals"]["php_fatal"] == 1)
        self.assertTrue(both_observed or result["detected_gaps"],
                        "A replaced generation must be observed or explicitly marked as a gap.")
        self.assertFalse(result["coverage_complete"])
        if result["detected_gaps"]:
            self.append(b"ordinary later row\n")
            self.assertTrue(set(result["detected_gaps"]).issubset(cursor.observe()["detected_gaps"]))

    def test_replaced_ancestor_invalidates_absolute_parent_but_preserves_old_fd_writes(self):
        holder = self.root / "holder"
        logs = holder / "logs"
        logs.mkdir(parents=True)
        source = logs / "source.log"
        source.write_bytes(b"")
        cursor = LogCursor(source)
        self.addCleanup(cursor.close)
        with source.open("ab", buffering=0) as old_writer:
            # The watched child directory is not itself renamed. Its ancestor
            # moves, so a MOVE_SELF event for that child is not a witness.
            holder.rename(self.root / "retained-holder")
            logs.mkdir(parents=True)
            source.write_bytes(b"PHP Fatal error: new absolute path\n")
            held_parent = os.fstat(cursor._parent_fd)
            current_parent = logs.stat()
            self.assertNotEqual((held_parent.st_dev, held_parent.st_ino),
                                (current_parent.st_dev, current_parent.st_ino))
            old_writer.write(b"PHP Warning: retained old descriptor\n")
            result = cursor.observe()
            self.assertEqual(1, result["counts"]["php_warning"])
            self.assertTrue(any(reason.startswith("parent_") for reason in result["detected_gaps"]),
                            "Replacing an ancestor must explicitly invalidate the absolute parent identity.")
            self.assertFalse(result["current_path_available"])
            self.assertEqual("incomplete", result["history_assurance"])
            old_writer.write(b"PHP Warning: later old descriptor\n")
            later = cursor.observe()
            self.assertEqual(1, later["counts"]["php_warning"])
            self.assertTrue(set(result["detected_gaps"]).issubset(later["detected_gaps"]))
        self.assertFalse(result["coverage_complete"])

    def test_generation_removed_before_open_latches_an_unrecoverable_gap(self):
        cursor = self.cursor()
        self.path.rename(self.logs / "baseline")
        self.path.write_bytes(b"PHP Warning: unseen generation\n")
        self.path.rename(self.logs / "ephemeral")
        (self.logs / "ephemeral").unlink()
        self.path.write_bytes(b"PHP Warning: current\n")
        result = cursor.observe()
        self.assertIn("generation_unavailable", result["detected_gaps"])
        self.assertEqual("incomplete", result["history_assurance"])
        self.append(b"ordinary row\n")
        self.assertIn("generation_unavailable", cursor.observe()["detected_gaps"])

    def test_missing_current_path_does_not_discard_late_old_writes(self):
        cursor = self.cursor()
        with self.path.open("ab", buffering=0) as writer:
            self.path.unlink()
            writer.write(b"PHP Warning: unlinked but open\n")
            result = cursor.observe()
        self.assertEqual(1, result["counts"]["php_warning"])
        self.assertFalse(result["current_path_available"])
        self.assertIn("source_path_unavailable", result["detected_gaps"])

    def test_detectable_truncate_latches_gap(self):
        self.path.write_bytes(b"baseline record that is substantially longer\n")
        cursor = self.cursor()
        self.path.write_bytes(b"PHP Warning: new\n")
        result = cursor.observe()
        self.assertIn("truncated", result["detected_gaps"])
        self.assertEqual(1, result["counts"]["php_warning"])

    def test_same_prefix_truncate_regrow_cannot_manufacture_history_assurance(self):
        prefix = b"baseline prefix\n"
        self.path.write_bytes(prefix)
        cursor = self.cursor()
        # Both modifications can collapse to one IN_MODIFY. The deleted row
        # has no surviving byte or size witness at the time of observation.
        self.append(b"PHP Fatal error: lost between observations\n")
        self.path.write_bytes(prefix + b"a replacement which exceeds the former observed size\n")
        result = cursor.observe()
        self.assertEqual(0, result["counts"]["php_fatal"])
        self.assertFalse(result["coverage_complete"])
        self.assertTrue(result["needs_review"])
        self.assertIn("source_history_unverified", result["coverage_reasons"])

    def test_queue_overflow_is_latched_even_after_later_successful_reads(self):
        cursor = self.cursor()
        first = self.inject(cursor, [(-1, MODULE.IN_Q_OVERFLOW, 0)])
        self.assertIn("event_queue_overflow", first["detected_gaps"])
        self.append(b"PHP Warning: later\n")
        later = cursor.observe()
        self.assertEqual(1, later["counts"]["php_warning"])
        self.assertIn("event_queue_overflow", later["detected_gaps"])

    def test_watch_loss_and_event_bound_are_not_hidden_by_reconciliation(self):
        cursor = self.cursor(max_events=1)
        result = self.inject(cursor, [
            (cursor._parent_watch, MODULE.IN_IGNORED, 0),
            (cursor._parent_watch, MODULE.IN_MODIFY, 0),
        ])
        self.assertIn("parent_watch_lost", result["detected_gaps"])
        self.assertIn("event_bound", result["detected_gaps"])

    def test_malformed_kernel_record_is_not_treated_as_an_empty_queue(self):
        cursor = self.cursor()
        raw = struct.pack("iIII", cursor._parent_watch, MODULE.IN_CREATE, 0, 300)
        with patch.object(MODULE.os, "read", return_value=raw):
            result = cursor.observe()
        self.assertIn("invalid_event", result["detected_gaps"])

    def test_permission_changes_are_reported_without_exposing_mode_or_path(self):
        cursor = self.cursor()
        self.path.chmod(self.path.stat().st_mode ^ 0o040)
        result = cursor.observe()
        self.assertIn("log_owner_or_mode_changed", result["detected_gaps"])
        self.assertNotIn(str(self.path), json.dumps(result))

    def test_descriptor_bound_preserves_held_files_and_marks_new_generation(self):
        cursor = self.cursor(max_open_files=1)
        self.path.rename(self.logs / "old")
        self.path.write_bytes(b"PHP Warning: cannot retain\n")
        result = cursor.observe()
        self.assertEqual(1, result["retained_files"])
        self.assertIn("file_descriptor_bound", result["detected_gaps"])

    def test_unpaired_moves_cannot_grow_pending_cookie_storage(self):
        outside = self.root / "outside"
        outside.mkdir()
        cursor = self.cursor(max_pending_moves=1)
        self.path.rename(outside / "first")
        self.path.write_bytes(b"")
        cursor.observe()
        self.path.rename(outside / "second")
        self.path.write_bytes(b"")
        result = cursor.observe()
        self.assertIn("rename_bound", result["detected_gaps"])
        self.assertLessEqual(len(cursor._moves), 1)

    def test_read_bound_is_an_io_bound_and_remains_latched_after_catching_up(self):
        cursor = self.cursor(max_read=32)
        self.append(b"x" * 100 + b"\n")
        result = cursor.observe()
        self.assertLessEqual(result["io_bytes"], 32)
        self.assertIn("read_bound", result["detected_gaps"])
        for _ in range(6):
            result = cursor.observe()
            self.assertLessEqual(result["io_bytes"], 32)
        self.assertIn("read_bound", result["detected_gaps"])
        self.assertFalse(result["read_backlog"])

    def test_oversize_line_is_discarded_until_newline_without_unbounded_buffer(self):
        cursor = self.cursor(max_line=32)
        self.append(b"x" * 100)
        first = cursor.observe()
        self.assertIn("line_bound", first["detected_gaps"])
        self.append(b"PHP Warning: still same invalid row\nPHP Warning: valid\n")
        second = cursor.observe()
        self.assertEqual(1, second["counts"]["php_warning"])
        self.assertLessEqual(max(len(state.pending) for state in cursor._files.values()), 32)

    def test_final_drain_reads_late_data_and_reports_partial_lines(self):
        cursor = self.cursor()
        self.append(b"PHP Warning: final complete\npartial secret")
        result = cursor.finalize()
        self.assertEqual(1, result["totals"]["php_warning"])
        self.assertTrue(result["drain_observed"])
        self.assertTrue(result["finalized"])
        self.assertIn("final_partial_line", result["detected_gaps"])
        self.assertEqual(result, cursor.finalize())
        self.assertNotIn("partial secret", json.dumps(result))

    def test_exhausted_final_drain_is_not_success(self):
        cursor = self.cursor(max_read=32, max_final_passes=1)
        self.append(b"x" * 200)
        result = cursor.finalize()
        self.assertFalse(result["drain_observed"])
        self.assertIn("final_drain_bound", result["detected_gaps"])

    def test_close_without_a_final_drain_cannot_be_certified_later(self):
        cursor = self.cursor()
        cursor.close()
        result = cursor.finalize()
        self.assertFalse(result["drain_observed"])
        self.assertIn("observer_closed", result["detected_gaps"])

    def test_finalization_releases_only_its_owned_descriptors(self):
        cursor = self.cursor()
        descriptors = [cursor._parent_fd, cursor._inotify_fd,
                       *(state.fd for state in cursor._files.values())]
        unrelated = os.open(self.path, os.O_RDONLY)
        self.addCleanup(os.close, unrelated)
        cursor.finalize()
        for descriptor in descriptors:
            with self.subTest(descriptor=descriptor), self.assertRaises(OSError):
                os.fstat(descriptor)
        self.assertEqual(self.path.stat().st_ino, os.fstat(unrelated).st_ino)

    def test_rejects_symlink_and_invalid_bounds_without_following_target(self):
        link = self.logs / "link.log"
        link.symlink_to(self.path)
        cursor = LogCursor(link)
        self.addCleanup(cursor.close)
        self.assertIn("source_path_unavailable", cursor.observe()["detected_gaps"])
        for key in ("max_read", "max_open_files", "max_events", "max_line", "max_pending_moves", "max_final_passes"):
            for value in (0, -1, True, 1.5):
                with self.subTest(key=key, value=value), self.assertRaises(ValueError):
                    LogCursor(self.path, **{key: value})


if __name__ == "__main__":
    unittest.main()
