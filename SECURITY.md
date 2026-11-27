# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 4.x | yes |

## Reporting a vulnerability

Report it privately through GitHub's
[security advisories](https://github.com/KaririCode-PHP/router/security/advisories/new),
or by e-mail to walmir.silva@kariricode.org. Please do not open a public
issue.

## Scope

In scope:

- a path that matches a route it should not, or a route that a crafted path
  can make unreachable (for example through the compiled PCRE expressions);
- control characters, a trailing newline or invalid UTF-8 reaching a handler
  through a built-in parameter type;
- client data (method, path, parameter values) appearing in an exception
  message or in what `MatchObserver` receives;
- a way to make `CachedMatcher` serve a table built for other routes, or
  `PhpFileStore` include a file it did not write.

Out of scope, by design:

- `RouteEntry::$host` and `$scheme`: they are carried to the dispatcher and
  not matched (ADR-005), so they are not an access-control mechanism;
- the path given to `PhpFileStore` or `ArrayRouteLoader::loadFile()`: both
  execute PHP from it, and it must come from the application, never from a
  request;
- handlers and middleware: the router returns their definitions and never
  runs them.
