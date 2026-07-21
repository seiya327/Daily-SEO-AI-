import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { pathToFileURL } from 'node:url';

const input = process.argv[2];
if (!input || !fs.existsSync(input)) {
    throw new Error('Usage: node tests/audit-pasted-article-wasm.mjs <pasted-text.txt>');
}

const source = fs.readFileSync(input, 'utf8');
const lines = source.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
const headings = new Set();
for (const line of lines) {
    const match = line.match(/^\[([^\]]+)]\(https?:\/\/[^)]+#section-\d+\)$/);
    if (match && !match[1].includes('参考資料')) {
        headings.add(match[1]);
    }
}

const body = [];
for (const line of lines.slice(3)) {
    if (line === 'Contents' || /^\[.+]\(https?:\/\/[^)]+#section-\d+\)$/.test(line)) {
        continue;
    }
    body.push(headings.has(line) ? `<h2>${escapeHtml(line)}</h2>` : `<p>${escapeHtml(line)}</p>`);
}

const payload = {
    research: {
        primary_keyword: 'UR-U スキマ時間 学習',
        entities: ['UR-U'],
        facts: [
            { claim: 'UR-Uは講義動画と講義LIVEを案内している。', confidence: 'high' },
            { claim: 'UR-Uは無料会員登録後のアプリ利用を案内している。', confidence: 'high' },
            { claim: 'UR-Uの規約には無料体験後の自動切替に関する記載がある。', confidence: 'high' },
            { claim: 'UR-Uの規約には利用期間と解約条件の記載がある。', confidence: 'medium' },
            { claim: 'UR-Uはプロフィール設定に関する案内を掲載している。', confidence: 'medium' },
            { claim: 'UR-UのアプリはApp Storeに掲載されている。', confidence: 'medium' },
        ],
        sources: [{}, {}, {}, {}, {}],
    },
    funnel: {
        article_type: 'cv',
        reader_stage: 'product_aware',
        conversion_bridge: '契約条件を理解した読者だけを公式情報へ案内する。',
    },
    article: {
        title: lines[0] || '',
        focus_keyword: 'UR-U スキマ時間 学習',
        answer_summary: 'UR-Uの利用条件と生活への適合性を確認し、無料体験へ進むか判断します。',
        content_html: body.join(''),
        cta_lead: '条件を確認できた場合だけ、公式ページで最新情報を確認してください。',
        cta_anchor: 'UR-U スキマ時間 学習',
        image_search_query: 'adult studying online course laptop',
        image_alt: '自宅でオンライン講座を受講している社会人',
        source_indexes: [0, 1, 2, 3, 4],
        internal_link_post_ids: [],
    },
    internal_link_candidates: [],
};

const repo = path.resolve(import.meta.dirname, '..');
const wasmRoot = process.env.DSAP_PHP_WASM_DIR || 'C:/tmp/dsap-php-wasm/node_modules';
const { PHP } = await import(pathToFileURL(path.join(wasmRoot, '@php-wasm/universal/index.js')).href);
const { loadNodeRuntime } = await import(pathToFileURL(path.join(wasmRoot, '@php-wasm/node/index.js')).href);
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: process.pid } }));
php.mkdir('/workspace');
php.mkdir('/workspace/tests');
php.mkdir('/workspace/includes');
for (const relative of ['tests/bootstrap.php', 'includes/Settings.php', 'includes/QualityGate.php']) {
    php.writeFile('/workspace/' + relative, fs.readFileSync(path.join(repo, relative)));
}
const encoded = Buffer.from(JSON.stringify(payload), 'utf8').toString('base64');
const response = await php.runStream({
    code: `<?php require '/workspace/tests/bootstrap.php'; require '/workspace/includes/Settings.php'; require '/workspace/includes/QualityGate.php'; $GLOBALS['dsap_test_options'][\\DSAP\\Settings::OPTION] = array_merge(\\DSAP\\Settings::defaults(), ['article_quality' => 'high', 'post_status' => 'publish']); $payload = json_decode(base64_decode('${encoded}'), true); $diagnostics = \\DSAP\\QualityGate::diagnostics($payload); $payload['quality_diagnostics'] = $diagnostics; $payload['audit'] = ['overall_score'=>94,'intent_coverage'=>94,'factual_support'=>94,'clarity'=>94,'originality'=>94,'seo_quality'=>94,'information_gain'=>94,'conversion_quality'=>94,'reader_trust'=>94,'internal_link_quality'=>94,'product_specificity'=>94,'intent_plausibility'=>94,'non_redundancy'=>94,'generic_or_invented_frameworks'=>[],'unsupported_claims'=>[],'critical_issues'=>[],'ymyl'=>false]; echo wp_json_encode(['diagnostics'=>$diagnostics,'decision'=>\\DSAP\\QualityGate::decision($payload, \\DSAP\\Settings::get())], JSON_PRETTY_PRINT);`,
});
process.stdout.write(await response.stdoutText);
process.stderr.write(await response.stderrText);
process.exit(0);

function escapeHtml(value) {
    return value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
}
