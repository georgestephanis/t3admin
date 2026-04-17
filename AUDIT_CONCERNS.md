# t3admin Security and Functionality Concerns Register

Date: 2026-04-17
Scope: Temporary Titan Token plugin audit
Status: Open concerns identified during code review and lint/runtime checks

## How to Refer to Concerns

Use the concern ID in future requests, for example:

- Address C-01 with a minimal patch
- Propose tests for C-03
- Accept risk for C-05 and document rationale

## Concern List

1. C-01 (High) Multisite storage model can break cross-site grants

- Category: Functionality and security boundary correctness
- Summary: The plugin is network-enabled, but grant records are stored in per-site options. Cross-site targeting in multisite can create grants that are active in storage but not enforced where expected.
- Why it matters: A valid grant may be superseded by a replacement grant that is effectively inert, resulting in incorrect privilege behavior and operator confusion.
- Evidence:
  - Network-enabled header in t3admin.php line 6
  - Option storage read and write in includes/class-grants.php lines 203, 234, 260, 278
  - Cross-site selector in includes/class-admin.php lines 313-333
  - Blog-scoped capability enforcement check in includes/class-grants.php lines 100-104
  - Supersede behavior in includes/class-grants.php lines 178-180
- Suggested direction:
  - Use network option storage for grants in multisite, or
  - Remove cross-site targeting and scope strictly to current site

2. C-02 (Medium) Log file protection depends on Apache-only behavior

- Category: Security hardening
- Summary: Log exposure protection is currently .htaccess plus index.php in uploads.
- Why it matters: On Nginx or IIS without equivalent rules, audit logs may be web-accessible.
- Evidence:
  - .htaccess and index.php creation in includes/class-logger.php lines 56-65
  - Sensitive log fields in includes/class-logger.php lines 79-95
- Suggested direction:
  - Move logs outside web root where possible, or
  - Add server-specific deny guidance and compatibility files (for example web.config)

3. C-03 (Medium) Log viewer does full-file reads and may not scale

- Category: Performance and reliability
- Summary: The log reader loads the full JSONL file into memory, reverses it, then slices.
- Why it matters: Large log files can produce slow admin responses and memory pressure.
- Evidence:
  - Full read using file() in includes/class-logger.php line 119
  - Reverse and slice strategy in includes/class-logger.php lines 127-128
- Suggested direction:
  - Add rotation or retention limits
  - Replace full-file read with tail-style paged reads

4. C-04 (Low) Inconsistent wp_unslash usage for POST input

- Category: Input handling hygiene
- Summary: Several POST fields are sanitized without wp_unslash first.
- Why it matters: Mostly correctness and standards consistency risk; can cause subtle parsing issues.
- Evidence:
  - Direct POST reads in includes/class-admin.php lines 541-543, 572-573, 589
- Suggested direction:
  - Apply wp_unslash before sanitization for all POST values

5. C-05 (Low) Admin grant form loads all users without pagination or search

- Category: Scalability and UX
- Summary: The grant form fetches all users at once.
- Why it matters: Large sites may experience slow admin page loads and poor usability.
- Evidence:
  - Unbounded query using number => -1 in includes/class-admin.php line 250
- Suggested direction:
  - Use searchable async user picker or paged lookup

6. C-06 (Low) Coding standards drift detected by PHPCS

- Category: Maintainability and process health
- Summary: Yoda condition violations were reported.
- Why it matters: Not directly exploitable, but weakens code consistency in security-sensitive code paths.
- Evidence:
  - includes/class-admin.php line 166
  - includes/class-grants.php lines 102 and 183
- Suggested direction:
  - Apply style fixes and keep PHPCS passing in CI or pre-commit workflow

## Validation Context

Checks performed during audit:

- Source review of plugin bootstrap and all classes under includes
- PHPCS run with vendor binary
- WP-CLI smoke checks for active plugin and class availability

## Suggested Triage Order

1. C-01
2. C-02
3. C-03
4. C-04
5. C-05
6. C-06
