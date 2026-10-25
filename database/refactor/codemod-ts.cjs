/**
 * TypeScript / React codemod of the terminology refactor (TypeScript compiler API): renames
 * identifiers and string literals by terminology.json, leaving JSX text and comments alone.
 * Module specifiers ("@/components/comben/MoneyInput") use the same rules as the file moves.
 *
 *   node database/refactor/codemod-ts.cjs <ts-module-dir> <file>...
 */
const fs = require('fs');
const path = require('path');
const ts = require(path.join(process.argv[2], 'typescript'));
const d = JSON.parse(fs.readFileSync(process.env.TERMINOLOGY_JSON || path.join(__dirname, 'terminology.json'), 'utf8'));
// Null-prototype copies: a plain object would answer T.toString, T.constructor … with Object.prototype members.
const own = (o) => Object.assign(Object.create(null), o);
const T = own(d.tokens), TB = own(d.tables), C = own(d.classes), NS = own(d.namespaces), P = own(d.pages), F = own(d.folders);

const word = (w) => TB[w] ?? T[w] ?? w;
const cls = (w) => C[w] ?? w;
const nsOrClass = (w) => C[w] ?? NS[w] ?? w;
const hyphenateUrl = (u) => u.replace(/(?<=\/|^)([a-z0-9_]+)(?=\/|$|\?|#)/g, (m) => m.replace(/_/g, '-'));

function piece(p, hasPathDelim) {
    if (/^[A-Z]/.test(p)) return hasPathDelim ? nsOrClass(p) : p;
    return word(p);
}

function str(s, forceUrl = false) {
    if (s === '') return s;
    if (P[s]) return P[s];
    if (/\s/.test(s)) return s; // UI text
    if (T[s] !== undefined || TB[s] !== undefined) return word(s);
    const isUrl = forceUrl || s.startsWith('/');
    const hasPathDelim = /[/\\]/.test(s);
    const out = s.replace(/[A-Za-z_][A-Za-z0-9_-]*/g, (seg) => {
        if (T[seg] !== undefined || TB[seg] !== undefined) return word(seg);
        if (seg.includes('-')) return seg.split('-').map((p) => piece(p, hasPathDelim)).join('-');
        return piece(seg, hasPathDelim);
    });
    return isUrl ? hyphenateUrl(out) : out;
}

/** A path segment of a module specifier or a file under resources/js (same rule as the moves). */
function fsPiece(seg) {
    const m = seg.match(/^([^.]+)(\..*)?$/);
    if (!m) return seg;
    const [, base, ext = ''] = m;
    if (/^[A-Z]/.test(base)) return nsOrClass(base) + ext;
    return (F[base] ?? base) + ext;
}
function moduleSpecifier(s) {
    if (!s.startsWith('@/') && !s.startsWith('.')) return s;
    return s.split('/').map((seg) => (seg === '@' || seg === '.' || seg === '..' ? seg : fsPiece(seg))).join('/');
}

const report = [];
for (const file of process.argv.slice(3)) {
    const src = fs.readFileSync(file, 'utf8');
    const sf = ts.createSourceFile(file, src, ts.ScriptTarget.Latest, true, file.endsWith('x') ? ts.ScriptKind.TSX : ts.ScriptKind.TS);
    const edits = [];
    const visit = (node, urlTemplate) => {
        if (ts.isIdentifier(node) || ts.isPrivateIdentifier(node)) {
            const t = node.text;
            const n = /^[A-Z]/.test(t) ? cls(t) : word(t);
            if (n !== t) edits.push([node.getStart(sf), node.end, n]);
            return;
        }
        if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
            const parent = node.parent;
            const isModule = parent && (ts.isImportDeclaration(parent) || ts.isExportDeclaration(parent) || (ts.isCallExpression(parent) && parent.expression.kind === ts.SyntaxKind.ImportKeyword));
            const raw = node.text;
            const n = isModule ? moduleSpecifier(raw) : str(raw);
            if (n !== raw) {
                const start = node.getStart(sf);
                const quote = src[start];
                edits.push([start, node.end, quote + n.replace(new RegExp('\\\\|' + (quote === '`' ? '`' : quote), 'g'), (c) => '\\' + c) + quote]);
            }
            return;
        }
        if (ts.isTemplateExpression(node)) {
            const isUrl = node.head.text.startsWith('/');
            const parts = [node.head, ...node.templateSpans.map((s) => s.literal)];
            for (const part of parts) {
                const start = part.getStart(sf);
                const rawText = part.rawText ?? part.text;
                const n = str(rawText, isUrl);
                if (n !== rawText) {
                    const openLen = part.kind === ts.SyntaxKind.TemplateHead ? 1 : 1; // "`" or "}"
                    const closeLen = part.kind === ts.SyntaxKind.TemplateTail ? 1 : 2; // "`" or "${"
                    edits.push([start + openLen, part.end - closeLen, n]);
                }
            }
            node.templateSpans.forEach((s) => visit(s.expression));
            return;
        }
        if (ts.isJsxText(node)) return;
        ts.forEachChild(node, (c) => visit(c));
    };
    visit(sf);
    if (!edits.length) continue;
    edits.sort((a, b) => b[0] - a[0]);
    let out = src;
    for (const [s, e, n] of edits) out = out.slice(0, s) + n + out.slice(e);
    fs.writeFileSync(file, out);
}
module.exports = { fsPiece };
