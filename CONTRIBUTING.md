# Contributing

Thanks for helping! This module changes live websites, so the rules are strict.

1. **Open an issue first** for anything larger than a small fix.
2. **Tests are required.** New logic goes into `lib/` (framework-free) with unit tests in `tests/`.
   A bug fix starts with a test that reproduces the bug.
3. **Run the checks** before opening a pull request:
   ```bash
   composer install
   composer lint
   composer test
   ```
4. **Manual test plan**: changes to the panel module, server plugin, worker or installer must be
   tried on a disposable ISPConfig VM following [docs/testing.md](docs/testing.md).
   Say in the pull request which ISPConfig version and OS you tested.
5. **Safety rules** in [docs/architecture.md](docs/architecture.md) are not negotiable: no shell strings,
   no secrets in arguments or logs, never touch a non-empty web root, dry-run must stay the default.
6. Each PHP file starts with `// SPDX-License-Identifier: BSD-3-Clause`.

By contributing you agree that your contribution is licensed under the project license (BSD 3-Clause).
