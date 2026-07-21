import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

const repo = path.resolve(import.meta.dirname, '..');
const wasmRoot = process.env.DSAP_PHP_WASM_DIR || 'C:/tmp/dsap-php-wasm/node_modules';
const { PHP } = await import(pathToFileURL(path.join(wasmRoot, '@php-wasm/universal/index.js')).href);
const { loadNodeRuntime } = await import(pathToFileURL(path.join(wasmRoot, '@php-wasm/node/index.js')).href);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: process.pid } }));

const files = [
    'tests/bootstrap.php',
    'tests/generate-article-smoke.php',
    'includes/AiClientInterface.php',
    'includes/Settings.php',
    'includes/MockAiClient.php',
    'includes/QualityGate.php',
    'includes/ArticleVisuals.php',
    'includes/ArticleImageGenerator.php',
];

php.mkdir('/workspace');
php.mkdir('/workspace/tests');
php.mkdir('/workspace/includes');
for (const relative of files) {
    php.writeFile('/workspace/' + relative.replaceAll('\\', '/'), fs.readFileSync(path.join(repo, relative)));
}

const response = await php.runStream({
    code: "<?php require '/workspace/tests/generate-article-smoke.php';",
});
const stdout = await response.stdoutText;
const stderr = await response.stderrText;
if (stderr.trim()) {
    process.stderr.write(stderr);
}
const report = JSON.parse(stdout);
const css = fs.readFileSync(path.join(repo, 'assets/frontend.css'), 'utf8');
const html = `<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${report.title}</title><style>body{margin:0;background:#f4f6f8;color:#1f2933;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.page{max-width:820px;margin:0 auto;background:#fff;padding:40px 56px;box-sizing:border-box}.page>h1{font-size:32px;line-height:1.4;margin:0 0 24px}.page h2{font-size:24px;line-height:1.5;margin-top:42px}.page p,.page li{font-size:17px;line-height:1.9}.page table{border-collapse:collapse;width:100%;margin:24px 0}.page th,.page td{border:1px solid #ccd2d8;padding:12px;text-align:left}@media(max-width:640px){.page{padding:24px 18px}.page>h1{font-size:27px}}${css}</style></head><body><article class="page dsap-article-content"><h1>${report.title}</h1>${report.content_html}</article></body></html>`;
const outputDir = path.join(repo, 'artifacts');
fs.mkdirSync(outputDir, { recursive: true });
fs.writeFileSync(path.join(outputDir, 'generated-article.html'), html);
fs.writeFileSync(path.join(outputDir, 'generated-article-report.json'), JSON.stringify(report, null, 2));
process.stdout.write(JSON.stringify({
    title: report.title,
    diagnostics: report.diagnostics,
    decision: report.decision,
    html: path.join(outputDir, 'generated-article.html'),
}, null, 2));
process.exit(0);
