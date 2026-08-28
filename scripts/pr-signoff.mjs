#!/usr/bin/env node

import { signoff } from './pr-workflow.mjs';

const index = process.argv.indexOf('--approved-sha');
const approvedSha = index === -1 ? undefined : process.argv[index + 1];

try {
    const result = signoff({ approvedSha });
    process.stdout.write(`Signed off ${result.sha}\n`);
} catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
}
