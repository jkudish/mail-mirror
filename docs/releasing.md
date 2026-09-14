# Release MailMirror

MailMirror has not published its first tag. A release changes shared state and
requires explicit authorization for each applicable action: repository
visibility, tag and GitHub release publication, and Packagist publication.

## Prepare the release

1. Choose a Semantic Versioning number and update `CHANGELOG.md`.
2. Confirm the README installation command and supported PHP and Laravel
   versions match `composer.json`.
3. Document every migration, deprecation, contract change, and required consumer
   action in the changelog.
4. Check every Markdown link and heading anchor.
5. Review all reachable commits, tags, and remote branches for credentials,
   private names, mailbox data, provider responses, and other material that
   must not become public.
6. Confirm fixtures remain synthetic and use reserved `.test` domains.
7. Build the Composer archive and inspect its file list and contents.
8. Run `composer pr:check` on the clean release commit.
9. Obtain exact-SHA approval and signoff before merge.

Changing the repository to public exposes its full reachable history, not only
the current files. Rewrite or remove unsafe history before changing visibility.

## Verify the archive

Build the package from the release commit:

```bash
composer archive --format=zip --dir=/tmp/mail-mirror-release
unzip -l /tmp/mail-mirror-release/*.zip
```

The archive must include runtime source, migrations, configuration, the README,
license, changelog, security policy, and consumer documentation. It must not
include `.env` files, credentials, `vendor/`, test output, local databases, or
repository-only agent configuration.

Extract the archive into a temporary directory and inspect it before
publication. Delete the temporary archive when finished.

## Publish a version

After approval, create an annotated tag from the exact signed-off commit:

```bash
version=v0.1.0
git tag -a "$version" -m "MailMirror $version"
git show --no-patch "$version"
```

Pushing the tag and creating a GitHub release are separate authorized actions.
Use the matching changelog entry for release notes, then read the tag and
release back from GitHub.

After the repository is public and the release exists, publish
`jkudish/mail-mirror` on Packagist. Confirm Packagist resolves the immutable tag
and package metadata.

## Test the published package

Install the release from Packagist in clean Laravel 12 and 13 applications:

```bash
composer require "jkudish/mail-mirror:^0.1"
php artisan migrate
```

Run the synthetic consumer import against both applications. Confirm the
installed version and source reference match the release tag. Do not enable a
provider or use real mail for release verification.

If publication is wrong, stop and document the mismatch. Do not move an
existing tag. Fix the source, verify a new commit, and publish a new version.
