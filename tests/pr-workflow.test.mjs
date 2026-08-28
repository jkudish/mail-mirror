import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, test } from 'node:test';

import { CHECK_PLAN_ID, CHECK_STEPS, runCheck, sanitizedEnvironment, signoff } from '../scripts/pr-workflow.mjs';

const SHA = '1111111111111111111111111111111111111111';
const OTHER_SHA = '2222222222222222222222222222222222222222';
const BASE_SHA = '3333333333333333333333333333333333333333';
const SIGNOFF_ENV = { GH_SIGNOFF_TOKEN: 'signoff', GH_TOKEN: 'ambient' };
const temporaryDirectories = [];

afterEach(() => {
    for (const directory of temporaryDirectories.splice(0)) rmSync(directory, { force: true, recursive: true });
});

function receiptPath() {
    const directory = mkdtempSync(join(tmpdir(), 'mail-mirror-pr-workflow-'));
    temporaryDirectories.push(directory);
    return join(directory, `${SHA}.json`);
}

function success(stdout = '') { return { status: 0, stdout, stderr: '' }; }

function runner({
    extension = 'gh signoff\tbasecamp/gh-signoff\tv1.0.0', heads = [SHA], localBase = BASE_SHA,
    pullRequest = { headRefOid: SHA, state: 'OPEN' }, remoteBase = BASE_SHA, path = receiptPath(), worktree = '',
} = {}) {
    const calls = [];
    let headIndex = 0;
    const run = (command, args, options = {}) => {
        calls.push({ command, args, options });
        if (command === 'git' && args[0] === 'rev-parse' && args[1] === 'HEAD') return success(`${heads[Math.min(headIndex++, heads.length - 1)]}\n`);
        if (command === 'git' && args[0] === 'rev-parse' && args[1] === '--verify') return success(`${localBase}\n`);
        if (command === 'git' && args[0] === 'ls-remote') return success(`${remoteBase}\trefs/heads/main\n`);
        if (command === 'git' && args[0] === 'status') return success(worktree);
        if (command === 'git' && args[0] === 'rev-parse' && args[1] === '--git-path') return success(`${path}\n`);
        if (command === 'gh' && args[0] === 'extension') return success(`${extension}\n`);
        if (command === 'gh' && args[0] === 'pr') return success(JSON.stringify(pullRequest));
        if (command === 'php' && args[0] === '-r') return success('8.5.9');
        if (command === 'composer' && args[0] === '--version') return success('Composer version 2.10.1 2026-06-04');
        if (command === 'node' && args[0] === '--version') return success('v24.19.0');
        return success();
    };
    return { calls, path, run };
}

function writeReceipt(path, overrides = {}) {
    writeFileSync(path, JSON.stringify({
        version: 1, sha: SHA, planId: CHECK_PLAN_ID, success: true, completedAt: '2026-08-28T00:00:00.000Z',
        runtime: { actual: { platform: 'linux', architecture: 'x64', php: '8.5.9', composer: '2.10.1', node: '24.19.0' } },
        ...overrides,
    }));
}

test('allowlists a credential-free verification environment', () => {
    assert.deepEqual(sanitizedEnvironment({ PATH: '/usr/bin', HOME: '/home/user', GH_TOKEN: 'secret', COMPOSER_AUTH: 'secret' }, '/tmp/isolated'), {
        PATH: '/usr/bin', HOME: '/tmp/isolated', PR_CHECK_HOST_HOME: '/home/user', CI: '1', COMPOSER_NO_INTERACTION: '1',
    });
});

test('owns stable, lowest, Laravel 12/13, quality, setup, and clean-diff checks', () => {
    assert.deepEqual(CHECK_STEPS.map(({ command, args }) => [command, args]), [
        ['composer', ['install', '--no-interaction', '--prefer-dist', '--no-progress']],
        ['composer', ['validate', '--strict']],
        ['composer', ['check-platform-reqs']],
        ['composer', ['audit', '--locked', '--no-interaction']],
        ['php', ['vendor/bin/pint', '--test']],
        ['php', ['vendor/bin/phpstan', 'analyse', '--memory-limit=1G']],
        ['bash', ['scripts/test-laravel-13-suite.sh']],
        ['bash', ['scripts/test-prefer-lowest.sh']],
        ['bash', ['scripts/test-consumer-install.sh', '12']],
        ['node', ['--test', 'tests/pr-workflow.test.mjs']],
        ['bash', ['-n', '.agents/setup', '.agents/resume', 'scripts/test-laravel-13-suite.sh', 'scripts/test-prefer-lowest.sh', 'scripts/test-consumer-install.sh']],
        ['git', ['diff', '--exit-code']],
    ]);
});

