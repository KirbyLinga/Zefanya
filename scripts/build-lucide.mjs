// Builds public/js/lucide.min.js with only the icons this app names, because the full
// library is 423 KB and loads on every page. Run by `npm run build`.
//
// An icon this scan cannot see (a name built at runtime, or one added since the last
// build) still renders: the file loads lucide.full.min.js when an icon is left unrendered.
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { join } from 'node:path';
import { build } from 'vite';

const { icons } = createRequire(import.meta.url)('lucide');
const toPascalCase = (name) => name.replace(/(\w)(\w*)(_|-|\s*)/g, (m, first, rest) => first.toUpperCase() + rest.toLowerCase());

const used = new Set();
function scan(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            scan(path);
        } else if (/\.(php|js)$/.test(entry.name)) {
            for (const [, name] of readFileSync(path, 'utf8').matchAll(/['"`]([a-z][a-z0-9-]*)['"`]/g)) {
                if (icons[toPascalCase(name)]) used.add(toPascalCase(name));
            }
        }
    }
}
['resources/views', 'resources/js', 'app', 'config', 'database/migrations'].forEach(scan);

const names = [...used].sort();
const entry = 'node_modules/.cache/lucide-subset.js';
mkdirSync('node_modules/.cache', { recursive: true });
writeFileSync(entry, `
import { createIcons, ${names.join(', ')} } from 'lucide';

const icons = { ${names.join(', ')} };
const fullLibrary = document.currentScript.src.replace('lucide.min.js', 'lucide.full.min.js');
let loadingFullLibrary = false;

window.lucide = {
    icons,
    createIcons(options = {}) {
        createIcons({ ...options, icons: { ...icons, ...options.icons } });
        if (loadingFullLibrary || !document.querySelector('i[data-lucide]')) return;

        loadingFullLibrary = true;
        const script = document.createElement('script');
        script.src = fullLibrary;
        script.onload = () => window.lucide.createIcons(options);
        document.head.append(script);
    },
};
`);

await build({
    configFile: false,
    logLevel: 'warn',
    publicDir: false,
    build: {
        outDir: 'public/js',
        emptyOutDir: false,
        lib: { entry, formats: ['iife'], name: 'lucideSubset', fileName: () => 'lucide.min.js' },
    },
});

console.log(`lucide.min.js: ${names.length} of ${Object.keys(icons).length} icons`);
