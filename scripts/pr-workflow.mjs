import { createHash } from 'node:crypto';
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';

const RECEIPT_VERSION = 1;
const SHA_PATTERN = /^[0-9a-f]{40}$/;
const VERSION_PATTERN = /^\d+\.\d+(?:\.\d+)?(?:[-+][0-9A-Za-z.-]+)?$/;
const BASE_REF = 'origin/main';
const REMOTE_BASE_REF = 'refs/heads/main';

export const CHECK_STEPS = Object.freeze([
    { command: 'composer', args: ['install', '--no-interaction', '--prefer-dist', '--no-progress'] },
    { command: 'composer', args: ['validate', '--strict'] },
    { command: 'composer', args: ['check-platform-reqs'] },
    { command: 'composer', args: ['audit', '--locked', '--no-interaction'] },
    { command: 'php', args: ['vendor/bin/pint', '--test'] },
    { command: 'php', args: ['vendor/bin/phpstan', 'analyse', '--memory-limit=1G'] },
    { command: 'bash', args: ['scripts/test-laravel-13-suite.sh'] },
    { command: 'bash', args: ['scripts/test-prefer-lowest.sh'] },
    { command: 'bash', args: ['scripts/test-consumer-install.sh', '12'] },
    { command: 'node', args: ['--test', 'tests/pr-workflow.test.mjs'] },
    { command: 'bash', args: ['-n', '.agents/setup', '.agents/resume', 'scripts/test-laravel-13-suite.sh', 'scripts/test-prefer-lowest.sh', 'scripts/test-consumer-install.sh'] },
    { command: 'git', args: ['diff', '--exit-code'] },
]);

export const CHECK_PLAN_ID = createHash('sha256').update(JSON.stringify({ steps: CHECK_STEPS })).digest('hex');

export function sanitizedEnvironment(environment = process.env, home) {
    return {
        ...(environment.PATH === undefined ? {} : { PATH: environment.PATH }),
        ...(home === undefined ? {} : { HOME: home }),
        ...(environment.HOME === undefined ? {} : { PR_CHECK_HOST_HOME: environment.HOME }),
        CI: '1', COMPOSER_NO_INTERACTION: '1',
    };
}

function localCommand(command, args) {
    const herdPhp = join(process.env.HOME ?? '', 'Library/Application Support/Herd/bin/php85');
    const herdComposer = join(process.env.HOME ?? '', 'Library/Application Support/Herd/bin/composer');
    if (process.platform !== 'darwin') return { command, args };
    if (command === 'php') return { command: herdPhp, args };
    if (command === 'composer') return { command: herdPhp, args: [herdComposer, ...args] };
    return { command, args };
}

export function defaultRunner(command, args, options = {}) {
    const resolved = localCommand(command, args);
    return spawnSync(resolved.command, resolved.args, { encoding: 'utf8', ...options });
}

