/*
 * Run every browser harness in tests/browser and sum their numbers.
 * Each harness is its own process, so one that throws cannot take the rest
 * down with it, and the exit code is non-zero if any check failed.
 */
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const harnesses = fs.readdirSync(__dirname)
	.filter(f => /^harness-.*\.js$/.test(f))
	.sort();

let failed = 0;
for (const file of harnesses) {
	const res = spawnSync(process.execPath, [path.join(__dirname, file)],
		{ stdio: 'inherit' });
	if (res.status !== 0) failed++;
	console.log('');
}
console.log(harnesses.length + ' harness file(s), ' + failed + ' with failures');
process.exit(failed ? 1 : 0);
