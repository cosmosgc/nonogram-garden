# 05 — Profiles, discovery, and collections

**Goal:** Make public puzzles discoverable and let creators organize and share their work without leaking private or unlisted content.

**Depends on:** Foundation ownership/visibility model and published puzzle pages from [02-editor-and-publishing.md](02-editor-and-publishing.md). Player statistics depend on [03-playing-and-progress.md](03-playing-and-progress.md).

## A. Creator profiles

- [x] Enforce unique, case-normalized usernames and define allowed characters, reserved names, and rename policy. Usernames allow 3–30 lowercase ASCII letters, digits, or underscores; reserved route/system names are rejected; owners can rename them.
- [x] Add profile edit flow for display name, username, bio, and optional avatar; validate length/type and prevent email disclosure. Avatars accept JPEG, PNG, and WebP up to 2 MB and 1024 × 1024 pixels, are decoded and re-encoded as metadata-free PNGs, and are removed on replacement/account deletion.
- [x] Add `/@{username}` profile route with paginated public puzzles and public collections only. Private/unlisted puzzles are excluded; pending public puzzles are included only while the admin-only site-wide preview toggle is enabled.
- [x] Add tests for case-insensitive route lookup, private email exclusion, hidden puzzle exclusion, and profile authorization.

**Acceptance:** Public profile shows creator-chosen public information and only public content; private account fields are never serialized.

## B. Public browse and puzzle metadata

- [x] Add paginated public puzzle listing and detail views. Approved public publications are listed by default; admins may enable site-wide preview of pending public publications. Rejected/removed, private, unlisted-pending, and draft content remains excluded. Owners retain a solution-free moderation-status page.
- [~] Implement safe filters for dimensions, creator, tags (if tags are implemented), difficulty, and recency; whitelist sortable fields and cap page size. Width, height, creator username, a validated single-tag slug, and newest/oldest filters are supported; pagination is capped at 12. Difficulty remains deferred because no shared rating definition exists.
- [x] Add an admin-only site-wide toggle for displaying pending public puzzles before approval, with visible pending labels across browse, profiles, collections, and play. The default is off; private, unlisted, draft, rejected, and removed content cannot be exposed by the toggle.
- [x] Add indexes based on actual query plans; avoid N+1 queries and add query-count/performance coverage for representative listing size. Composite indexes cover public/creator listings, collection listings, dimensions, and recent progress. Local MySQL `EXPLAIN` selects the expected indexes for browse, creator, collection, and progress aggregates; the 12-item browse query is bounded. The user reviewed performance and confirmed it looks good.
- [x] Track plays/completions with a defined idempotency and privacy policy; do not use untrusted client counters. A registered start is one persisted progress row per account and immutable revision; a completion is the server-set `completed_at`. Owners see aggregate counts for the current approved revision on their dashboard only; player identities and public counts are not exposed, and guest-local sessions are not counted.
- [~] Add tests ensuring private/unlisted/draft content is absent from public discovery surfaces. Browse, creator profiles, collections, and gameplay routes have visibility regression tests; no public related-puzzle recommendations or public aggregate counts exist yet, so add coverage if those surfaces are introduced.

**Acceptance:** Browse is paginated, filter inputs are validated, and visibility rules are consistently enforced across all query paths.

## C. Collections

- [x] Create collection and collection-puzzle migrations/models: owner, slug, title, description, visibility, ordered position, timestamps, unique pair and position index.
- [x] Define route strategy for collection slugs and uniqueness (global slug or owner-scoped slug) and implement consistently. Collection slugs are globally unique and remain stable after title edits.
- [x] Add collection policy and authenticated create/update/delete endpoints; owner-only writes and validated title/description/visibility.
- [x] Implement add/remove/reorder operations transactionally; validate every puzzle is accessible and avoid duplicate membership or duplicate positions. Collection contents are restricted to currently visible public published puzzles; pending public entries can be added only while the admin preview toggle is enabled, and positions are normalized transactionally.
- [x] Return only puzzles visible to the requesting user; do not leak private puzzles through a public collection.
- [x] Add creator collection listing and collection detail page with pagination where needed.
- [x] Add tests for authorization, ordered listing, duplicate add, reorder validation, visibility combinations, and deletion behavior. Tests cover owner-only writes, ordering/reordering/removal, invalid/non-public items, visibility, duplicate membership constraints, and pivot cascade on collection deletion.

**Acceptance / milestone:** Creator can manage a public ordered collection; users cannot use collection membership to reveal puzzles they cannot otherwise view.