function output(result, label) {
    if (result.error !== undefined) throw new Error(`${label} could not start: ${result.error.message}`);
    if (result.status !== 0) {
        const detail = String(result.stderr ?? result.stdout ?? '').trim();
        throw new Error(`${label} failed${detail === '' ? '' : `: ${detail}`}`);
    }
    return String(result.stdout ?? '').trim();
}
function version(run, environment, command, args, pattern, label) {
    const match = output(run(command, args, { encoding: 'utf8', env: environment }), label).match(pattern);
    if (match === null) throw new Error(`${label} returned an unrecognized version.`);
    return match[1];
}
function runtime(run, environment) {
    return { actual: {
        platform: process.platform, architecture: process.arch,
        php: version(run, environment, 'php', ['-r', 'echo PHP_VERSION;'], /^(\S+)$/, 'php version'),
        composer: version(run, environment, 'composer', ['--version', '--no-ansi'], /^Composer version (\S+)/, 'Composer version'),
        node: version(run, environment, 'node', ['--version'], /^v(\S+)$/, 'Node version'),
    } };
}
function git(run, args) { return output(run('git', args, { encoding: 'utf8' }), `git ${args.join(' ')}`); }
function head(run) {
    const sha = git(run, ['rev-parse', 'HEAD']);
    if (!SHA_PATTERN.test(sha)) throw new Error('Git HEAD did not resolve to a full lowercase SHA.');
    return sha;
}
function requireClean(run) {
    if (git(run, ['status', '--porcelain=v1', '--untracked-files=all']) !== '') throw new Error('The worktree must be clean before verification or signoff.');
}
function requireFreshBase(run) {
    const local = git(run, ['rev-parse', '--verify', BASE_REF]);
    const [remote, ref, ...extra] = git(run, ['ls-remote', '--exit-code', 'origin', REMOTE_BASE_REF]).split(/\s+/);
    if (!SHA_PATTERN.test(local) || !SHA_PATTERN.test(remote ?? '') || ref !== REMOTE_BASE_REF || extra.length > 0) throw new Error(`Git could not resolve ${BASE_REF} and remote main to exact SHAs.`);
    if (local !== remote) throw new Error(`${BASE_REF} is stale; fetch origin before verification.`);
}
export function receiptPathFor(sha, { run = defaultRunner } = {}) {
    return git(run, ['rev-parse', '--git-path', `mail-mirror/pr-check/${sha}.json`]);
}
function writeReceipt(path, receipt) {
    mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
    const temporary = `${path}.${process.pid}.tmp`;
    try {
        writeFileSync(temporary, `${JSON.stringify(receipt, null, 2)}\n`, { encoding: 'utf8', flag: 'wx', mode: 0o600 });
        renameSync(temporary, path); chmodSync(path, 0o600);
    } finally { rmSync(temporary, { force: true }); }
}
export function runCheck({ environment = process.env, now = () => new Date(), run = defaultRunner } = {}) {
    const sha = head(run); requireClean(run); requireFreshBase(run);
    const isolatedHome = mkdtempSync(join(tmpdir(), 'mail-mirror-pr-check-home-'));
    const checkEnvironment = sanitizedEnvironment(environment, isolatedHome);
    let actualRuntime;
    try {
        for (const step of CHECK_STEPS) output(run(step.command, step.args, { env: checkEnvironment, stdio: 'inherit' }), `${step.command} ${step.args.join(' ')}`);
        actualRuntime = runtime(run, checkEnvironment);
    } finally { rmSync(isolatedHome, { force: true, recursive: true }); }
    requireClean(run);
    if (head(run) !== sha) throw new Error('Git HEAD changed while verification was running.');
    const receipt = { version: RECEIPT_VERSION, sha, planId: CHECK_PLAN_ID, success: true, completedAt: now().toISOString(), runtime: actualRuntime };
    const path = receiptPathFor(sha, { run }); writeReceipt(path, receipt); return { path, receipt };
}
function readReceipt(path) {
    try { return JSON.parse(readFileSync(path, 'utf8')); }
    catch { throw new Error(`No readable verification receipt exists for the current SHA: ${path}`); }
}
function requireValidReceipt(receipt, sha) {
    const actual = receipt.runtime?.actual;
    if (receipt.version !== RECEIPT_VERSION || receipt.sha !== sha || receipt.planId !== CHECK_PLAN_ID || receipt.success !== true
        || typeof receipt.completedAt !== 'string' || typeof actual?.platform !== 'string' || typeof actual?.architecture !== 'string'
        || !VERSION_PATTERN.test(actual?.php ?? '') || !VERSION_PATTERN.test(actual?.composer ?? '') || !VERSION_PATTERN.test(actual?.node ?? '')) {
        throw new Error('The verification receipt is stale or does not match the current plan and SHA.');
    }
}
function signoffEnvironment(environment) {
    const { GH_SIGNOFF_TOKEN: token, ...rest } = environment;
    if (typeof token !== 'string' || token.trim() === '') throw new Error('GH_SIGNOFF_TOKEN is required for signoff.');
    return { ...rest, GH_TOKEN: token };
}
function requireExtension(run, environment) {
    const extensions = output(run('gh', ['extension', 'list'], { encoding: 'utf8', env: environment }), 'gh extension list');
    if (!/(^|\s)basecamp\/gh-signoff(\s|$)/m.test(extensions)) throw new Error('The basecamp/gh-signoff extension is not installed.');
}
function pullRequest(run, environment) {
    const response = output(run('gh', ['pr', 'view', '--json', 'headRefOid,state'], { encoding: 'utf8', env: environment }), 'gh pr view');
    try { return JSON.parse(response); } catch { throw new Error('GitHub returned an invalid pull-request response.'); }
}
export function signoff({ approvedSha, environment = process.env, run = defaultRunner } = {}) {
    if (!SHA_PATTERN.test(approvedSha ?? '')) throw new Error('--approved-sha must be a full 40-character lowercase Git SHA.');
    requireClean(run); const sha = head(run);
    if (sha !== approvedSha) throw new Error('The explicitly approved SHA does not match the current Git HEAD.');
    const path = receiptPathFor(sha, { run }); requireValidReceipt(readReceipt(path), sha);
    const githubEnvironment = signoffEnvironment(environment); requireExtension(run, githubEnvironment);
    const pr = pullRequest(run, githubEnvironment);
    if (pr.state !== 'OPEN') throw new Error('The current branch must have an open pull request.');
    if (pr.headRefOid !== sha) throw new Error('The open pull request head does not match the approved SHA.');
    requireClean(run);
    if (head(run) !== sha) throw new Error('Git HEAD changed while signoff eligibility was being checked.');
    output(run('gh', ['signoff', '--commit', sha], { env: githubEnvironment, stdio: 'inherit' }), `gh signoff --commit ${sha}`);
    return { path, sha };
}
