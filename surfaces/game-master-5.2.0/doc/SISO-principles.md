# SISO Principles — core
## What any SISO core must be, and what every core concedes

**Version:** 2.0. It supersedes 1.3.
**Scope:** the core, independent of any substrate. Each implementation (the Lua reference, sisoc, a hardware build) is an *instance*. It keeps its own spec and its own ledger of concessions. See *Instances*.

This version is numbered 2.0 instead of 1.4 for two reasons. The text sisoc works from is already titled "v1.4 DRAFT". And this version adds the concession ledger, which changes how the whole document is read.

**The documents:**

| Document | Says | Changes when |
|---|---|---|
| These principles | What any core must be. | The ontology or the core's design changes. |
| An instance's spec | What one implementation is: its interface, names, errors, tests, and its own concessions. | The implementation changes. |
| An instance's conformance audit | How each item here is held in that implementation, with evidence. | Either of the above changes. |

The reference core is the Lua implementation. Its spec is `doc/SPEC.md`, and its audit is `doc/conformance.md`. Implementations in other languages are ported from it.

---

## What SISO is

SISO (Stream In, Stream Out) computes with three primitives: Event, Gate and Stream. The flow it comes from has no direction, no edges and no outside. A machine that answers questions needs all three. These principles specify the core, the thinnest layer that supplies them, and they name what that layer concedes.

**Each concession is named, has an explicit purpose, and is given in its thinnest form. Anything not conceded is forbidden.**

Shims can get thinner. They should not widen.

The properties that follow from the rules (Polyglot, Parallel-safe, Stateless, Without side effects, Auditable) are consequences, not features. If one is missing, a rule is broken or a concession is unnamed.

---

## How rules are held

Each rule states the strength with which an instance must hold it:

- **Architecture required.** The instance must have no mechanism through which the rule can be broken, and a test must pin that.
- **Architecture, or a recorded convention.** The instance holds the rule by architecture where its substrate allows. Where the substrate can express the violation and the instance cannot prevent it, the rule binds the author, and the instance records that gap in its ledger as a *substrate* concession. An unrecorded convention is an unnamed concession (Drift 8).

---

## The three primitives

SISO has exactly three kinds of thing. No fourth.

1. **Event.** An immutable datum: a type and data. The type is its routing key. The data is what its target gate requires. Nothing else is on it, and delivery never adds anything to it. It is created and never modified.
2. **Gate.** A pure function from one event to zero, one or many events. It has no state, no side effects, and no awareness of anything beyond the event it receives.
3. **Stream.** The place where events propagate. It delivers each event to the gate whose signature matches its type, carries what the gate returns, and continues until nothing more can be delivered. Events that no gate claims settle as **residue**. The stream holds the present: the residue, the registered gates, and nothing else a gate could read.

There are no variables, no records, and nothing with identity that a gate can read or change. **The core has no refs.** State that outlives a propagation lives as residue (Rule 4).

If an implementation needs a fourth primitive in order to function, it is not SISO. The usual drift is inventing one to store state the stream should carry, or history the log should handle.

---

## The outside

The flow denies an exterior. The core has one, and it is as small as it can be.

**Four doors are open while the stream runs:**

| Door | What crosses |
|---|---|
| **Entry** | An event enters, and one propagation runs until it settles. |
| **Take** | The residue leaves, and the stream no longer holds it. This is the only way an answer leaves. |
| **Sample** | The present is read. Nothing is removed, and nothing done to the sample reaches the stream. |
| **Observe** | An observer is attached or detached. It is shown what passes. Attaching or removing it changes nothing. |

**One exit is not an answer.** A rejected propagation (Rule 3) leaves through the entry door as a rejection. The rejection carries the reason and the identities of the deliveries involved. It commits nothing, and it is never residue.

**One door builds the stream: registration.** The outside chooses the gates. Registering runs nothing. Each signature is unique, and a duplicate is refused.

Nothing else crosses. The outside cannot call a gate, order gates, or touch a propagation in progress. *Architecture required.*

Files, networks, clocks, users, retries and persistence are concessions of a **boundary layer above the core**. They are recorded in that layer's own ledger, never here.

