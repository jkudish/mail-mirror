#!/usr/bin/env node

import { runCheck } from './pr-workflow.mjs';

try {
    const { path, receipt } = runCheck();
    process.stdout.write(`Verified ${receipt.sha}\nReceipt: ${path}\n`);
} catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
}
