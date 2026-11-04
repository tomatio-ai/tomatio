# Contributing to TomaTío

Thank you for helping. This repository is the public release mirror of TomaTío: every release publishes a fresh snapshot of the plugin source here, about once a week. Day-to-day development, tests and CI happen in the project's own repository, and the maintainers carry accepted changes between the two.

## Report a bug or suggest an idea

Open an issue here and pick **Bug report** or **Idea**. Say which TomaTío, WordPress and PHP versions you run and, for a bug, the steps that show it. A maintainer reads every issue; when one is accepted, it is tracked in the main repository and this issue gets a note when the fix ships.

Never report a security problem in a public issue. See [SECURITY.md](SECURITY.md).

## Send a change

Pull requests are welcome.

1. Open an issue first for anything bigger than a small fix, so we can agree on the approach before you spend time on it.
2. Branch from `main`, keep the change focused, and explain what it fixes and how you checked it.
3. A maintainer reviews it here. When it is accepted, they bring it into the main repository as a pull request with you credited as co-author, where the full test suite runs. Your change then appears here with the next release, and this pull request is closed with a link to it.

The test suite and the lint configuration live in the main repository, so you cannot run them from this mirror; the maintainers run them on your change.

## Build from source

You need PHP 8.3 or newer, Composer and Node.js.

```bash
composer install --no-dev
npm ci
npm run build
```

To install TomaTío on a site, use the release zip from [Releases](https://github.com/tomatio-ai/tomatio/releases) instead: it already contains the libraries and built files.

## Licence

TomaTío is free software under the GPL, version 2 or later (see [LICENSE](LICENSE)). By sending a change you agree that it is released under the same licence.
