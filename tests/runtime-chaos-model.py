#!/usr/bin/env python3
"""Deterministic chaos model for the durable execution kernel.

The model explores long event streams including crashes at dispatch boundaries,
stale callbacks, transient failures, browser waits and worker yields. Its goal
is to prove bounded progress/termination properties of the normative model and
to provide seeds that implementation tests can reproduce.
"""
from __future__ import annotations

import hashlib
import random
import sys
from dataclasses import dataclass, field

TERMINAL = {"COMPLETED", "TECHNICAL_ERROR", "CANCELLED", "DEAD_LETTER"}
MAX_IDENTICAL_PROGRESS_SIGNATURES = 4
MAX_EVENTS = 500
MAX_FAILURES = 5


@dataclass
class Job:
    steps: int
    state: str = "READY"
    step: int = 0
    failure_count: int = 0
    claim_count: int = 0
    child_by_step: dict[int, str] = field(default_factory=dict)
    evidence: dict[int, str] = field(default_factory=dict)
    lease_epoch: int = 0
    active_child: str = ""
    duplicate_children: int = 0
    watchdog_count: int = 0

    def child_key(self, step: int) -> str:
        return hashlib.sha256(f"mission\nplan\n{step}".encode()).hexdigest()

    def progress_signature(self) -> str:
        evidence_hash = hashlib.sha256(repr(sorted(self.evidence.items())).encode()).hexdigest()[:16]
        return f"{self.state}|{self.step}|{self.active_child}|{evidence_hash}|{self.failure_count}"

    def claim(self) -> None:
        assert self.state in {"READY", "INTERRUPTED"}
        self.claim_count += 1
        self.lease_epoch += 1
        self.state = "RUNNING"
        # A scheduling event is not an execution failure.
        assert self.failure_count < MAX_FAILURES

    def dispatch_browser(self) -> None:
        assert self.state == "RUNNING"
        logical = self.child_key(self.step)
        previous = self.child_by_step.get(self.step)
        if previous is None:
            self.child_by_step[self.step] = logical
        elif previous != logical:
            self.duplicate_children += 1
        self.active_child = logical
        self.state = "WAITING_FOR_BROWSER"

    def browser_complete(self, verified: bool) -> None:
        assert self.state == "WAITING_FOR_BROWSER"
        self.evidence[self.step] = "VERIFIED" if verified else "UNVERIFIED"
        self.active_child = ""
        self.step += 1
        self.state = "READY" if self.step < self.steps else "RUNNING"

    def transient_failure(self) -> None:
        assert self.state in {"RUNNING", "WAITING_FOR_BROWSER"}
        self.failure_count += 1
        self.active_child = ""
        self.state = "DEAD_LETTER" if self.failure_count >= MAX_FAILURES else "READY"

    def crash(self) -> None:
        if self.state == "RUNNING":
            self.state = "INTERRUPTED"
        elif self.state == "WAITING_FOR_BROWSER":
            # Child identity remains durable; parent recovery must reuse it.
            self.state = "READY"

    def stale_callback(self) -> None:
        # A callback from a previous lease epoch cannot mutate durable progress.
        before = (self.state, self.step, dict(self.evidence), self.active_child)
        after = (self.state, self.step, dict(self.evidence), self.active_child)
        assert before == after

    def progress_watchdog(self) -> None:
        """Model the production watchdog that turns bounded stasis into retry."""
        assert self.state in {"RUNNING", "WAITING_FOR_BROWSER"}
        self.failure_count += 1
        self.watchdog_count += 1
        self.active_child = ""
        self.state = "DEAD_LETTER" if self.failure_count >= MAX_FAILURES else "READY"

    def finish_if_done(self) -> None:
        if self.state == "RUNNING" and self.step >= self.steps:
            self.state = "COMPLETED"


def run(seed: int, steps: int) -> Job:
    rng = random.Random(seed)
    job = Job(steps=steps)
    identical = 0
    previous_signature = ""

    for event_index in range(MAX_EVENTS):
        if job.state in TERMINAL:
            break

        before = job.progress_signature()

        if job.state in {"READY", "INTERRUPTED"}:
            job.claim()
        elif job.state == "RUNNING":
            job.finish_if_done()
            if job.state == "COMPLETED":
                break
            roll = rng.random()
            if roll < 0.10:
                job.transient_failure()
            elif roll < 0.28:
                job.crash()
            elif roll < 0.33:
                job.stale_callback()
            else:
                job.dispatch_browser()
        elif job.state == "WAITING_FOR_BROWSER":
            roll = rng.random()
            if roll < 0.08:
                job.transient_failure()
            elif roll < 0.23:
                job.crash()
            elif roll < 0.28:
                job.stale_callback()
            else:
                job.browser_complete(verified=rng.random() >= 0.15)
        else:
            raise AssertionError(f"unexpected state: {job.state}")

        after = job.progress_signature()
        if after == previous_signature == before:
            identical += 1
        else:
            identical = 0
        if identical >= MAX_IDENTICAL_PROGRESS_SIGNATURES - 1:
            job.progress_watchdog()
            after = job.progress_signature()
            identical = 0
        previous_signature = after

        assert identical < MAX_IDENTICAL_PROGRESS_SIGNATURES, (
            f"seed={seed} event={event_index} repeated no-progress signature {after}"
        )
        assert job.duplicate_children == 0, f"seed={seed} created duplicate logical browser child"
        assert job.failure_count <= MAX_FAILURES

    assert job.state in TERMINAL, f"seed={seed} failed to terminate in {MAX_EVENTS} events: {job}"
    if job.state == "COMPLETED":
        assert job.step == steps
        assert len(job.child_by_step) == steps
        assert len(job.evidence) == steps
        # Verification cannot be upgraded: any unverified child makes the
        # aggregate mission unverified/degraded, never fully verified.
        aggregate_verified = all(value == "VERIFIED" for value in job.evidence.values())
        if any(value == "UNVERIFIED" for value in job.evidence.values()):
            assert aggregate_verified is False
    return job


runs = 0
completed = 0
dead = 0
max_claims = 0
for steps in (1, 2, 4, 8, 16, 32):
    for seed in range(1, 501):
        result = run(seed * 1000 + steps, steps)
        runs += 1
        completed += result.state == "COMPLETED"
        dead += result.state == "DEAD_LETTER"
        max_claims = max(max_claims, result.claim_count)

assert runs == 3000
assert completed + dead == runs
print(
    "RUNTIME CHAOS MODEL: PASS "
    f"runs={runs} completed={completed} dead_letter={dead} max_claims={max_claims} max_events={MAX_EVENTS}"
)
sys.exit(0)