test('guards setup and least-privilege signoff foundation configuration', () => {
    const setup = readFileSync(new URL('../.agents/setup', import.meta.url), 'utf8');
    const guidance = readFileSync(new URL('../.agents/skills/verifying-pull-requests/references/setup.md', import.meta.url), 'utf8');
    assert.match(setup, /composer\.github\.io\/installer\.sig/);
    assert.match(setup, /hash_file\('sha384'/);
    assert.match(setup, /02e0cf9c/);
    assert.match(setup, /gh extension install basecamp\/gh-signoff --pin v0\.4\.1/);
    assert.match(guidance, /Pull requests read and Commit\s+statuses read\/write/);
    assert.match(guidance, /Contents access is not needed/);
});

test('writes a mode-0600 exact-SHA receipt after all checks pass without credentials', () => {
    const mock = runner();
    const result = runCheck({ environment: { PATH: '/usr/bin', GH_TOKEN: 'secret' }, now: () => new Date('2026-08-28T00:00:00Z'), run: mock.run });
    assert.equal(result.path, mock.path);
    assert.equal(JSON.parse(readFileSync(mock.path, 'utf8')).sha, SHA);
    assert.equal(statSync(mock.path).mode & 0o777, 0o600);
    const checks = mock.calls.filter(({ options }) => options.stdio === 'inherit');
    assert.deepEqual(checks.map(({ command, args }) => [command, args]), CHECK_STEPS.map(({ command, args }) => [command, args]));
    assert.ok(checks.every(({ options }) => options.env.GH_TOKEN === undefined));
});

test('verification fails closed for dirty state, stale origin/main, or changing HEAD', () => {
    assert.throws(() => runCheck({ run: runner({ worktree: ' M composer.json\n' }).run }), /worktree must be clean/i);
    assert.throws(() => runCheck({ run: runner({ remoteBase: OTHER_SHA }).run }), /origin\/main is stale/i);
    const changed = runner({ heads: [SHA, OTHER_SHA] });
    assert.throws(() => runCheck({ run: changed.run }), /HEAD changed/i);
    assert.throws(() => readFileSync(changed.path));
});

test('signoff requires exact approval and current receipt before GitHub access', () => {
    const mock = runner();
    assert.throws(() => signoff({ approvedSha: 'HEAD', run: mock.run }), /full 40-character/i);
    assert.throws(() => signoff({ approvedSha: OTHER_SHA, run: mock.run }), /does not match/i);
    assert.throws(() => signoff({ approvedSha: SHA, run: mock.run }), /No readable verification receipt/i);
    writeReceipt(mock.path, { planId: 'stale' });
    assert.throws(() => signoff({ approvedSha: SHA, run: mock.run }), /receipt is stale/i);
});

test('signoff requires dedicated token, extension, and matching open PR', () => {
    const noToken = runner(); writeReceipt(noToken.path);
    assert.throws(() => signoff({ approvedSha: SHA, environment: { GH_TOKEN: 'ambient' }, run: noToken.run }), /GH_SIGNOFF_TOKEN/);
    const noExtension = runner({ extension: '' }); writeReceipt(noExtension.path);
    assert.throws(() => signoff({ approvedSha: SHA, environment: SIGNOFF_ENV, run: noExtension.run }), /extension is not installed/i);
    const closed = runner({ pullRequest: { headRefOid: SHA, state: 'CLOSED' } }); writeReceipt(closed.path);
    assert.throws(() => signoff({ approvedSha: SHA, environment: SIGNOFF_ENV, run: closed.run }), /open pull request/i);
    const mismatch = runner({ pullRequest: { headRefOid: OTHER_SHA, state: 'OPEN' } }); writeReceipt(mismatch.path);
    assert.throws(() => signoff({ approvedSha: SHA, environment: SIGNOFF_ENV, run: mismatch.run }), /head does not match/i);
});

test('signoff rechecks state, overrides ambient token, and never forces', () => {
    const changed = runner({ heads: [SHA, OTHER_SHA] }); writeReceipt(changed.path);
    assert.throws(() => signoff({ approvedSha: SHA, environment: SIGNOFF_ENV, run: changed.run }), /HEAD changed/i);
    assert.equal(changed.calls.some(({ command, args }) => command === 'gh' && args[0] === 'signoff'), false);
    const mock = runner(); writeReceipt(mock.path);
    signoff({ approvedSha: SHA, environment: SIGNOFF_ENV, run: mock.run });
    const githubCalls = mock.calls.filter(({ command }) => command === 'gh');
    assert.deepEqual(githubCalls.find(({ args }) => args[0] === 'signoff').args, ['signoff', '--commit', SHA]);
    assert.ok(githubCalls.every(({ options }) => options.env.GH_TOKEN === 'signoff' && options.env.GH_SIGNOFF_TOKEN === undefined));
});
