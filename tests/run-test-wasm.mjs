import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

const repo = path.resolve(import.meta.dirname, '..');
const target = (process.argv[2] || '').replaceAll('\\', '/');
const lint = target === '--lint';
if (!lint && (!target.startsWith('tests/') || !target.endsWith('.php'))) {
    throw new Error('Pass a PHP test path under tests/.');
}

const wasmRoot = process.env.DSAP_PHP_WASM_DIR || 'C:/tmp/dsap-php-wasm/node_modules';
const { PHP } = await import(pathToFileURL(path.join(wasmRoot, '@php-wasm/universal/index.js')).href);
const { loadNodeRuntime } = await import(pathToFileURL(path.join(wasmRoot, '@php-wasm/node/index.js')).href);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: process.pid } }));

const collectPhp = (directory) => fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
        return collectPhp(absolute);
    }
    return entry.name.endsWith('.php') ? [absolute] : [];
});
const files = [
    path.join(repo, 'daily-seo-ai-publisher.php'),
    path.join(repo, 'uninstall.php'),
    ...collectPhp(path.join(repo, 'includes')),
    ...collectPhp(path.join(repo, 'tests')),
];

const made = new Set(['/']);
const ensureDirectory = (directory) => {
    if (made.has(directory)) {
        return;
    }
    ensureDirectory(path.posix.dirname(directory));
    php.mkdir(directory);
    made.add(directory);
};

for (const absolute of files) {
    const relative = path.relative(repo, absolute).replaceAll('\\', '/');
    const destination = '/workspace/' + relative;
    ensureDirectory(path.posix.dirname(destination));
    php.writeFile(destination, fs.readFileSync(absolute));
}
php.writeFile('/workspace/contracts.json', fs.readFileSync(path.join(repo, 'contracts.json')));

const workspaceFiles = files.map((absolute) => '/workspace/' + path.relative(repo, absolute).replaceAll('\\', '/'));
const code = lint
    ? `<?php
$errors = [];
foreach (${JSON.stringify(workspaceFiles)} as $file) {
    try {
        token_get_all(file_get_contents($file), TOKEN_PARSE);
    } catch (Throwable $error) {
        $errors[] = $file . ': ' . $error->getMessage();
    }
}
echo $errors === [] ? 'php_files=${workspaceFiles.length} parse_errors=0' : implode("\\n", $errors);
`
    : `<?php require '/workspace/${target}';`;
const response = await php.runStream({ code });
const stdout = await response.stdoutText;
const stderr = await response.stderrText;
if (stderr.trim()) {
    process.stderr.write(stderr);
}
process.stdout.write(stdout);
process.exit(lint && !stdout.includes('parse_errors=0') ? 1 : 0);