---

## The log — not a primitive

A log is an observer. It records what passed through the stream for debugging, verification and analysis after the fact. The stream shows it what the stream already knows (delivery identity, cause, type, which gate claimed it, and optionally the data) and reads nothing back.

The observer may store what it is shown. The stream never stores history.

A core in which the log is consulted by a gate, a sample or any running part is not SISO. The log is downstream of processing, never inside it. *Architecture required.*

---

## The rules

### 1. Gates are pure and events are locally sufficient

A gate receives one event and returns zero, one or many events. That is its whole behaviour.

- A gate has no state: nothing it keeps changes between calls.
- A gate queries nothing outside the event it received: not the stream, not other gates, not the log, not residue.
- A gate has no side effects. Its result is its return value.
- A gate does not throw. A failure the gate can recognise is part of its one effect, and the gate returns it as an event (an error type), which settles as residue like any answer. A throw is an effect in the strict sense: an exit that is not the return value. So a gate that throws, by design or by bug, is treated as a rejected propagation (Rule 3, K5).

**Why `transform(event)`, not `transform(event, stream)`.** The first design passed the stream to the gate. The second passed only a callback, `transform(event, emit)`. The current design is `transform(event)` with return-based propagation. Both earlier designs left a way to reach outside. The narrowing removed the means to commit the impurity, not just the encouragement. A gate that can only return data cannot reach the stream.

**Events carry what their gate requires to fire, nothing more.** Events are sized to their gate, not loaded "just in case" for what might happen downstream. Downstream gates receive downstream events, and those carry what they require.

*Held:*
- **Architecture required:** a gate receives the event and nothing else (no handle to the stream or anything in it), and delivery adds nothing to an event.
- **Architecture, or a recorded convention:** statelessness, absence of side effects (including throwing), immutability of the event a gate receives, and event sizing.

### 2. Single effect

A gate performs one logical transformation. Its output may be zero, one or many events, so fan-out is legal. Two independent effects from one input are not legal: a gate doing both A and B, where A and B have no causal relationship through the input event. Split them into two gates, each triggered by its own event type.

*Held:* architecture, or a recorded convention. It binds gate authors.

### 3. No ordering: causality is carried by events

Gates have no defined execution order. The relative order in which active events are processed is not part of the semantics. An instance that needs registration order, dispatch order, insertion order or any scheduled sequence to produce correct output breaks this rule.

Causality travels on events. If gate B needs the effect of gate A, then A returns an event that carries what B needs, and B fires on it. B is not "scheduled after" A; B is *caused by* A's output.

**Causality is acyclic.** A causal chain must not revisit an event type. A causal chain is an event, the events its gate returns, the events their gates return, and so on. Gates are keyed by type, so a repeated type is a repeated gate, and the propagation would never settle.

Acyclicity is what makes a propagation finite: chain length is bounded by the number of registered gates. Fan-out branches are independent chains. The same type may appear in sibling branches, never twice on one path.

**Acyclicity is enforced, not decreed.** A gate's author cannot know every path its outputs may take, and in general no one can tell whether a process will finish. So the stream rejects:

- **a self-loop:** a gate that returns its own type;
- **any other cycle** (A → B → A, of any length). A legal chain holds at most one claimed event per registered gate, so a claimed event deeper than the gate count proves that a type repeated.

A rejected propagation, for this reason or because a gate threw, commits no residue and leaves through the rejection exit (K5).

*Held:* architecture required.

**Retry is a boundary concern.** A pure gate's output depends only on its event, so repeating the same work inside the core can only repeat the same result. Transient failure, polling and backoff all involve the world. A gate that wants another attempt returns an event of a different type. That event settles as residue, and the boundary decides whether to enter it again.

**Propagations.** An entry runs one propagation until it settles. In the core, propagations share nothing a gate can read: there are no refs, and residue is invisible to gates. So their sequence carries no meaning, and running them interleaved would produce the same residue. An instance that adds shared state between propagations loses this guarantee and must concede ordering in its own ledger.

### 4. The stream is the present

