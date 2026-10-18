from __future__ import annotations

import unittest

from run_raid_bot import _validate_enrichment_timeouts


class EnrichmentTimeoutConfigTests(unittest.TestCase):
    def test_enabled_enrichment_requires_overall_greater_than_protocol(self) -> None:
        for overall in (9.0, 10.0):
            with self.subTest(overall=overall), self.assertRaisesRegex(
                ValueError,
                "must be greater",
            ):
                _validate_enrichment_timeouts(
                    10.0,
                    overall,
                    enabled=True,
                )

        _validate_enrichment_timeouts(10.0, 55.0, enabled=True)

    def test_disabled_enrichment_does_not_reject_unused_budget_relation(self) -> None:
        _validate_enrichment_timeouts(10.0, 10.0, enabled=False)

    def test_each_timeout_must_be_positive(self) -> None:
        with self.assertRaisesRegex(ValueError, "PROTOCOL_TIMEOUT"):
            _validate_enrichment_timeouts(0, 55, enabled=True)
        with self.assertRaisesRegex(ValueError, "ENRICHMENT_TIMEOUT"):
            _validate_enrichment_timeouts(10, 0, enabled=True)


if __name__ == "__main__":
    unittest.main()
