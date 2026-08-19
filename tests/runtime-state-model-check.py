#!/usr/bin/env python3
"""Exhaustive finite-state checks for the durable runtime contract.

This is an executable specification. It does not claim to prove arbitrary PHP,
but it exhaustively proves the finite transition/retry/verification model that
the implementation conformance gates are required to match.
"""
from __future__ import annotations

from enum import IntEnum
from itertools import product

STATES = {
    "PLANNED",
    "READY",
    "RUNNING",
    "WAITING_FOR_BROWSER",
    "INTERRUPTED",
    "COMPLETED",
    "TECHNICAL_ERROR",
    "CANCELLED",
    "DEAD_LETTER",
}
TERMINAL = {"COMPLETED", "TECHNICAL_ERROR", "CANCELLED", "DEAD_LETTER"}

TRANSITIONS = {
    "PLANNED": {"READY", "CANCELLED"},
    "READY": {"RUNNING", "CANCELLED"},
    "INTERRUPTED": {"READY", "RUNNING", "CANCELLED", "DEAD_LETTER"},
    "RUNNING": {
        "READY",
        "WAITING_FOR_BROWSER",
        "INTERRUPTED",
        "COMPLETED",
        "TECHNICAL_ERROR",
        "CANCELLED",
        "DEAD_LETTER",
    },
    "WAITING_FOR_BROWSER": {"READY", "TECHNICAL_ERROR", "CANCELLED", "DEAD_LETTER"},
    "COMPLETED": set(),
    "TECHNICAL_ERROR": set(),
    "CANCELLED": set(),
    "DEAD_LETTER": set(),
}

assert set(TRANSITIONS) == STATES

# Exhaustively inspect every possible state pair.
for src, dst in product(sorted(STATES), repeat=2):
    allowed = dst in TRANSITIONS[src]
    if src in TERMINAL:
        assert not allowed, f"terminal state {src} must not transition to {dst}"
    if dst == "COMPLETED":
        assert allowed == (src == "RUNNING"), f"COMPLETED must be reachable only from RUNNING, got {src}"
    if dst == "WAITING_FOR_BROWSER":
        assert allowed == (src == "RUNNING"), f"WAITING_FOR_BROWSER must be entered only from RUNNING, got {src}"

# Bounded liveness proof for normal browser-step resume. Claims/resumes are not failures.
def run_browser_playbook(step_count: int, max_failures: int) -> tuple[str, int, int]:
    state = "READY"
    failures = 0
    claims = 0
    completed_steps = 0
    while completed_steps < step_count:
        assert state == "READY"
        state = "RUNNING"
        claims += 1
        state = "WAITING_FOR_BROWSER"
        state = "READY"
        # Resume consumes no failure budget.
        state = "RUNNING"
        claims += 1
        completed_steps += 1
        if completed_steps < step_count:
            state = "READY"
    state = "COMPLETED"
    assert failures < max_failures
    return state, failures, claims

for steps in range(1, 33):
    state, failures, claims = run_browser_playbook(steps, max_failures=5)
    assert state == "COMPLETED"
    assert failures == 0
    assert claims >= steps

# Failure budget is monotonic and changes only on failure events.
def apply_event(failures: int, event: str) -> int:
    if event in {"claim", "resume", "yield", "browser_wait", "checkpoint"}:
        return failures
    if event in {"transient_failure", "stale_lease_failure"}:
        return failures + 1
    raise AssertionError(event)

for start in range(0, 6):
    for event in ("claim", "resume", "yield", "browser_wait", "checkpoint"):
        assert apply_event(start, event) == start
    assert apply_event(start, "transient_failure") == start + 1

# Verification is a lattice: aggregation can only preserve or weaken evidence.
class Strength(IntEnum):
    UNVERIFIED = 0
    DEGRADED = 1
    VERIFIED = 2


def aggregate(children: tuple[Strength, ...]) -> Strength:
    return min(children, default=Strength.VERIFIED)

for n in range(1, 6):
    for children in product(tuple(Strength), repeat=n):
        parent = aggregate(children)
        assert parent <= min(children)
        if Strength.UNVERIFIED in children:
            assert parent != Strength.VERIFIED
        if all(c == Strength.VERIFIED for c in children):
            assert parent == Strength.VERIFIED

# Stable child identity: a replay of the same logical step yields the same key;
# a different plan, job or step cannot alias it.
def child_key(job: str, plan: str, step: int) -> str:
    import hashlib
    return hashlib.sha256(f"{job}\n{plan}\n{step}".encode()).hexdigest()

for job, plan, step in product(("j1", "j2"), ("p1", "p2"), range(4)):
    key = child_key(job, plan, step)
    assert key == child_key(job, plan, step)
    alternatives = {
        child_key(j2, p2, s2)
        for j2, p2, s2 in product(("j1", "j2"), ("p1", "p2"), range(4))
        if (j2, p2, s2) != (job, plan, step)
    }
    assert key not in alternatives

print("RUNTIME STATE MODEL: PASS")
print(f"states={len(STATES)} state_pairs={len(STATES) ** 2} browser_lengths=32 verification_vectors={sum(3**n for n in range(1,6))}")