Current state lives in the stream, as residue. It is not stored separately. Present-tense questions are answered by sampling the stream. Reconstructing the present by scanning a log breaks this rule.

#### Residue

Residue is what the stream holds when a propagation settles: the events no gate claimed. It is the answer.

- **Taking is the only way an answer leaves.** A rejection leaves through the rejection exit, and a rejection is not an answer.
- **Residue is a multiset.** Its order is not part of the answer.
- **Residue persists until taken**, across propagations. This is where long-lived state lives in the core. To carry something forward, such as a balance or a counter, the outside takes it and enters it again as part of the next question. Every change of state is therefore a visible crossing of a door. Residue is never visible to gates.

*Held:* architecture required.

#### Sampling

Sampling reads the present. Its three invariants:

- **Local.** A sample reads what the stream holds now. It does not rebuild state from history, scan a log, or query a store.
- **Non-destructive.** The stream is identical before and after a sample. A sample is a copy or a read-only view, and nothing done to it reaches the stream.
- **Proportional to the present.** The cost of a sample may grow with the residue it reads. It never grows with history.

*Held:* architecture required for the act of sampling. Read-only samples: architecture, or a recorded convention.

### 5. There is no orchestration

The core has no orchestration layer: no tick loop walking registered enrichers, no pre-phase that assembles fat events, no dispatch sequence imposing structure the events do not carry. If something needs an orchestrator, its gates are incomplete (they should fire on events they are currently handed), its events are incomplete (they should carry what orchestration now supplies), or both.

---

## Ticks are bookkeeping, not structure

A tick names the moment "propagation has settled; the next input can arrive." Ticks are useful for batching, rendering, stepping and debugging. They are not part of the specification.

A tick does not cause events to fire, order gates, or assemble context. If removing ticks changes output, the ticks have become orchestration, and Rule 5 is broken.

Signs that ticks have become load-bearing:
- gates that check which tick they are in;
- events that carry a tick number for routing or ordering;
- logic that waits for "all gates to have run this tick";
- a tick loop that calls gates in sequence.

---

## Properties that follow from the rules

- **Polyglot.** Events are the only contract, so they cross languages, services and runtimes without translation. *Condition:* event data is plain data.
- **Parallel-safe.** Gates share nothing and have no order. Sibling branches, and separate propagations, may run concurrently and produce the same residue. An instance may permit parallelism without exhibiting it.
- **Stateless.** Nothing a gate can read persists between calls. A propagation's outcome depends only on its entry event and the registered gates.
- **Without side effects.** The only result of a propagation is its residue, or its rejection. Whatever the instance keeps for its own purposes, such as ids or an observer's record, is visible to no gate and is listed in its ledger.
- **Auditable.** Given the same gates, the same entry produces the same trajectory and the same residue, rejections included. Recording is optional; reproducibility follows from the structure.

---

## Concessions of the core

These hold for every SISO core on any substrate. An instance's own concessions are in its spec.

Each entry has:
- a **name**;
- a **cause**:
  - **Ontological**: any computation needs it (direction, edges, an outside). It is permanent.
  - **Design**: a choice of the core's design, with a named condition that retires it.
- a **purpose**;
- its **thinnest form**.

