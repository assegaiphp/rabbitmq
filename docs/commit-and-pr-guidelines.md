# Commit And PR Guidelines

Use this format for `assegaiphp/rabbitmq` commits and pull requests.

## Commits

Prefer small, single-purpose commits:

```text
type(scope): short summary
```

Examples:

```text
fix(rabbitmq): acknowledge failed jobs safely
feature(rabbitmq): expose connection heartbeat options
test(rabbitmq): cover queue publish payload options
docs(rabbitmq): clarify amqp extension requirements
```

Recommended types:

- `fix`
- `feature`
- `docs`
- `test`
- `refactor`
- `chore`

If the summary needs "and" to describe unrelated work, split the commit.

## Pull Requests

Every PR should clearly answer:

1. which milestone it belongs to
2. why it belongs there
3. what changed
4. what did not change
5. how it was verified

Use the repository PR template and keep the body concise.

## Verification

The default check for this repo is:

- `composer test`

Add any extra manual verification that matters for the changed behavior.

## Release Notes

Use:

- `Release notes: Not needed` for internal refactors, tests, and narrow fixes
- `Release notes: Needed` for user-visible behavior changes
- `Upgrade notes: Needed` when users may need to change code, config, workflow, or expectations
