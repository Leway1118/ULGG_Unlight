from __future__ import annotations

import hashlib
import json
import re
import tomllib
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping


CANONICALIZATION = "toml-parsed-json-sort-keys-v1"
CONTENT_HASH_PREFIX = "sha256:"
FINGERPRINT_LENGTH = 8

CARD_KEY_PATTERN = re.compile(
    r"^cc\d{3}_(?:\d{2}|r\d{2})$",
    re.IGNORECASE,
)


class RulesetContentError(ValueError):
    """Raised when Ruleset content cannot be parsed or validated."""

    def __init__(
        self,
        code: str,
        message: str,
        *,
        details: Mapping[str, Any] | None = None,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.message = message
        self.details = dict(details or {})

    def to_dict(self) -> dict[str, Any]:
        return {
            "code": self.code,
            "message": self.message,
            "details": self.details,
        }


@dataclass(frozen=True)
class RulesetContent:
    """Validated and canonicalized Ruleset COST content."""

    source_path: Path | None
    cost_map: dict[str, int]
    canonical_json: str
    canonicalization: str
    content_hash: str
    fingerprint: str
    expected_count: int

    def to_summary(self) -> dict[str, Any]:
        return {
            "source_path": (
                str(self.source_path)
                if self.source_path is not None
                else None
            ),
            "canonicalization": self.canonicalization,
            "content_hash": self.content_hash,
            "fingerprint": self.fingerprint,
            "expected_count": self.expected_count,
        }


def parse_toml_text(
    text: str,
    *,
    source_name: str = "<memory>",
) -> dict[str, Any]:
    if not isinstance(text, str):
        raise RulesetContentError(
            "INVALID_RULESET_TEXT",
            "Ruleset TOML content must be text.",
            details={
                "source": source_name,
                "actual_type": type(text).__name__,
            },
        )

    normalized = text.removeprefix("\ufeff")

    try:
        parsed = tomllib.loads(normalized)
    except tomllib.TOMLDecodeError as error:
        raise RulesetContentError(
            "TOML_PARSE_FAILED",
            "Ruleset TOML could not be parsed.",
            details={
                "source": source_name,
                "reason": str(error),
            },
        ) from error

    if not isinstance(parsed, dict):
        raise RulesetContentError(
            "INVALID_RULESET_ROOT",
            "Ruleset TOML root must be an object.",
            details={"source": source_name},
        )

    return parsed


def validate_cost_map(
    value: Mapping[str, Any],
    *,
    source_name: str = "<memory>",
) -> dict[str, int]:
    """
    Validate the v1 Ruleset format.

    v1 requires one non-empty, flat TOML object:

        cc001_01 = 8
        cc001_r01 = 19
    """
    if not isinstance(value, Mapping):
        raise RulesetContentError(
            "INVALID_COST_MAP",
            "Ruleset COST data must be an object.",
            details={
                "source": source_name,
                "actual_type": type(value).__name__,
            },
        )

    if not value:
        raise RulesetContentError(
            "EMPTY_COST_MAP",
            "Ruleset COST data must not be empty.",
            details={"source": source_name},
        )

    validated: dict[str, int] = {}
    invalid_keys: list[str] = []
    invalid_values: list[dict[str, Any]] = []

    for raw_key, raw_value in value.items():
        if not isinstance(raw_key, str):
            invalid_keys.append(str(raw_key))
            continue

        key = raw_key.strip()

        if key != raw_key or not CARD_KEY_PATTERN.fullmatch(key):
            invalid_keys.append(raw_key)
            continue

        # bool is a subclass of int, so it must be rejected explicitly.
        if isinstance(raw_value, bool) or not isinstance(raw_value, int):
            invalid_values.append(
                {
                    "key": key,
                    "actual_type": type(raw_value).__name__,
                    "value": _safe_value(raw_value),
                }
            )
            continue

        if raw_value < 0:
            invalid_values.append(
                {
                    "key": key,
                    "actual_type": "int",
                    "value": raw_value,
                    "reason": "negative_cost",
                }
            )
            continue

        normalized_key = key.lower()

        if normalized_key in validated:
            raise RulesetContentError(
                "DUPLICATE_NORMALIZED_KEY",
                "Ruleset contains duplicate card keys after normalization.",
                details={
                    "source": source_name,
                    "key": normalized_key,
                },
            )

        validated[normalized_key] = raw_value

    if invalid_keys or invalid_values:
        raise RulesetContentError(
            "INVALID_COST_ENTRIES",
            "Ruleset contains invalid COST entries.",
            details={
                "source": source_name,
                "invalid_keys": invalid_keys[:20],
                "invalid_values": invalid_values[:20],
                "invalid_key_count": len(invalid_keys),
                "invalid_value_count": len(invalid_values),
            },
        )

    return validated


def canonical_json_text(data: Mapping[str, int]) -> str:
    try:
        return json.dumps(
            dict(data),
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
            allow_nan=False,
        )
    except (TypeError, ValueError) as error:
        raise RulesetContentError(
            "CANONICALIZATION_FAILED",
            "Ruleset could not be converted to canonical JSON.",
            details={"reason": str(error)},
        ) from error


def calculate_content_hash(canonical_json: str) -> str:
    encoded = canonical_json.encode("utf-8")
    digest = hashlib.sha256(encoded).hexdigest()
    return CONTENT_HASH_PREFIX + digest


def fingerprint_from_hash(
    content_hash: str,
    *,
    length: int = FINGERPRINT_LENGTH,
) -> str:
    expected_length = len(CONTENT_HASH_PREFIX) + 64

    if (
        not isinstance(content_hash, str)
        or len(content_hash) != expected_length
        or not content_hash.startswith(CONTENT_HASH_PREFIX)
    ):
        raise RulesetContentError(
            "INVALID_CONTENT_HASH",
            "Content hash must use the sha256:<64 lowercase hex> format.",
        )

    digest = content_hash.removeprefix(CONTENT_HASH_PREFIX)

    if not re.fullmatch(r"[0-9a-f]{64}", digest):
        raise RulesetContentError(
            "INVALID_CONTENT_HASH",
            "Content hash must use the sha256:<64 lowercase hex> format.",
        )

    if length < 1 or length > 64:
        raise RulesetContentError(
            "INVALID_FINGERPRINT_LENGTH",
            "Fingerprint length must be between 1 and 64.",
            details={"length": length},
        )

    return digest[:length].upper()


def build_ruleset_content(
    raw_data: Mapping[str, Any],
    *,
    source_path: Path | None = None,
    source_name: str | None = None,
) -> RulesetContent:
    resolved_source_name = (
        source_name
        or (str(source_path) if source_path is not None else "<memory>")
    )

    cost_map = validate_cost_map(
        raw_data,
        source_name=resolved_source_name,
    )
    canonical_json = canonical_json_text(cost_map)
    content_hash = calculate_content_hash(canonical_json)

    return RulesetContent(
        source_path=source_path,
        cost_map=cost_map,
        canonical_json=canonical_json,
        canonicalization=CANONICALIZATION,
        content_hash=content_hash,
        fingerprint=fingerprint_from_hash(content_hash),
        expected_count=len(cost_map),
    )


def load_ruleset_content(path: Path | str) -> RulesetContent:
    source_path = Path(path).expanduser().resolve()

    if not source_path.exists():
        raise RulesetContentError(
            "RULESET_FILE_NOT_FOUND",
            "Ruleset TOML file does not exist.",
            details={"path": str(source_path)},
        )

    if not source_path.is_file():
        raise RulesetContentError(
            "RULESET_PATH_NOT_FILE",
            "Ruleset path is not a file.",
            details={"path": str(source_path)},
        )

    try:
        raw_bytes = source_path.read_bytes()
    except OSError as error:
        raise RulesetContentError(
            "RULESET_FILE_READ_FAILED",
            "Ruleset TOML file could not be read.",
            details={
                "path": str(source_path),
                "reason": str(error),
            },
        ) from error

    try:
        text = raw_bytes.decode("utf-8-sig")
    except UnicodeDecodeError as error:
        raise RulesetContentError(
            "RULESET_ENCODING_INVALID",
            "Ruleset TOML must use UTF-8 encoding.",
            details={
                "path": str(source_path),
                "reason": str(error),
            },
        ) from error

    parsed = parse_toml_text(
        text,
        source_name=str(source_path),
    )

    return build_ruleset_content(
        parsed,
        source_path=source_path,
    )


def load_ruleset_text(
    text: str,
    *,
    source_name: str = "<memory>",
) -> RulesetContent:
    parsed = parse_toml_text(
        text,
        source_name=source_name,
    )
    return build_ruleset_content(
        parsed,
        source_name=source_name,
    )


def _safe_value(value: Any) -> Any:
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    return f"<{type(value).__name__}>"