| ID | Concession | Cause | Purpose | Thinnest form |
|---|---|---|---|---|
| **K1** | **Delivery order.** Events are delivered in some sequence. | Ontological: direction | The machine takes steps, and a question must come before its answer. | The order carries no meaning. Gates cannot observe it, and residue is a multiset. An instance may pin its own order for testing, which pins the implementation, not the semantics. On a substrate that propagates physically in parallel, nothing depends on it. |
| **K2** | **Types and the gate table.** Events carry a type. Gates are registered by unique signature, and the table persists across propagations. | Ontological: edges | A problem has edges. The registered gates say what this stream can answer, and everything else settles. | One gate per type. Direct lookup. A duplicate is refused. The table is used only for routing and for the acyclicity bound. Gates never see it. The table cannot learn: growing it is deferred to the self-learning work. |
| **K3** | **The doors.** Entry, take, sample and observe while running, plus registration to build. | Ontological: outside | A question needs someone outside to ask it and read the answer. | Nothing else crosses. The outside cannot call a gate, order gates, or reach into a propagation. |
| **K4** | **Delivery metadata.** Each delivery carries its cause and its depth on the causal chain, alongside the event. | Ontological: direction (a question must finish) | Causality as data, and enforced acyclicity. | Two values, derived from the parent delivery. Never on the event, never visible to a gate, gone when the delivery is processed. The depth bound is read from the present (the gate count), not configured. *Rejected alternative:* carrying the set of types on the path. It catches a cycle at once and names it, but grows with depth and makes the chain carry its own history. |
| **K5** | **Rejection.** A self-loop, a cycle or a throwing gate rejects the whole propagation. Rejection is an exit, the one way out that is not taking residue. | Ontological: direction | A design error must not settle as residue that looks like an answer. | The propagation commits no residue. Because gates are pure, the same entry is rejected the same way every time. The rejection carries the reason and the identities involved, and nothing else. It is not a side effect on the present, because residue is unchanged. *Cost:* a cycle may be detected up to one lap late, and the rejection alone may not name the loop (an attached observer can). |
| **K6** | **Strays settle as residue.** An event whose type has no gate, including a mistyped one, is residue. Nothing marks it as different from an answer. | Design | A thin core with no declared exits. | An observer can see it was unclaimed. *Retired when* declared exits and pending events arrive together with gate growth. |

### Retired

The ledger also records what was removed, so that thinning can be seen over time:

- **`transform(event, stream)` → `transform(event, emit)` → `transform(event)`.** Each step removed a way for the gate to reach outside.
- **Retry in the core.** The bound was written by the gate onto the event. A gate that never advanced it looped forever, so the safety net was not one. Moving the count into the stream would have repaired the net but kept a mechanism the core has no honest use for. Retry left the core, and acyclicity became strict and enforced.
- **Pending → residue.** Residue is the answer, not something waiting.
- **Sampling in O(1).** Replaced by "proportional to the present".

---

## Common drift patterns

Audit every instance against each pattern before claiming compliance.

**Drift 1: the log becomes consulted.** A recording starts being queried to answer present-tense questions. It is correct, so the pattern spreads, until the system cannot run without its log. This breaks Rule 4 and the log's status. *Fix:* sample the stream.

**Drift 2: registration order becomes scheduling.** Two gates cooperate because of the order they were registered in. No event carries the dependency. This breaks Rule 3. *Fix:* A returns an event that B fires on.

**Drift 3: an orchestration layer accumulates.** "Enrichers" or "pre-processors" assemble fat events, and gates become passive. This breaks Rule 5. *Fix:* each enricher is either a gate or a sign that events should carry more.

**Drift 4: gates reach outside their event.** A gate imports module globals, calls back into the stream, or reads some other structure. This breaks Rule 1. *Fix:* put the context on the event.

**Drift 5: events accumulate speculative payload.** Events carry context their gate does not need, in case a later gate might. This breaks Rule 1's sizing clause. *Fix:* trim each event to its gate.

**Drift 6: a fourth primitive appears.** "State", "context", "cache", "scheduler", "world." *Audit:* identify which rule the new thing is patching, and fix that rule's violation instead of accepting the primitive.

**Drift 7: guard-rails.** The runtime "protects" the system by dropping, truncating or repairing what a gate returned. The gate's return is no longer what the stream carries, and the trajectory no longer reflects the gate. *Fix:* reject or carry. Never silently repair.

**Drift 8: the unnamed concession.** Each small practical fix is correct in the case at hand, and none is written down: a counter here, an order there, a flag that persists. The signs are:
- a named value that persists and is overwritten;
- a fourth kind of thing;
- an order imposed instead of carried by events;
- a history consulted to learn the present.

*Fix:* remove the concession, or record it in the ledger it belongs to (the core's, or the instance's). Give it a name and a purpose, and show that it is the thinnest form.

---

## Compliance checklist

An instance complies when every item holds. Its conformance audit gives the evidence for each item. An item that cannot be shown is reported as *not verified*, never ticked.

