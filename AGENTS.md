# Hamnoor Plugin — Codex Instructions

## Project identity

This repository contains the WordPress plugin “Hamnoor | هم‌نور”.

Hamnoor is a Persian RTL WordPress plugin for creating and managing collective Quran recitation campaigns.

The product owner is not a programmer. Explain results clearly and avoid requiring manual code editing unless absolutely necessary.

## Core working rule

Make only the changes explicitly requested.

Do not refactor, rename, remove, redesign, optimize, or modify unrelated code unless the task specifically requires it.

Before changing code:

1. Inspect the relevant files and existing implementation.
2. Identify the actual root cause.
3. Check how the proposed change may affect existing features.
4. Prefer the smallest safe change.

After changing code:

1. Review the complete diff.
2. Check for unintended changes.
3. Validate all modified PHP files.
4. Report exactly which files were changed.
5. Report what was tested and what could not be tested.

## Existing behavior

Preserve all existing working behavior unless the task explicitly requests a change.

Important areas include:

- Khatm creation
- Share reservation
- Recitation completion
- Share release
- Public tracking
- User dashboard
- Khatm creator permissions
- Administrator controls
- Notifications
- Sharing links
- Persian and RTL interface
- Existing database records
- Existing URLs and short links

Do not change existing database schemas, option names, metadata keys, URLs, shortcodes, actions, filters, AJAX actions, REST routes, user roles, or capabilities without explicit approval.

## WordPress standards

Follow current WordPress coding and security practices.

For all submitted data:

- Verify nonce.
- Check user capabilities where required.
- Unslash input where appropriate.
- Sanitize and validate input.
- Escape output at the point of rendering.
- Use prepared SQL queries.
- Use WordPress APIs instead of direct implementations whenever practical.

Do not access WordPress files directly.

Prevent direct execution of PHP files where appropriate.

Preserve the plugin’s existing prefixes and namespaces. Do not introduce unprefixed global functions, classes, constants, hooks, options, or database tables.

## Security and privacy

Never place API keys, passwords, tokens, private URLs, personal information, or server credentials in the repository.

Never expose sensitive information in logs, HTML, JavaScript, REST responses, AJAX responses, or error messages.

Do not reduce existing nonce, permission, authentication, sanitization, validation, or escaping protections.

Do not add telemetry or send information to external services unless explicitly requested.

## Database safety

Preserve backward compatibility with existing Hamnoor installations and records.

Do not delete or rewrite existing user data.

Any database migration must:

- Be explicitly requested.
- Be versioned.
- Be repeatable without corruption.
- Preserve existing records.
- Fail safely.
- Include rollback considerations.

Never run destructive database operations without explicit approval.

## User interface

The interface is Persian and RTL.

Preserve:

- Correct RTL layout
- Mobile responsiveness
- Existing visual modes
- Compatibility with different WordPress themes
- Accessibility of buttons, labels, forms, checkboxes, and radio buttons

Do not change user-facing Persian text unless requested.

Do not change Quranic, religious, devotional, or jurisprudential content without explicit approval from the product owner.

## Compatibility

Maintain compatibility with the WordPress and PHP versions currently declared by the plugin.

Do not introduce a new framework, package, build system, external library, Composer dependency, or npm dependency unless explicitly approved.

Avoid conflicts with themes and other plugins.

Load scripts and styles only where needed.

## Testing

For every change, check the relevant scenarios.

At minimum:

- Run PHP syntax validation on every modified PHP file when PHP is available.
- Check JavaScript syntax when JavaScript is modified.
- Review the Git diff.
- Check for unintended file changes.
- State which scenarios still require testing on a real WordPress staging site.

Do not claim a test passed unless it was actually executed.

If the required environment is unavailable, clearly state that the test was not executed.

## Versioning and releases

Do not change the plugin version, changelog, release notes, or package name unless the task explicitly requests a new release.

When creating a release:

- Update all matching version references consistently.
- Preserve the correct plugin folder structure.
- Exclude Git metadata, secrets, development-only files, and previous ZIP files.
- Produce an installable ZIP package.
- Report the resulting version and package contents.

## Git discipline

Keep changes small, focused, and easy to review.

Do not commit, push, merge, reset, rebase, force-push, delete branches, or modify Git history unless explicitly requested.

Never modify or delete `.gitignore` or `AGENTS.md` without explicit approval.

## Task completion report

At the end of each task, report:

1. Root cause
2. Implemented solution
3. Files changed
4. Tests executed
5. Tests requiring human verification
6. Risks or limitations
7. Whether a release ZIP was created