| # | Item | Required strength |
|---|---|---|
| 1 | Exactly three primitives. Everything else is in a ledger. | Inspection |
| 2 | A gate receives one event and nothing else, and returns events. | Architecture |
| 3 | No gate has state or side effects (throwing included). | Architecture, or a recorded convention |
| 4 | Every gate has a single logical effect. | Architecture, or a recorded convention |
| 5 | An event is a type and data, carries what its gate requires, and is never annotated by delivery. | Architecture for shape and annotation; architecture or a recorded convention for sizing |
| 6 | No gate depends on execution order. | Architecture |
| 7 | No causal chain revisits a type; self-loops and cycles are rejected. | Architecture |
| 8 | A rejected propagation commits no residue and leaves only as a rejection through the entry door. | Architecture |
| 9 | Present-tense questions are answered by sampling, never from history. | Inspection |
| 10 | Sampling is local, non-destructive and proportional to the present, and nothing done to a sample reaches the stream. | Architecture; architecture or a recorded convention for read-only samples |
| 11 | Answers leave only by being taken. | Architecture |
| 12 | Attaching or removing every observer leaves behaviour unchanged. | Architecture |
| 13 | No orchestration. | Inspection |
| 14 | Ticks, if present, are bookkeeping only. | Inspection |
| 15 | Every mechanism outside the primitives is in the core's ledger or the instance's, in its thinnest form or marked as not yet, or is a recorded departure. | Inspection |

---

## Instances

An instance is an implementation of the core: a Lua library (the reference), a port to another language, a compiler and its runtime, a hardware build. Each instance keeps:

- **a spec**: its interface and names, and **its own ledger**. That ledger holds concessions caused by its substrate (with a "vanishes when") or by the instance itself (to be retired). It also holds *recorded departures*: rules the instance does not yet meet, each with a fix.
- **a conformance audit** against this checklist.

An instance in a language the reference is not written in passes the reference's language-agnostic fixture suite.

The layering:

- **The core does not change to accommodate an instance.**
- **Every entry in an instance's ledger cites the rule or concession here that it bends.** A departure that cites nothing is an unnamed concession (Drift 8).
- **Most of an instance's concessions are expected to be substrate concessions**: they translate the flow onto hardware that is not the flow, and should vanish with that hardware. The four running doors do not vanish (K3).

*The sisoc addendum.* The document titled "SISO Principles — v1.4 DRAFT" is sisoc's addendum, not a revision of this text. What it holds is sisoc's to record and justify, including refs and retry inside the stream.

---

## Changes from 1.3

| Section | Change |
|---|---|
| Title and scope | The core, independent of substrate. Implementations are instances with their own spec, ledger and audit. Numbered 2.0, because the sisoc draft holds "1.4". |
| How rules are held | New: "architecture required" vs "architecture, or a recorded convention". |
| Primitives | An event is a type and data, exactly. The core has no refs. Residue is named in the Stream primitive. |
| The outside | New: four running doors, the rejection exit, and the building door. Boundary concerns are excluded by name. |
| The log | The observer may store; the stream never does. |
| Rule 1 | Gates do not throw. A failure is returned as an event, and a throw is treated as rejection. |
| Rule 3 | Rejection and the *Propagations* paragraph. Retry is a boundary concern. |
| Rule 4 | Residue is a multiset, persists until taken, and is where long-lived state lives. Sampling is proportional to the present, and samples are read-only. |
| Properties | Parallel-safe instead of "perfectly parallel"; Without side effects added; Auditable stated as a function of entry and gates. |
| Concessions | New: the core's ledger (K1–K6) and the retired list. |
| Drifts | New: Drift 7 (guard-rails) and Drift 8 (the unnamed concession). |
| Checklist | Each item states its required strength. Unverifiable items are reported as not verified. New items 8 and 15. |
| Instances | New: each instance's spec carries its own ledger and cites the core rule it bends. An instance in another language than the reference passes the reference's fixture suite. |

---

## Scope

These principles specify the primitives, the rules and the core's concessions. They do not prescribe language, interface names, serialization, log format, error representation or performance targets beyond what the rules require. Those belong in each instance's spec. Instances declare which version of this document they comply with.
